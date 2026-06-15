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
$appPassword = getenv('APP_PASSWORD') ?: '';
$adminUser = getenv('ADMIN_USER') ?: 'admin';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';

foreach ([$dataDir, $historyDir] as $dir) {
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
        <title>威杰智慧點餐系統</title>
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
        <h1>威杰智慧點餐系統</h1>
        <p>請輸入公司共用密碼。</p>
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
    requireAdmin();
    $file = basename((string)$_GET['download_csv']);
    $path = $historyDir . '/' . $file;
    if (!preg_match('/^report_\d{8}_\d{6}\.csv$/', $file) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path);
    exit;
}

$users = loadUsers($usersFile);
$orders = loadOrders($ordersFile);
$settings = loadSettings($settingsFile);
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
            $users[$name] = (int)($_POST['initial_balance'] ?? 0);
            saveUsers($usersFile, $users);
        }
    } elseif ($action === 'delete_user') {
        $name = trim((string)($_POST['user_name'] ?? ''));
        if ($name !== '管理員') {
            unset($users[$name]);
            saveUsers($usersFile, $users);
        }
    } elseif ($action === 'settle') {
        foreach ($orders as $order) {
            $name = (string)($order['user'] ?? '');
            if (array_key_exists($name, $users)) {
                $users[$name] -= (int)($order['price'] ?? 0);
            }
        }
        saveUsers($usersFile, $users);
        if ($orders) {
            $timestamp = date('Ymd_His');
            $historyFile = $historyDir . '/history_' . $timestamp . '.txt';
            rename($ordersFile, $historyFile);
            file_put_contents($ordersFile, '');
            $csvName = 'report_' . $timestamp . '.csv';
            $csv = fopen($historyDir . '/' . $csvName, 'wb');
            fwrite($csv, "\xEF\xBB\xBF");
            fputcsv($csv, ['日期', '時間', '使用者', '品項', '價格', '備註']);
            foreach ($orders as $order) {
                fputcsv($csv, [$order['date'], $order['time'], $order['user'], $order['item'], $order['price'], $order['mood'] ?? '無']);
            }
            fclose($csv);
            header('Location: /?download_csv=' . rawurlencode($csvName));
            exit;
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
            $users[$name] = $targetBalance + $pendingSpent;
            saveUsers($usersFile, $users);
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
    if ((string)$_POST['public_action'] === 'update_group') {
        $organizer = trim((string)($_POST['organizer'] ?? ''));
        if (mb_strlen($organizer) > 50) {
            http_response_code(422);
            exit('Invalid organizer');
        }
        $settings['organizer'] = $organizer;
        $deadlineValue = isset($_POST['clear_deadline'])
            ? ''
            : trim((string)($_POST['order_deadline'] ?? ''));
        if ($deadlineValue !== '') {
            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $deadlineValue, new DateTimeZone('Asia/Taipei'));
            if (!$date || $date->format('Y-m-d\TH:i') !== $deadlineValue) {
                http_response_code(422);
                exit('Invalid deadline');
            }
        }
        $settings['order_deadline'] = $deadlineValue;
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
            move_uploaded_file($tmp, $menuFile);
        }
        saveSettings($settingsFile, $settings);
    }
    header('Location: /');
    exit;
}

