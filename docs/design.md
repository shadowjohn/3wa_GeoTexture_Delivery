# GeoTexture Delivery：PHP 圖台與區域任務

## 已確認需求

- Windows 本機開發，PHP 網站，E8 使用 `https://3wa.tw/inc/javascript/easymap8/easymap.js`。
- 圖台套疊新北建物，使用者畫 Polygon 建立處理任務。
- 後台顯示範圍、面積、建物數、實際進度與錯誤，可定位、取消與重試。
- crontab 呼叫 PHP CLI 分批準備任務；下一階段才接 PHP／Python 轉換器。
- 可另畫 Polygon 匯出，與原處理範圍獨立。
- 建物成果獨立保存，以來源＋build_id＋輸入版本辨識；重疊任務重用有效成果。
- 圖台區分未處理、待處理、失敗、已完成。未來 GLB 貼皮成果可直接放回 E8。

## 本階段交付

PHP + SQLite + 原生 HTML/JS，無前端 build、Composer 或資料庫伺服器。來源 GeoSQLite 僅唯讀；任務與帳號另存 `db/app.sqlite`。原始資料預設使用 repository 外的 `../建物資料/F_新北建物8418.db`，可用環境變數指定。

依後續確認，第一版包含 admin/admin 帳密登入、GD 圖形驗證碼、帳號設定修改密碼；密碼以雜湊保存。全部後台頁面與 API 均需登入，變更任務與帳密使用 CSRF token。`db/.htaccess` 與 `db/web.config` 阻擋 Apache／IIS 的直接 HTTP 存取，網站根目錄使用 `public/`，Linux 以 www-data 群組權限讀寫 db。設計參考與驗證截圖保存在 `snapshot/`。

來源有 460,840 個非空且唯一的 build_id；EPSG:4326，GEOMETRYCOLLECTION WKT，已有 `idx_8418_GEOMETRY` R-tree。先依 bbox 查索引，再作精確 Polygon 相交，避免把外接矩形內但 Polygon 外的建物算入。

建立任務時凍結建物清單與輸入 hash。CLI 每次準備有限筆數；完成前置作業後顯示「待轉換」，貼皮完成數維持 0，不模擬轉換成功。可重試失敗任務、取消任務。CLI 以檔案鎖避免 crontab 重疊執行。

匯出 ZIP 先包含 Polygon、建物 GeoJSON、成果與缺漏 manifest。只有已登錄且檔案實際存在、版本相符的 GLB 才算完成與可重用；沒有模型時不產生假模型。未來透過 CLI 登錄轉換成果，E8 載入模型並在匯出包附上。

目前服務限本機，不部署、不安裝系統排程、不推送 Git。保留原有 LICENSE 刪除狀態。

## 模組與介面

- `src/Geometry.php`: `Geometry::validatePolygon(array): array`、`bbox(array): array`、`area(array): float`（m²）、`fromWkt(string): array`（Polygon/MultiPolygon）、`intersects(array,array): bool`。
- `src/BuildingSource.php`: `__construct(string $path)`、`meta(): array`、`query(array $bbox,int $limit=2000,?array $polygon=null): array`。回傳 GeoJSON FeatureCollection；`meta` 含 `returned`、`truncated`。feature properties 為 `build_id,name,height,height_missing,source_hash,source_key`，id 為 build_id。最大查詢範圍及頂點數需限制。
- `src/App.php`、`src/bootstrap.php`: 初始化來源及任務儲存、任務與成果管理、匯出。
- `public/api.php`: `?action=meta|buildings|selection|jobs|job|create_job|cancel_job|retry_job|export`。錯誤 `{error: string}` 搭配適當 HTTP status。bbox GET 逗號數列；Polygon POST `{polygon: GeoJSON Polygon}`；建立任務另含 `name,force`；job 使用 `id`。其餘 JSON 成功為 `{data: ...}`，buildings/selection 的 data 為 FeatureCollection。
- 任務 JSON：`id,name,polygon,area_m2,total,prepared,completed,failed,skipped,status,created_at,updated_at,error`。狀態 `queued,preparing,awaiting_converter,completed,failed,cancelled`。
- 建物圖台 properties 另含 `status`（`unprocessed,queued,failed,completed`）、`model`（null 或模型資訊）。model 為 `url,longitude,latitude,altitude,heading,scale,asset_id`，GLB 透過安全的成果下載 API 取得。
- meta JSON：`source`（ready/name/error）、`center`（longitude/latitude）、`zoom`、`converter_ready:false`、`csrf_token`。變更 API 使用 `X-CSRF-Token`。
- `cli/worker.php --batch=100`: 只執行一次有限批次，輸出 JSON 統計，無背景常駐程序；`cli/register-result.php` 未來轉換器可登錄 GLB 與來源 hash。
- `public/index.php`、`public/assets/`: 一個工作站含圖台、任務列表與詳情。Polygon 編輯與圖層使用 E8 公開 API；E8 preview.5 沒有 E7 的 setDrawMode，透過公開 getNativeContext 擴充繪圖。

## 驗證

幾何洞、多面、邊界相交與無效 Polygon；R-tree 選取；任務持久化、重疊任務重用、取消/重試、批次準備不假完成、匯出內容與遺失成果處理。實際開啟 E8 CDN 圖台，畫 Polygon、建任務、CLI 準備、再畫 Polygon 匯出，檢查桌面與窄螢幕。
