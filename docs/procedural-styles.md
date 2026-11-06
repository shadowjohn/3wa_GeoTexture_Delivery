# 程序化建物風格

第一版提供台灣街區、現代簡約、粉彩插畫。建物輪廓與高度使用原始資料；窗戶、樓層分割、牆面和屋頂配色均為示意。沒有使用 World Gacha 的程式或素材。

## 網頁使用

1. 在「建物外觀」選風格，框選範圍並建立任務。
2. 建立任務後由背景排程自動處理，關閉頁面仍會繼續。任務卡片每 3 秒以 AJAX 更新完成數、百分比、待處理與失敗數。需要時可按「立即處理一批」啟動最多 10 棟；失敗項目保留原因，可按重試。
3. 切換 3D；任務詳情可定位已完成建物並開啟獨立預覽。
4. 同一範圍改選另一風格可建立另一任務。所有版本並存，地圖與匯出依目前風格選取。
5. 在成果匯出重新框選，下載 ZIP。模型內嵌 PNG，並在 GLB 與 ZIP manifest 標記 `appearance_kind=procedural`、`verification_status=synthetic`。

「實景貼皮」仍使用原本 CLI 影像試轉流程，不會把示意立面當成實景觀測。正式站已於 2026-11-06 安裝 john 的每分鐘 cron，依建立順序執行 `php cli/worker.php --batch=100 --time-budget=45`。每批最多 100 棟，達 45 秒後不再啟動下一棟，當前建物完成後結束；與網頁共用 `db/worker.lock`，避免同時轉換。實景配方只準備輸入後停在待實景轉換。排程紀錄位於 `/home/john/logs/map-building-skin-worker.log`，時間戳為 UTC。

## 安裝

```sh
python3 -m venv .venv-procedural
.venv-procedural/bin/python -m pip install -r requirements-procedural.txt
```

PHP 需允許 `proc_open`。worker 優先用 `GEOTEXTURE_PYTHON`，未指定時採專案 `.venv-procedural/bin/python`，再退回 `python3`。檔案鎖、來源版本、取消狀態及私有成果存取沿用原有任務系統。缺失或損壞的程序化模型會重新排隊；高度缺值或無效輪廓會失敗，不會補假高度。

worker 每批以 `python -u -m pipeline.procedural --batch` 共用一個行程，一次只送一棟 NDJSON（含 `request` 與絕對路徑 `output_path`，上限 8 MiB），產生模型和 manifest 後立即收到 `{"ok":true}`。有效的單棟失敗回應不會中斷其餘建物；通訊錯誤或 20 秒逾時會回收子程序，下一棟重新啟動。舊單檔 CLI 保留。PHP 負責任務、取消及成果驗證；Python 不直接更新 SQLite。

新產出的程序化 GLB 將外牆、屋頂、底面各合併成一個 primitive，以 `extras.surface_ranges` 的 `key`、`index_offset`、`index_count` 保存逐面三角形對照；range 以索引元素計數，必須連續且完整涵蓋。既有單面 primitive 仍可登錄。幾何、UV 與貼圖未改變，[實測結果](2026-11-06-material-results.md) 記錄繪製次數及限制。

進度 API `GET api.php?action=progress` 需登入，只回傳各任務的資料庫計數、狀態及 worker 鎖狀態，不掃描模型檔或傳回完整幾何。頁面隱藏時暫停輪詢，避免重疊請求；網路錯誤保留上次進度並自動重試。地圖成果更新最多每 15 秒一次，建物明細按「更新建物清單」取得。

## 新增風格