$users = loadUsers($usersFile);
$orders = loadOrders($ordersFile);
$settings = loadSettings($settingsFile);
$deadline = deadlineTimestamp($settings);
$ordersClosed = $deadline !== null && time() >= $deadline;
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
<title>威杰智慧點餐系統</title>
<style>
:root{--primary:#818cf8;--success:#34d399;--bg:#0f172a;--card:#1e293b;--text:#f8fafc;--dim:#94a3b8;--danger:#f87171}
*{box-sizing:border-box}body{font-family:system-ui;background:var(--bg);color:var(--text);margin:0;padding:20px}
.layout{display:grid;grid-template-columns:minmax(300px,1fr) minmax(320px,1fr);max-width:1200px;margin:auto;gap:20px}
.box{background:var(--card);padding:18px;border-radius:16px;margin-bottom:15px;border:1px solid #334155}
h1{color:var(--primary)}label{font-size:.8rem;color:var(--dim);font-weight:700}
select,input,button{width:100%;padding:12px;border-radius:10px;border:1px solid #475569;margin-top:7px}
select,input{background:var(--bg);color:#fff}button{border:0;background:var(--primary);color:#fff;font-weight:700;cursor:pointer}
.success{background:var(--success);color:#064e3b}.danger{background:var(--danger)}.muted{color:var(--dim)}
.notice{border-color:var(--primary);text-align:center}.closed{border-color:var(--danger);color:#fecaca}
button:disabled,input:disabled{opacity:.5;cursor:not-allowed}
.menu{width:100%;border-radius:12px;display:block}.order{padding:12px 0;border-bottom:1px solid #334155}
.actions{display:flex;gap:8px}.actions button{padding:7px}.admin{border-color:var(--success)}
table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:9px;border-bottom:1px solid #334155}
@media(max-width:800px){.layout{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="layout">
<section>
<h1>威杰智慧點餐系統</h1>
<div class="box notice <?= $ordersClosed ? 'closed' : '' ?>">
<?php if ($deadline !== null): ?>
<strong><?= $ordersClosed ? '訂單已截止' : '訂單截止時間' ?></strong><br>
<?= h(date('Y-m-d H:i', $deadline)) ?>（台北時間）
<?php else: ?>
<strong>目前未設定訂單截止時間</strong>
<?php endif; ?>
</div>
<div class="box">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<label>開團人</label>
<input type="text" name="organizer" maxlength="50" value="<?= h((string)($settings['organizer'] ?? '')) ?>" placeholder="請輸入開團人姓名" required>
<label>訂單截止時間（台北時間）</label>
<input type="datetime-local" name="order_deadline" value="<?= h((string)($settings['order_deadline'] ?? '')) ?>">
<label style="display:flex;align-items:center;gap:8px;margin-top:10px;text-transform:none">
<input type="checkbox" name="clear_deadline" value="1" style="width:auto;margin:0">
清除截止時間（不限時）
</label>
<label>選擇菜單圖片（可只更新開團人）</label>
<input type="file" name="menu_image" accept="image/jpeg,image/png,image/webp">
<button name="public_action" value="update_group">儲存開團人、截止時間與菜單</button>
</form>
</div>
<div class="box">
<label>使用者與目前餘額</label>
<select id="user">
<?php foreach ($balances as $name => $balance): ?>
<option value="<?= h($name) ?>"><?= h($name) ?>（$<?= h($balance) ?>）</option>
<?php endforeach; ?>
</select>
</div>
<?php if (is_file($menuFile)): ?><div class="box"><img class="menu" src="/?menu_image=1" alt="今日菜單"></div><?php endif; ?>
<div class="box">
<label>新增訂單</label>
<input id="item" maxlength="100" placeholder="品項" required <?= $ordersClosed ? 'disabled' : '' ?>>
<input id="price" type="number" min="1" max="100000" placeholder="價格" required <?= $ordersClosed ? 'disabled' : '' ?>>
<input id="mood" maxlength="100" placeholder="備註" <?= $ordersClosed ? 'disabled' : '' ?>>
<button class="success" onclick="createOrder()" <?= $ordersClosed ? 'disabled' : '' ?>><?= $ordersClosed ? '訂單已截止' : '送出訂單' ?></button>
</div>
<div class="box">
<label>所選使用者的今日訂單</label>
<div id="mine"></div>
</div>
</section>
<aside>
<div class="box">
<label>今日訂單（<?= count($todayOrders) ?> 筆）</label>
<table><thead><tr><th>姓名 / 品項</th><th>價格</th><th>備註</th></tr></thead><tbody>
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
<form method="post" onsubmit="return confirm('確定結帳並歸檔今日訂單？')">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<button class="success" name="admin_action" value="settle">結帳並下載 CSV</button>
</form>
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
  const mood=prompt('備註',order.mood||'無');if(mood===null)return;
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
