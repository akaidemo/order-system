# 威杰智慧點餐系統

這是可部署到 Railway、Render 或一般 Docker 主機的 PHP 點餐系統。

## 必要環境變數

- `APP_PASSWORD`: 公司共用入口密碼
- `ADMIN_USER`: 管理員帳號，預設 `admin`
- `ADMIN_PASSWORD`: 管理員密碼
- `DATA_DIR`: 持久資料目錄，預設 `/data`

正式環境請將持久 Volume 掛載至 `/data`。此目錄保存：

- `orders.txt`
- `users.json`
- `menu`（管理員上傳的 JPG、PNG 或 WebP 菜單，最多 10 MB）
- `history/`

## Railway

1. 從此 GitHub repository 建立 Railway 專案。
2. 新增 Volume，Mount Path 設為 `/data`。
3. 設定 `APP_PASSWORD`、`ADMIN_USER`、`ADMIN_PASSWORD`。
4. Generate Domain 後即可使用 HTTPS 網址。

## 本機驗證

```sh
docker compose up --build
```

開啟 `http://localhost:8080`。

## 舊資料

`seed/` 只保留於交付壓縮檔，不應提交 GitHub。首次部署後，把該資料夾內的檔案上傳到 Railway Volume 的 `/data`，將 `menu.jpg` 改名為 `menu`，並將 `history/` 放到 `/data/history/`。
