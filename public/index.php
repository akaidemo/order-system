<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
]);
date_default_timezone_set('Asia/Taipei');

$dataDir = rtrim(getenv('DATA_DIR') ?: '/data', '/\\');
$ordersFile = $dataDir . '/orders.txt';
$usersFile = $dataDir . '/users.json';
$historyDir = $dataDir . '/history';
$menuFile = $dataDir . '/menu';
$settingsFile = $dataDir . '/settings.json';
$menuLibraryDir = $dataDir . '/menu_library';
$menuLibraryFile = $dataDir . '/menu_library.json';
$balanceAuditFile = $dataDir . '/balance_audit.jsonl';
$appPassword = getenv('APP_PASSWORD') ?: '';
$adminUser = getenv('ADMIN_USER') ?: 'admin';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';

foreach ([$dataDir, $historyDir, $menuLibraryDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        http_response_code(500);
        exit('Unable to initialize data directory.');
    }
}
if (!is_file($ordersFile)) {
    file_put_contents($ordersFile, '');
}
if (!is_file($usersFile)) {
    file_put_contents($usersFile, json_encode(['管理員' => 0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
if (!is_file($balanceAuditFile)) {
    file_put_contents($balanceAuditFile, '');
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrfValid(string $token): bool
{
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function loadUsers(string $file): array
{
    $users = json_decode((string)file_get_contents($file), true);
    return is_array($users) ? $users : [];
}

function saveUsers(string $file, array $users): void
{
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $file);
}

function appendBalanceAudit(string $file, array $entry): void
{
    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'actor' => !empty($_SESSION['is_admin']) ? 'admin' : 'system',
    ] + $entry;
    file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function loadBalanceAudit(string $file, int $limit = 50): array
{
    if (!is_file($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -$limit);
    $items = [];
    foreach (array_reverse($lines) as $line) {
        $item = json_decode($line, true);
        if (is_array($item)) {
            $items[] = $item;
        }
    }
    return $items;
}

function loadSettings(string $file): array
{
    if (!is_file($file)) {
        return ['order_deadline' => '', 'organizer' => ''];
    }
    $settings = json_decode((string)file_get_contents($file), true);
    return is_array($settings) ? $settings : ['order_deadline' => '', 'organizer' => ''];
}

function saveSettings(string $file, array $settings): void
{
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $file);
}

function loadMenuLibrary(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $items = json_decode((string)file_get_contents($file), true);
    return is_array($items) ? $items : [];
}

function saveMenuLibrary(string $file, array $items): void
{
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $file);
}

function menuLabel(string $label, string $fallback): string
{
    $label = trim($label);
    if ($label === '') {
        $label = trim(pathinfo($fallback, PATHINFO_FILENAME));
    }
    if ($label === '') {
        $label = '未命名菜單';
    }
    return mb_substr($label, 0, 60);
}

function menuExtension(string $mime): string
{
    return match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };
}

function deadlineTimestamp(array $settings): ?int
{
    $value = trim((string)($settings['order_deadline'] ?? ''));
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone('Asia/Taipei'));
    return $date ? $date->getTimestamp() : null;
}

function loadOrders(string $file): array
{
    $orders = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $index => $line) {
        $order = json_decode($line, true);
        if (is_array($order)) {
            if (empty($order['id'])) {
                $order['id'] = substr(hash('sha256', $line . ':' . $index), 0, 24);
            }
            $orders[] = $order;
        }
    }
    return $orders;
}

function saveOrders(string $file, array $orders): void
{
    $lines = array_map(
        static fn(array $order): string => json_encode($order, JSON_UNESCAPED_UNICODE),
        $orders
    );
    file_put_contents($file, implode(PHP_EOL, $lines) . ($lines ? PHP_EOL : ''), LOCK_EX);
}

function settleOrders(
    string $ordersFile,
    string $usersFile,
    string $historyDir,
    string $balanceAuditFile,
    array $orders,
    array $users
): ?string {
    if (!$orders) {
        return null;
    }
    $spentByUser = [];
    foreach ($orders as $order) {
        $name = (string)($order['user'] ?? '');
        if (array_key_exists($name, $users)) {
            $spentByUser[$name] = ($spentByUser[$name] ?? 0) + (int)($order['price'] ?? 0);
        }
    }
    foreach ($spentByUser as $name => $spent) {
        $before = (int)$users[$name];
        $after = $before - $spent;
        $users[$name] = $after;
        appendBalanceAudit($balanceAuditFile, [
            'action' => 'settle_order',
            'user' => $name,
            'before_balance' => $before,
            'after_balance' => $after,
            'amount' => -$spent,
            'note' => '結單扣款',
        ]);
    }
    saveUsers($usersFile, $users);
    $timestamp = date('Ymd_His');
    $historyFile = $historyDir . '/history_' . $timestamp . '.txt';
    rename($ordersFile, $historyFile);
    file_put_contents($ordersFile, '');
    $csvName = 'report_' . $timestamp . '.csv';
    $csv = fopen($historyDir . '/' . $csvName, 'wb');
    fwrite($csv, "\xEF\xBB\xBF");
    fputcsv($csv, ['日期', '時間', '使用者', '品項', '價格', '今日心情']);
    foreach ($orders as $order) {
        fputcsv($csv, [$order['date'], $order['time'], $order['user'], $order['item'], $order['price'], $order['mood'] ?? '無']);
    }
    fclose($csv);
    return $csvName;
}

function requireAdmin(): void
{
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        exit('Forbidden');
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

$authError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['app_login'])) {
    if ($appPassword === '') {
        $authError = '伺服器尚未設定 APP_PASSWORD。';
    } elseif (hash_equals($appPassword, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['app_authenticated'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: /');
        exit;
    } else {
        $authError = '系統密碼錯誤。';
    }
}

if (empty($_SESSION['app_authenticated'])) {
    ?>
    <!doctype html>
    <html lang="zh-Hant">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>威杰飲料管理系統</title>
        <style>
            body{margin:0;background:#0f172a;color:#f8fafc;font-family:system-ui;display:grid;place-items:center;min-height:100vh}
            form{width:min(360px,calc(100% - 48px));background:#1e293b;padding:28px;border-radius:18px}
            input,button{box-sizing:border-box;width:100%;padding:13px;margin-top:12px;border-radius:10px;border:1px solid #475569}
            input{background:#0f172a;color:#fff}button{background:#818cf8;color:#fff;font-weight:700;cursor:pointer}
            .error{color:#fca5a5}
        </style>
    </head>
    <body>
    <form method="post">
        <h1>威杰飲料管理系統</h1>
        <p>請輸入公司共用密碼，準備開喝。</p>
        <?php if ($authError): ?><p class="error"><?= h($authError) ?></p><?php endif; ?>
        <input type="password" name="password" autocomplete="current-password" required autofocus>
        <button name="app_login" value="1">進入系統</button>
    </form>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['menu_image'])) {
    if (!is_file($menuFile)) {
        http_response_code(404);
        exit;
    }
    $menuMime = mime_content_type($menuFile);
    if (!in_array($menuMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(415);
        exit;
    }
    header('Content-Type: ' . $menuMime);
    header('Cache-Control: no-store');
    readfile($menuFile);
    exit;
}

if (isset($_GET['download_csv'])) {
    $file = basename((string)$_GET['download_csv']);
    $path = $historyDir . '/' . $file;
    $authorized = !empty($_SESSION['is_admin'])
        || (!empty($_SESSION['download_csv_once']) && hash_equals((string)$_SESSION['download_csv_once'], $file));
    if (!$authorized || !preg_match('/^report_\d{8}_\d{6}\.csv$/', $file) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    unset($_SESSION['download_csv_once']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path);
    exit;
}

$users = loadUsers($usersFile);
$orders = loadOrders($ordersFile);
$settings = loadSettings($settingsFile);
$menuLibrary = loadMenuLibrary($menuLibraryFile);
$deadline = deadlineTimestamp($settings);
$ordersClosed = $deadline !== null && time() >= $deadline;
$message = '';
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    if (
        $adminPassword !== ''
        && hash_equals($adminUser, (string)($_POST['username'] ?? ''))
        && hash_equals($adminPassword, (string)($_POST['password'] ?? ''))
    ) {
        session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        header('Location: /');
        exit;
    }
    $loginError = '管理員帳號或密碼錯誤。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload) || !csrfValid((string)($payload['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $action = (string)($payload['action'] ?? '');
    $user = trim((string)($payload['user'] ?? ''));
    if ($ordersClosed) {
        http_response_code(423);
        echo json_encode(['success' => false, 'error' => '訂單已截止'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!array_key_exists($user, $users)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Unknown user']);
        exit;
    }

    if ($action === 'create') {
        $item = trim((string)($payload['item'] ?? ''));
        $price = filter_var($payload['price'] ?? null, FILTER_VALIDATE_INT);
        $mood = trim((string)($payload['mood'] ?? '')) ?: '無';
        if ($item === '' || mb_strlen($item) > 100 || $price === false || $price <= 0 || $price > 100000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid order']);
            exit;
        }
        $order = [
            'id' => bin2hex(random_bytes(12)),
            'user' => $user,
            'item' => $item,
            'price' => $price,
            'mood' => mb_substr($mood, 0, 100),
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
        ];
        file_put_contents($ordersFile, json_encode($order, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        echo json_encode(['success' => true]);
        exit;
    }

    $id = (string)($payload['id'] ?? '');
    $index = array_search($id, array_column($orders, 'id'), true);
    if ($index === false || ($orders[$index]['user'] ?? '') !== $user || ($orders[$index]['date'] ?? '') !== date('Y-m-d')) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    if ($action === 'delete') {
        array_splice($orders, $index, 1);
        saveOrders($ordersFile, $orders);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'edit') {
        $item = trim((string)($payload['item'] ?? ''));
        $price = filter_var($payload['price'] ?? null, FILTER_VALIDATE_INT);
        if ($item === '' || mb_strlen($item) > 100 || $price === false || $price <= 0 || $price > 100000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Invalid order']);
            exit;
        }
        $orders[$index]['item'] = $item;
        $orders[$index]['price'] = $price;
        $orders[$index]['mood'] = mb_substr(trim((string)($payload['mood'] ?? '')) ?: '無', 0, 100);
        saveOrders($ordersFile, $orders);
        echo json_encode(['success' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    requireAdmin();
    if (!csrfValid((string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request');
    }
    $action = (string)$_POST['admin_action'];
    if ($action === 'add_user') {
        $name = trim((string)($_POST['user_name'] ?? ''));
        if ($name !== '' && mb_strlen($name) <= 50 && !array_key_exists($name, $users)) {
            $initialBalance = (int)($_POST['initial_balance'] ?? 0);
            $users[$name] = $initialBalance;
            saveUsers($usersFile, $users);
            appendBalanceAudit($balanceAuditFile, [
                'action' => 'add_user',
                'user' => $name,
                'before_balance' => null,
                'after_balance' => $initialBalance,
                'amount' => $initialBalance,
                'note' => '新增人員',
            ]);
        }
    } elseif ($action === 'delete_user') {
        $name = trim((string)($_POST['user_name'] ?? ''));
        if ($name !== '管理員' && array_key_exists($name, $users)) {
            $beforeBalance = (int)$users[$name];
            unset($users[$name]);
            saveUsers($usersFile, $users);
            appendBalanceAudit($balanceAuditFile, [
                'action' => 'delete_user',
                'user' => $name,
                'before_balance' => $beforeBalance,
                'after_balance' => null,
                'amount' => -$beforeBalance,
                'note' => '刪除人員',
            ]);
        }
    } elseif ($action === 'upload_menu' && isset($_FILES['menu_image'])) {
        $tmp = $_FILES['menu_image']['tmp_name'];
        $mime = is_uploaded_file($tmp) ? mime_content_type($tmp) : '';
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && (int)$_FILES['menu_image']['size'] <= 10 * 1024 * 1024) {
            move_uploaded_file($tmp, $menuFile);
        }
    } elseif ($action === 'set_balance') {
        $name = trim((string)($_POST['balance_user'] ?? ''));
        $targetBalance = filter_var($_POST['target_balance'] ?? null, FILTER_VALIDATE_INT);
        if (array_key_exists($name, $users) && $targetBalance !== false) {
            $pendingSpent = 0;
            foreach ($orders as $order) {
                if (($order['user'] ?? '') === $name) {
                    $pendingSpent += (int)($order['price'] ?? 0);
                }
            }
            $beforeStored = (int)$users[$name];
            $beforeVisible = $beforeStored - $pendingSpent;
            $afterStored = $targetBalance + $pendingSpent;
            $users[$name] = $targetBalance + $pendingSpent;
            saveUsers($usersFile, $users);
            appendBalanceAudit($balanceAuditFile, [
                'action' => 'manual_adjust',
                'user' => $name,
                'before_balance' => $beforeVisible,
                'after_balance' => $targetBalance,
                'amount' => $targetBalance - $beforeVisible,
                'pending_spent' => $pendingSpent,
                'before_stored_balance' => $beforeStored,
                'after_stored_balance' => $afterStored,
                'note' => trim((string)($_POST['balance_note'] ?? '')),
            ]);
        }
    }
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['public_action'])) {
    if (!csrfValid((string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid request');
    }
    $publicAction = (string)$_POST['public_action'];
    if ($publicAction === 'rename_menu') {
        $menuId = trim((string)($_POST['history_menu_id'] ?? ''));
        $newName = menuLabel((string)($_POST['history_menu_name'] ?? ''), '歷史菜單');
        if ($menuId !== '' && isset($menuLibrary[$menuId])) {
            $menuLibrary[$menuId]['name'] = $newName;
            saveMenuLibrary($menuLibraryFile, $menuLibrary);
        }
        header('Location: /');
        exit;
    }
    if ($publicAction === 'settle_group') {
        $organizer = (string)($settings['organizer'] ?? '');
        if ($organizer === '' || !array_key_exists($organizer, $users)) {
            http_response_code(422);
            exit('請先設定有效的開團人');
        }
        $csvName = settleOrders($ordersFile, $usersFile, $historyDir, $balanceAuditFile, $orders, $users);
        if ($csvName === null) {
            header('Location: /');
            exit;
        }
        $_SESSION['download_csv_once'] = $csvName;
        header('Location: /?download_csv=' . rawurlencode($csvName));
        exit;
    }
    if ($publicAction === 'update_group') {
        $organizer = trim((string)($_POST['organizer'] ?? ''));
        if (!array_key_exists($organizer, $users)) {
            http_response_code(422);
            exit('Invalid organizer');
        }
        $settings['organizer'] = $organizer;
        $deadlineDate = trim((string)($_POST['deadline_date'] ?? ''));
        $deadlineHour = trim((string)($_POST['deadline_hour'] ?? ''));
        $deadlineMinute = trim((string)($_POST['deadline_minute'] ?? ''));
        $deadlineValue = $deadlineDate === ''
            ? ''
            : $deadlineDate . 'T' . $deadlineHour . ':' . $deadlineMinute;
        if ($deadlineValue !== '') {
            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $deadlineValue, new DateTimeZone('Asia/Taipei'));
            if (
                !$date
                || $date->format('Y-m-d\TH:i') !== $deadlineValue
                || !preg_match('/^(?:[01]\d|2[0-3])$/', $deadlineHour)
                || !in_array($deadlineMinute, ['00', '10', '20', '30', '40', '50'], true)
            ) {
                http_response_code(422);
                exit('Invalid deadline');
            }
        }
        $settings['order_deadline'] = $deadlineValue;
        saveSettings($settingsFile, $settings);
    }
    if ($publicAction === 'update_menu') {
        $selectedHistoryMenu = trim((string)($_POST['history_menu_id'] ?? ''));
        if (
            $selectedHistoryMenu !== ''
            && isset($menuLibrary[$selectedHistoryMenu])
            && is_file($menuLibraryDir . '/' . ($menuLibrary[$selectedHistoryMenu]['file'] ?? ''))
        ) {
            copy($menuLibraryDir . '/' . $menuLibrary[$selectedHistoryMenu]['file'], $menuFile);
        }
        if (isset($_FILES['menu_image']) && $_FILES['menu_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $tmp = $_FILES['menu_image']['tmp_name'];
            $mime = is_uploaded_file($tmp) ? mime_content_type($tmp) : '';
            if (
                !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
                || (int)$_FILES['menu_image']['size'] > 10 * 1024 * 1024
            ) {
                http_response_code(422);
                exit('Invalid menu image');
            }
            $menuId = date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $extension = menuExtension($mime);
            $libraryFile = $menuId . '.' . $extension;
            $libraryPath = $menuLibraryDir . '/' . $libraryFile;
            move_uploaded_file($tmp, $libraryPath);
            copy($libraryPath, $menuFile);
            $menuLibrary[$menuId] = [
                'name' => menuLabel((string)($_POST['menu_label'] ?? ''), (string)($_FILES['menu_image']['name'] ?? '')),
                'file' => $libraryFile,
                'mime' => $mime,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            saveMenuLibrary($menuLibraryFile, $menuLibrary);
        }
    }
    header('Location: /');
    exit;
}

$users = loadUsers($usersFile);
$orders = loadOrders($ordersFile);
$settings = loadSettings($settingsFile);
$menuLibrary = loadMenuLibrary($menuLibraryFile);
$balanceAudit = loadBalanceAudit($balanceAuditFile, 50);
$deadline = deadlineTimestamp($settings);
$ordersClosed = $deadline !== null && time() >= $deadline;
$deadlineValue = (string)($settings['order_deadline'] ?? '');
$selectedDeadlineDate = $deadlineValue !== '' ? substr($deadlineValue, 0, 10) : '';
$selectedDeadlineHour = $deadlineValue !== '' ? substr($deadlineValue, 11, 2) : date('H');
$selectedDeadlineMinute = $deadlineValue !== '' ? substr($deadlineValue, 14, 2) : '00';
$deadlineDates = [];
for ($dayOffset = 0; $dayOffset < 14; $dayOffset++) {
    $dateOption = (new DateTimeImmutable('today', new DateTimeZone('Asia/Taipei')))
        ->modify('+' . $dayOffset . ' days');
    $deadlineDates[$dateOption->format('Y-m-d')] = $dateOption->format('m/d') . '（' . ['日', '一', '二', '三', '四', '五', '六'][(int)$dateOption->format('w')] . '）';
}
if ($selectedDeadlineDate !== '' && !isset($deadlineDates[$selectedDeadlineDate])) {
    $deadlineDates = [$selectedDeadlineDate => $selectedDeadlineDate] + $deadlineDates;
}
$balances = $users;
foreach ($orders as $order) {
    $name = (string)($order['user'] ?? '');
    if (array_key_exists($name, $balances)) {
        $balances[$name] -= (int)($order['price'] ?? 0);
    }
}
$todayOrders = array_values(array_filter($orders, static fn(array $order): bool => ($order['date'] ?? '') === date('Y-m-d')));
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>威杰飲料管理系統</title>
<style>
:root{--primary:#f97316;--primary-soft:#fed7aa;--success:#22c55e;--bg:#fff7ed;--panel:#ffffff;--panel-strong:#ffedd5;--text:#1f2937;--dim:#6b7280;--line:#fdba74;--danger:#ef4444;--shadow:0 18px 45px rgba(154,52,18,.14)}
*{box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif;background:radial-gradient(circle at 12% 8%,rgba(253,186,116,.55) 0 120px,transparent 121px),radial-gradient(circle at 88% 16%,rgba(120,53,15,.14) 0 90px,transparent 91px),linear-gradient(135deg,#fff7ed 0,#fef3c7 45%,#f8fafc 100%);color:var(--text);margin:0;padding:24px;position:relative;overflow-x:hidden}
body:before{content:"";position:fixed;inset:0;pointer-events:none;opacity:.28;background:
radial-gradient(circle at 8% 88%,#78350f 0 6px,transparent 7px),
radial-gradient(circle at 13% 84%,#78350f 0 5px,transparent 6px),
radial-gradient(circle at 18% 90%,#78350f 0 7px,transparent 8px),
radial-gradient(ellipse at 78% 84%,#65a30d 0 10px,transparent 11px),
radial-gradient(ellipse at 83% 80%,#84cc16 0 13px,transparent 14px),
radial-gradient(ellipse at 90% 87%,#4d7c0f 0 10px,transparent 11px)}
.app-shell{max-width:1280px;margin:0 auto}
.hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:20px;padding:22px;border-radius:28px;background:linear-gradient(135deg,#9a3412,#f97316);color:white;box-shadow:var(--shadow)}
.hero h1{margin:0;font-size:2rem;letter-spacing:.03em}.hero p{margin:.4rem 0 0;color:#ffedd5}.status-strip{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:9px 13px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25);font-size:.9rem;font-weight:700}
.layout{display:grid;grid-template-columns:minmax(360px,1.15fr) minmax(360px,.85fr);gap:20px;align-items:start}
.box{background:rgba(255,255,255,.92);padding:18px;border-radius:24px;margin-bottom:16px;border:1px solid #fed7aa;box-shadow:var(--shadow)}
.box h2{margin:0 0 12px;font-size:1.1rem;color:#9a3412}.section-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px}
label{display:block;font-size:.78rem;color:var(--dim);font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-top:10px}
select,input,button{width:100%;padding:13px 14px;border-radius:14px;border:1px solid #fdba74;margin-top:7px;font-size:1rem}
select,input{background:#fffaf5;color:var(--text)}button{border:0;background:var(--primary);color:white;font-weight:800;cursor:pointer;box-shadow:0 10px 20px rgba(249,115,22,.24);border-radius:999px}
button:hover{filter:brightness(.98);transform:translateY(-1px)}.success{background:var(--success);color:white}.danger{background:var(--danger)}.muted{color:var(--dim)}
.danger{border-radius:10px 18px 10px 18px}.success{border-radius:18px;box-shadow:0 6px 0 #15803d,0 14px 24px rgba(34,197,94,.22)}
.actions .danger{border-radius:8px 16px 8px 16px}.actions button:not(.danger){border-radius:999px}
.notice{background:var(--panel-strong);border-color:var(--primary);text-align:center;font-weight:700}.closed{border-color:var(--danger);color:#991b1b;background:#fee2e2}
button:disabled,input:disabled{opacity:.5;cursor:not-allowed;transform:none}.menu{width:100%;border-radius:20px;display:block;border:1px solid #fed7aa}
.menu-card{overflow:hidden;padding:10px}.order{padding:13px;border:1px solid #ffedd5;border-radius:16px;margin-top:10px;background:#fffaf5}
.actions{display:flex;gap:8px}.actions>*{flex:1;min-width:0}.actions button{padding:9px}.admin{border-color:#86efac}
.order-form{display:grid;grid-template-columns:1.2fr .6fr;gap:10px}.order-form .wide{grid-column:1/-1}
table{width:100%;border-collapse:separate;border-spacing:0 8px}td,th{text-align:left;padding:10px;background:#fffaf5}th{color:var(--dim);font-size:.78rem;text-transform:uppercase}td:first-child,th:first-child{border-radius:12px 0 0 12px}td:last-child,th:last-child{border-radius:0 12px 12px 0}
.sidebar{position:sticky;top:20px}.total-badge{white-space:nowrap;color:#9a3412;background:#ffedd5;border-radius:999px;padding:6px 10px;font-weight:800}
.bubble-bg{position:absolute;right:18px;bottom:12px;color:rgba(120,53,15,.16);font-size:3rem;line-height:1;pointer-events:none}
@media(max-width:900px){body{padding:14px}.hero{display:block}.status-strip{justify-content:flex-start;margin-top:14px}.layout{grid-template-columns:1fr}.sidebar{position:static}.order-form{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="app-shell">
<header class="hero">
<div>
<h1>威杰飲料管理系統</h1>
<p>開團、看菜單、填訂單、結單下載，今天喝什麼一頁搞定。</p>
</div>
<div class="status-strip">
<span class="pill">開團人：<?= h((string)($settings['organizer'] ?? '尚未設定')) ?></span>
<span class="pill"><?= count($todayOrders) ?> 筆訂單</span>
<span class="pill">$<?= h(array_sum(array_map(static fn(array $order): int => (int)($order['price'] ?? 0), $todayOrders))) ?></span>
</div>
</header>
<div class="layout">
<section>
<div class="box notice <?= $ordersClosed ? 'closed' : '' ?>">
<?php if ($deadline !== null): ?>
<strong><?= $ordersClosed ? '訂單已截止' : '訂單截止時間' ?></strong><br>
<?= h(date('Y-m-d H:i', $deadline)) ?>（台北時間）
<?php else: ?>
<strong>目前未設定訂單截止時間</strong>
<?php endif; ?>
</div>
<div class="box">
<h2>開團設定</h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<label>開團人</label>
<select name="organizer" required>
<option value="">請選擇開團人</option>
<?php foreach ($users as $name => $_balance): ?>
<option value="<?= h($name) ?>" <?= ($settings['organizer'] ?? '') === $name ? 'selected' : '' ?>><?= h($name) ?></option>
<?php endforeach; ?>
</select>
<label>訂單截止時間（台北時間）</label>
<div class="actions">
<select name="deadline_date" aria-label="截止日期">
<option value="">不限時</option>
<?php foreach ($deadlineDates as $dateValue => $dateLabel): ?>
<option value="<?= h($dateValue) ?>" <?= $selectedDeadlineDate === $dateValue ? 'selected' : '' ?>><?= h($dateLabel) ?></option>
<?php endforeach; ?>
</select>
<select name="deadline_hour" aria-label="截止小時">
<?php for ($hour = 0; $hour < 24; $hour++): $hourValue = str_pad((string)$hour, 2, '0', STR_PAD_LEFT); ?>
<option value="<?= $hourValue ?>" <?= $selectedDeadlineHour === $hourValue ? 'selected' : '' ?>><?= $hourValue ?> 時</option>
<?php endfor; ?>
</select>
<select name="deadline_minute" aria-label="截止分鐘">
<?php foreach (['00', '10', '20', '30', '40', '50'] as $minuteValue): ?>
<option value="<?= $minuteValue ?>" <?= $selectedDeadlineMinute === $minuteValue ? 'selected' : '' ?>><?= $minuteValue ?> 分</option>
<?php endforeach; ?>
</select>
</div>
<button name="public_action" value="update_group">儲存開團人與截止時間</button>
</form>
</div>
<div class="box">
<h2>菜單管理</h2>
<span class="bubble-bg">● ● ●</span>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<?php if ($menuLibrary): ?>
<label>歷史菜單</label>
<select name="history_menu_id">
<option value="">不套用歷史菜單</option>
<?php foreach (array_reverse($menuLibrary, true) as $menuId => $menuItem): ?>
<option value="<?= h($menuId) ?>"><?= h($menuItem['name'] ?? '未命名菜單') ?></option>
<?php endforeach; ?>
</select>
<?php endif; ?>
<label>上傳新菜單圖片</label>
<input type="file" name="menu_image" accept="image/jpeg,image/png,image/webp">
<input type="text" name="menu_label" maxlength="60" placeholder="新菜單名稱（上傳後保存到歷史）">
<button name="public_action" value="update_menu">套用歷史菜單／上傳新菜單</button>
</form>
<?php if ($menuLibrary): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<label>編輯歷史菜單名稱</label>
<select name="history_menu_id" required>
<?php foreach (array_reverse($menuLibrary, true) as $menuId => $menuItem): ?>
<option value="<?= h($menuId) ?>"><?= h($menuItem['name'] ?? '未命名菜單') ?></option>
<?php endforeach; ?>
</select>
<input type="text" name="history_menu_name" maxlength="60" placeholder="新的菜單名稱" required>
<button name="public_action" value="rename_menu">更新歷史菜單名稱</button>
</form>
<?php endif; ?>
</div>
<div class="box">
<h2>結單下載</h2>
<form method="post" onsubmit="return confirm('確定結單？這會扣除本團消費、歸檔所有訂單，並下載 CSV 到這台電腦。')">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<button class="success" name="public_action" value="settle_group" <?= $orders ? '' : 'disabled' ?>>結單並下載 CSV</button>
</form>
</div>
<div class="box">
<h2>我要點餐</h2>
<label>使用者與目前餘額</label>
<select id="user">
<?php foreach ($balances as $name => $balance): ?>
<option value="<?= h($name) ?>"><?= h($name) ?>（$<?= h($balance) ?>）</option>
<?php endforeach; ?>
</select>
</div>
<?php if (is_file($menuFile)): ?><div class="box menu-card"><div class="section-title"><h2>今日菜單</h2><span class="total-badge">點圖放大查看</span></div><img class="menu" src="/?menu_image=1" alt="今日菜單"></div><?php endif; ?>
<div class="box">
<div class="section-title"><h2>新增訂單</h2><span class="total-badge"><?= $ordersClosed ? '已截止' : '開放中' ?></span></div>
<div class="order-form">
<input id="item" maxlength="100" placeholder="品項，例如：珍珠奶茶 微糖少冰" required <?= $ordersClosed ? 'disabled' : '' ?>>
<input id="price" type="number" min="1" max="100000" placeholder="價格" required <?= $ordersClosed ? 'disabled' : '' ?>>
<input class="wide" id="mood" maxlength="100" placeholder="今日心情，例如：今天想喝甜一點、去冰、加珍珠" <?= $ordersClosed ? 'disabled' : '' ?>>
</div>
<button class="success" onclick="createOrder()" <?= $ordersClosed ? 'disabled' : '' ?>><?= $ordersClosed ? '訂單已截止' : '送出訂單' ?></button>
</div>
<div class="box">
<div class="section-title"><h2>我的今日訂單</h2><span class="total-badge">可修改 / 刪除</span></div>
<div id="mine"></div>
</div>
</section>
<aside class="sidebar">
<div class="box">
<div class="section-title"><h2>今日訂單</h2><span class="total-badge"><?= count($todayOrders) ?> 筆</span></div>
<table><thead><tr><th>姓名 / 品項</th><th>價格</th><th>今日心情</th></tr></thead><tbody>
<?php foreach (array_reverse($todayOrders) as $order): ?>
<tr><td><small class="muted"><?= h($order['user']) ?></small><br><?= h($order['item']) ?></td><td>$<?= h($order['price']) ?></td><td><?= h($order['mood'] ?? '無') ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php if (!empty($_SESSION['is_admin'])): ?>
<div class="box admin">
<h2>管理員</h2>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<label>修改個人目前餘額</label>
<select name="balance_user" required>
<?php foreach ($balances as $name => $balance): ?>
<option value="<?= h($name) ?>"><?= h($name) ?>（目前 $<?= h($balance) ?>）</option>
<?php endforeach; ?>
</select>
<input type="number" name="target_balance" placeholder="修改後餘額" required>
<input type="text" name="balance_note" maxlength="120" placeholder="調整原因（例如：儲值、補扣、修正）">
<button name="admin_action" value="set_balance">更新個人餘額</button>
</form>
<form method="post">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="text" name="user_name" maxlength="50" placeholder="使用者姓名">
<input type="number" name="initial_balance" placeholder="初始餘額" value="0">
<div class="actions">
<button name="admin_action" value="add_user">新增</button>
<button class="danger" name="admin_action" value="delete_user">刪除</button>
</div>
</form>
<h3>金額修改紀錄</h3>
<?php if ($balanceAudit): ?>
<table>
<thead><tr><th>時間 / 人員</th><th>異動</th><th>原因</th></tr></thead>
<tbody>
<?php foreach ($balanceAudit as $audit): ?>
<?php
$beforeAudit = $audit['before_balance'] ?? null;
$afterAudit = $audit['after_balance'] ?? null;
$amountAudit = (int)($audit['amount'] ?? 0);
$actionLabels = [
    'manual_adjust' => '手動調整',
    'settle_order' => '結單扣款',
    'add_user' => '新增人員',
    'delete_user' => '刪除人員',
];
?>
<tr>
<td><small class="muted"><?= h($audit['time'] ?? '') ?></small><br><?= h($audit['user'] ?? '') ?></td>
<td>
<?= h($actionLabels[$audit['action'] ?? ''] ?? ($audit['action'] ?? '異動')) ?><br>
<small class="muted">
<?= $beforeAudit === null ? '無' : '$' . h($beforeAudit) ?> → <?= $afterAudit === null ? '無' : '$' . h($afterAudit) ?>
（<?= $amountAudit >= 0 ? '+' : '' ?><?= h($amountAudit) ?>）
</small>
</td>
<td><?= h(($audit['note'] ?? '') !== '' ? $audit['note'] : '無') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php else: ?>
<p class="muted">尚無金額修改紀錄。</p>
<?php endif; ?>
<p><a class="muted" href="/?logout=1">登出</a></p>
</div>
<?php else: ?>
<div class="box">
<h2>管理員登入</h2>
<?php if ($loginError): ?><p style="color:var(--danger)"><?= h($loginError) ?></p><?php endif; ?>
<form method="post">
<input name="username" autocomplete="username" placeholder="帳號" required>
<input type="password" name="password" autocomplete="current-password" placeholder="密碼" required>
<button name="admin_login" value="1">登入</button>
</form>
</div>
<?php endif; ?>
</aside>
</div>
</div>
<script>
const csrf=<?= json_encode($_SESSION['csrf']) ?>;
const ordersClosed=<?= $ordersClosed ? 'true' : 'false' ?>;
let orders=<?= json_encode($todayOrders, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const userEl=document.getElementById('user');
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function api(payload){
  const response=await fetch('/',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,csrf})});
  const data=await response.json().catch(()=>({error:'操作失敗'}));
  if(!response.ok)throw new Error(data.error||'操作失敗');
  return data;
}
async function createOrder(){
  if(ordersClosed)return alert('訂單已截止');
  const item=document.getElementById('item').value.trim(),price=Number(document.getElementById('price').value);
  if(!item||!Number.isInteger(price)||price<=0)return alert('請輸入品項與正確價格');
  await api({action:'create',user:userEl.value,item,price,mood:document.getElementById('mood').value});
  location.reload();
}
async function removeOrder(id){
  if(ordersClosed)return alert('訂單已截止');
  if(!confirm('確定刪除此訂單？'))return;
  await api({action:'delete',user:userEl.value,id});location.reload();
}
async function editOrder(id){
  if(ordersClosed)return alert('訂單已截止');
  const order=orders.find(o=>o.id===id);
  const item=prompt('品項',order.item);if(item===null)return;
  const price=Number(prompt('價格',order.price));if(!item.trim()||!Number.isInteger(price)||price<=0)return alert('輸入不正確');
  const mood=prompt('今日心情',order.mood||'無');if(mood===null)return;
  await api({action:'edit',user:userEl.value,id,item:item.trim(),price,mood});location.reload();
}
function renderMine(){
  const mine=orders.filter(o=>o.user===userEl.value);
  document.getElementById('mine').innerHTML=mine.length?mine.map(o=>`<div class="order"><b>${esc(o.item)}</b>　$${o.price}<br><small class="muted">${esc(o.mood||'無')} ${esc(o.time)}</small>${ordersClosed?'':`<div class="actions"><button onclick="editOrder('${o.id}')">修改</button><button class="danger" onclick="removeOrder('${o.id}')">刪除</button></div>`}</div>`).join(''):'<p class="muted">目前沒有訂單</p>';
}
userEl.addEventListener('change',renderMine);renderMine();
</script>
</body>
</html>