- `src/Recipes.php`：註冊唯一版本 key、中文名稱、說明及 `kind=procedural`。網頁選單直接讀取此清單。
- `pipeline/procedural.py`：將 key 加入 `RECIPES`，在 `_facade`、`_roof` 定義圖案、配色與公尺重複尺寸。所有風格共用幾何、GLB 和輸出契約。
- 任何影響外觀的規則更新都使用新 key（如 `procedural-tw-v2`），保留舊版生成規則，避免同一版本輸出不同外觀。
- 以 `source_key/build_id/recipe_key` 產生固定 seed；不以執行時間或臨時檔名決定外觀。幾何變更則由 `source_hash` 區分。
- 新增測試確認 deterministic bytes、貼圖差異、內洞/多 Polygon、真實高度、法線與軸向、材質完整性、API 風格隔離、ZIP 風格標記。
- 預設省略 API `recipe_key` 仍是 `texture-v1`，保留舊客戶端行為。網頁則預設選台灣街區。

## 第一版範圍

逐棟 GLB 內部共用牆面材質、屋頂材質，各自內嵌貼圖；不同 GLB 尚未共用材質圖集。適合街廓試製，尚未做全市效能或 3D Tiles 打包。進階場景光照、夜景、輪廓後製、幾何陽台及城市來源切換不在此版。台中路徑已知，後續接入仍需資料表與來源鍵的多來源支援。

首頁預設 3D。地圖先顯示所有查回建物的白模，依視野中心距離逐批補上貼皮：桌機最多常駐 64 棟、同時下載 4 棟；圖台寬度小於 600px 時為 24 棟、同時下載 2 棟。遠處、等待中或下載失敗的建物保留白模，模型 ready 後才替換。移動視野會卸載舊模型並載入近處模型；快速切換風格時，過期下載完成即清除，不搶占新風格畫面。下方顯示貼皮載入棟數，這個預覽額度不影響完整 ZIP 交付。

地圖使用 `buildings&detail=preview` 省略逐面及貼圖明細，保留定位、面數、來源與品質警告；不帶此參數的 API、任務詳情及匯出仍使用完整資料。成果登錄維持完整 GLB 驗證，後續每次讀取比對檔案大小及 SHA-256，不重複解析每個頂點。模型 HTTP 採 `private, no-cache` 與 ETag，304 回應前仍檢查登入及檔案完整性。

## 驗證

```sh
php tests/procedural.php
php tests/batch-worker.php
.venv-procedural/bin/python -m pytest tests/test_procedural.py tests/test_procedural_groups.py tests/test_procedural_batch.py tests/test_http_procedural.py tests/test_glb_export.py -q
node tests/frontend.mjs
node tests/map-preview.mjs
```

PHP/HTTP 測試使用暫存資料庫與測試帳號；不修改正式密碼或資料。`tests/test_http_procedural.py` 另需 pytest 和 requests。

## 帳號防盜用

密碼以 PHP `password_hash(PASSWORD_DEFAULT)` 儲存單向加鹽雜湊（目前部署為 bcrypt），用 `password_verify` 比對；不以明碼、Base64、MD5 或一般 SHA-256 儲存密碼。登入工作階段另保存密碼雜湊的版本指紋，僅用來使改密碼前的登入失效，不是密碼儲存演算法。

每次授權請求都核對版本與 12 小時期限。改密碼核對舊密碼、使用條件更新避免並行覆寫，更新 Session ID，其他舊登入在下次請求拒絕。此次升級前未綁定版本的 Session 需重新登入，既有密碼不變。已在處理中的請求不會回溯撤銷。

維持既有一次性五分鐘驗證碼、每來源位址五分鐘最多十次失敗、CSRF、參數化 SQL、HTTPS Secure/HttpOnly/SameSite=Lax Cookie。未登入 API 為 401，db/Git/原始碼的 HTTP 存取為 403。這是此站登入與資料存取範圍的驗證，不代表整台伺服器已做完整安全稽核。

SQLite 帳號與任務資料庫設為 `0660`，搭配既有具名 ACL 讓命令列帳號與網站帳號共用寫入；WAL/SHM 繼承主檔權限，避免交替執行時變成唯讀。原始建物庫只讀，網站私有目錄仍禁止 HTTP 存取。
