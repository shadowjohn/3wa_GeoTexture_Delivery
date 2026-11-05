# GeoTexture Delivery

E8 建物貼皮交付工作站。PHP 網站、SQLite 任務與帳號庫，不需 Composer、npm build 或資料庫伺服器。

目前完成的流程：登入 → E8 新北建物圖台 → 畫 Polygon → 建立任務 → 排程分批準備建物 → 另畫 Polygon 匯出成果包。

已接入 orthofacade 的單棟 CLI 試轉、Mapillary 真實影像及內嵌貼圖 GLB；目前仍在核對影像與建物的對位品質。worker 只準備轉換輸入，尚未自動呼叫試轉器。部分貼皮會保留缺面與品質警示，不算全棟完成。

公司續接請先讀 [單棟牆角校準交接](docs/calibration-next.md)；已保存單張原始全景與安全診斷資料包，可離線重現問題。單棟校準尚未完成。

## 本機啟動

PHP 8.2+，啟用 `pdo_sqlite`、`sqlite3`、`gd`、`zip`。網路需能存取 E8 CDN 及其底圖服務。

Windows 執行 repository 內的 `run.bat`，或：

```powershell
php -S 127.0.0.1:8088 -t public
```

Linux：

```sh
sh run.sh
```

開啟 [本機工作站](http://127.0.0.1:8088)。初始帳號／密碼為 `admin`／`admin`，搭配 GD 圖片驗證碼。登入後的「帳號設定」可修改密碼，變更後需重新登入。

這次工作區外層另有建物資料，Git repository 實際在 `D:\mytools\3wa_GeoTexture_Delivery\3wa_GeoTexture_Delivery`；請在有 `run.bat` 的這一層啟動。

## 資料與目錄

```text
public/                網站根目錄（Apache／IIS 均指向這裡）
src/                   PHP 幾何、來源、任務與登入
cli/                   一次性 worker、成果登錄
db/app.sqlite          自動建立；帳號、任務、逐棟狀態、成果索引
db/inputs/             排程準備的每棟轉換輸入 JSON
db/artifacts/          已登錄成果；GLB 經授權 API 讀取
db/.htaccess           Apache 拒絕直接存取 db 全目錄
db/web.config          IIS 拒絕直接存取 db 全目錄
snapshot/              介面參考與驗證截圖
tests/                 不依賴正式圖資的暫存 fixture 測試
```

來源預設 `../建物資料/F_新北建物8418.db`（唯讀、不複製、不提交 Git）。資料包含 460,840 棟，非空且唯一的 `build_id`、WGS84 WKT、建物高度與 R-tree。

可由環境變數覆寫路徑，例如：

```powershell
$env:GEOTEXTURE_SOURCE_DB = 'D:\mytools\3wa_GeoTexture_Delivery\建物資料\F_新北建物8418.db'
$env:GEOTEXTURE_STATE_DIR = 'D:\GeoTextureData\db'
php -S 127.0.0.1:8088 -t public
```

變更 state 路徑會使用該處自己的帳號與任務庫。不要讓 state 或原始圖資成為網站可公開下載的目錄。

## 圖台與任務

- 圖台以目前視野載入建物；縮小時暫停載入，每個視野最多顯示 2,000 棟，超限會提示放大。
- 「繪製 Polygon」後單擊加點，按完成或雙擊結束；可退一步，Esc 取消。
- 以 R-tree 先查候選，再精確判斷 Polygon 相交，保留凹形、洞與邊界語意。
- 每個任務最多 10,000 棟、Polygon 最多 500 頂點；查詢 bbox 每軸最多 0.25 度。超限拒絕建立／匯出，不靜默漏資料。
- 任務儲存 Polygon、估計面積、建物清單與來源版本；範圍與詳情可定位、查看前置建檔與貼皮成果進度，另可取消或重試。
- 面積採球面估計，供任務規模判讀，並非測量成果。
- 2D 以灰／黃／紅／青綠標示狀態。3D 的未貼皮建物採不透明淺灰白模及中性光，處理狀態只標示於屋頂輪廓；已有成果則載入實際 GLB，選取 Polygon 不會在建物表面覆色。

## 排程

先手動執行一批：

```sh
php cli/worker.php --batch=100
```

每次最多處理指定筆數，立即結束；檔案鎖避免同時執行。輸出 JSON 統計，錯誤回傳非零 exit code。建檔完成會變為 `awaiting_converter`，貼皮完成仍是 0。

Linux 部署示例（由管理員設定，這次沒有實際安裝系統排程）：

```sh
sudo install -d -o www-data -g www-data -m 0770 /srv/geotexture/db
sudo chown -R www-data:www-data /srv/geotexture/db
sudo chmod -R u+rwX,g+rwX,o-rwx /srv/geotexture/db
sudo -u www-data crontab -e
```

crontab 內容：

```cron
* * * * * cd /srv/geotexture && umask 0027 && /usr/bin/php cli/worker.php --batch=100 >> db/worker.log 2>&1
```

Windows 可用工作排程器呼叫 `php.exe`，引數 `cli/worker.php --batch=100`，起始位置設為 repository。IIS 的 App Pool 身分需具有 `db/` 修改權限；來源圖資僅需讀取。網站根目錄設定 `public/`，PHP FastCGI 由既有環境提供。`public/web.config` 已加入 `.mjs` MIME type。

`.htaccess` 需 Apache 允許對應的 `AllowOverride`；IIS 設定需有 URL Authorization／Request Filtering 支援。`public/` 作為網站根目錄時，`db/` 本身就在站台之外。部署後仍應實測 SQLite URL 取得 403／404；本次未部署正式 Apache／IIS。

## 單棟貼皮試轉

使用自己的 Mapillary Client Token，單行放入 `db/mapillary-token.txt`，不要提交 Git。引擎與 Python 依賴安裝、影像使用界線及驗證紀錄見 [orthofacade 接續紀錄](docs/orthofacade-integration.md)，資料契約見 [試轉契約](docs/orthofacade-pilot-contract.md)。

```powershell
.venv-orthofacade/Scripts/python.exe -X utf8 cli/orthofacade-pilot.py --job-id=JOB_ID --build-id=BUILD_ID --run=pilot-001 --candidate-limit=1 --tex-ppb=64 --inspection
.venv-orthofacade/Scripts/python.exe -X utf8 -m pipeline.glb_export db/pilots/pilot-001/pilot.json db/artifacts/pilot-001/building.glb
```

Linux 將 Python 路徑換為 `.venv-orthofacade/bin/python`。試轉每棟最多 6 張全景、每次最多 3 個候選；不以有一張貼圖就宣稱對位正確。已有街景仍可能因遮擋、稀疏 SfM、地面高度或全域對位誤差失敗。

Python CLI 使用自己的路徑參數，不會讀取 PHP 的 GEOTEXTURE_STATE_DIR／GEOTEXTURE_SOURCE_DB；自訂位置請加上 `--app-db`、`--source-db`，必要時另指定 `--token-file`。

`db/inputs/<版本雜湊>.json` 包含 `source_key`、`build_id`、`source_hash`、`recipe_key` 與原始建物 Feature。PHP／Python 轉換器可逐筆消費，再將可攜 GLB 放入 `db/artifacts/incoming/`。

以輸入 JSON 的實際識別與 hash 登錄結果：

```sh
php cli/register-result.php --source-key=new-taipei-8418 --build-id=BUILD_ID --source-hash=SHA256_FROM_INPUT --recipe-key=texture-v1 --path=pilot-001/building.glb --manifest-path=pilot-001/building.manifest.json
```

`--path` 與 `--manifest-path` 相對於 artifacts，不能跳出目錄。GLB 必須為 v2、自含 buffer／紋理資產、格式與大小有效；通過後另存為不可變檔案並記錄 SHA-256。模型採本地公尺 x東/y上/z南，E8 配置 Y-up、forward -Z、heading 0。

目前接受有可達 scene／node／未壓縮核心 mesh 的 GLB，可使用內嵌 BIN 或 data URI；稀疏 accessor、必要擴充套件明確拒絕。新管線附 manifest，檢查實際材質→紋理→影像、UV、圖片 hash/尺寸、逐面覆蓋與原始來源版本。這些結構檢查仍無法取代照片對位的目視驗收。

部分成果在任務顯示「部分貼皮」，有模型即可點「查看已貼圖建物」定位。獨立 GLB 預覽可旋轉模型，也可開啟匯出後的 GLB；新版 GLB 內含影像來源、授權與品質摘要。缺面保持白模，屋頂也計入必要面；底面不計，所有必要面覆蓋達門檻才算 complete。

重用條件為來源＋`build_id`＋`source_hash`＋`recipe_key` 相符，而且成果檔仍存在且完整。幾何／高度改變或 recipe 更新不會套用舊成果。強制重做不會刪掉舊成果，也不會直接把舊成果算作新任務已完成。

## 匯出

切換「成果匯出」再畫範圍。處理與匯出範圍各自獨立保存。ZIP 內容：

- `selection.geojson`：匯出 Polygon。
- `buildings.geojson`：精確選取的建物與狀態。
- `manifest.json`：來源版本、已完成／部分貼皮／缺漏、逐面品質、授權及模型 hash。
- `models/*.glb`：只有已登錄且完整的實際模型。

尚無模型時只會匯出資料及缺漏清單，沒有假貼圖。

## 驗證

```sh
php tests/geometry.php
php tests/app.php
php tests/auth.php
node tests/frontend.mjs
node tests/model-quality.mjs
node --check public/assets/app.mjs
node --check public/assets/map.mjs
.venv-orthofacade/Scripts/python.exe -m pytest tests/test_mapillary_coverage.py tests/test_glb_export.py tests/test_orthofacade_adapter.py -q
```

測試使用獨立暫存 SQLite，不修改正式圖資或帳號。實作與驗證續接紀錄在 [history.md](history.md)，需求在 [docs/design.md](docs/design.md)。

實機驗證、設計對照與截圖索引見 [docs/verification.md](docs/verification.md)。更換 CDN 或部署環境時，可參考 [E8 CORS 設定](docs/e8-cdn-cors.md)。
