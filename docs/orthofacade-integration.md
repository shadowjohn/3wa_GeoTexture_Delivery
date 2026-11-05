# orthofacade 接續紀錄（2026-11-06）

使用者明確指出應接回先前評估的自動貼皮引擎，而非止於白模、改色或從零製作手動照片工具。

## 已核對的上游

- Repository：https://github.com/louis-e/orthofacade
- 調查 main commit：`a24e4ca349a1ceecec5c64517e62b6828f4aebac`。
- 程式 LICENSE 為 Apache-2.0；影像與地理資料授權須另存來源紀錄。
- CPU only，無 ML 模型；Mapillary 全景／相機參數／SfM 點雲＋OSM 建物輪廓。
- 流程為 fetch、geometry、align、texture、sheet。
- 輸出每牆 PNG、幾何／品質 JSON 及檢視頁；沒有 GLB exporter，也無原生 GeoSQLite／Google Street View／WMTS provider。

程式已核對 README、LICENSE、prefetch.py、geo.py、export.py、run.py、tests，並固定在上述 commit。已產生淡江大橋周邊第一棟真實影像 GLB 試轉，影像對位仍須核對，不能直接視為完成品。

## 第一個驗收切片

用淡江大橋周邊一棟有實際 Mapillary 全景覆蓋的建物：

```text
既有建物 build_id + footprint + build_h
→ 發現 Mapillary 影像、相機與 SfM 覆蓋
→ 凍結影像清單與 hash
→ orthofacade 校正、融合，得到真正的逐牆紋理
→ UV + 內嵌貼圖 GLB
→ 成果登錄
→ E8 顯示、ZIP 匯出
```

先驗證一棟，再接批次；沒有影像的牆標記缺圖，不用純色或程序化材質冒充實景。

## 介接結果與剩餘工作

1. 已完成官方建物 adapter：以 Overpass-shaped cache 轉入 Building/Wall，保留官方 Polygon、build_id、build_h 與來源 hash，沒有用 bbox 內 OSM 建物取代原任務。
2. 已隔離每個 run 的快取與輸出，逐棟選附近影像，按 image ID 共用下載。沒有直接執行會共用 script/cache 的上游 fetch stage。
3. 已完成內嵌照片 GLB exporter。blocks PNG 的類別 alpha 不用作照片透明度；單視角使用補洞前 raw mask，多視角有補洞且無真實觀測 mask 時保守拒絕貼面。
4. 已加入 partial／trial_unverified、逐面覆蓋與品質記錄；exit 0、A tier 或存在 PNG 都不能取代真實對位驗收。目前仍未校準成功。
5. 登錄已驗 material→baseColorTexture→texture→image、UV、內嵌影像解碼與 hash/尺寸、來源及逐面 manifest。結構檢查不保證影像對應正確，還需要牆角校準與目視／獨立點驗證。
6. 引擎 commit、影像內容／相機／SfM hash 已存入成果。完成品重用仍依 source_hash／recipe_key；部署不同引擎或 adapter 配方時須更新 recipe_key，尚未自動推導完整配方版本。簽名 URL 不作版本鍵。

## 影像來源界線

- WMTS 航照適合屋頂，無法從俯視正射影像還原完整外牆；匯出圖磚紋理仍需確認該服務的重製／交付授權。
- 此引擎現成來源是 Mapillary，不是 Google 街景。Google Maps Platform 一般條款限制抽取、快取與衍生成果，不直接把 Google 街景下載後包進可交付 GLB。
- Mapillary 影像為 CC-BY-SA，需保存影像／作者／授權與變更註記；資料授權與我們 PHP 程式授權分開管理。

來源：[上游 README](https://github.com/louis-e/orthofacade/blob/main/README.md)、[Mapillary 影像授權](https://help.mapillary.com/hc/en-us/articles/115001770409-CC-BY-SA-license-for-open-data)、[Google 條款 §3.2.3](https://cloud.google.com/maps-platform/terms)、[NLSC WMTS 常見問題](https://maps.nlsc.gov.tw/S09SOA/pro/faq_ajax_list.jsp)。

## 目前設定

使用者已自行建立 Client Token，存於本機 `db/mapillary-token.txt`；實測 metadata 查詢成功。正式批次不用上游內附 demo token。

官方流程是建立 Mapillary 帳號、登入、在 Developers 註冊應用程式、取得 Client Token。只需讀取公開資料，不需要 Write／Upload 或 Client Secret。Token 不貼聊天、不提交 Git；後續透過本機設定交給 worker。

上游有合成幾何／全景／點雲測試；沒有附 Munich 原始 cache，所以離線測試不能取代淡江大橋真實覆蓋與貼圖驗證。Python 3.12 隔離 venv 已安裝 CPU 依賴；上游 geo/rectify/blocks 離線測試 28 passed、5 skipped（缺 Munich cache）。

重新準備環境：

```sh
git clone https://github.com/louis-e/orthofacade vendor/orthofacade
git -C vendor/orthofacade checkout --detach a24e4ca349a1ceecec5c64517e62b6828f4aebac
python -m venv .venv-orthofacade
.venv-orthofacade/Scripts/python.exe -m pip install -r requirements-orthofacade.txt
```

Linux 改用 `.venv-orthofacade/bin/python`。若既有 venv 沒有 pip，可先以該 venv Python 執行 `-m ensurepip --upgrade`。來源建物 SQLite 支援中文路徑；上游 OpenCV 原生檔案 I/O 在 Windows 中文路徑會失敗，因此 pilot/cache 使用 ASCII 路徑。UNC 尚未實測。上游程式不修改；Token、完整快取、venv、下載的引擎均不提交 Git。公司續接用的單張全景與安全診斷另置於 snapshot/calibration-001，保留來源與 CC-BY-SA 授權，不含 Token。

## 覆蓋查詢工具

`cli/mapillary-coverage.py` 使用 Python 標準庫，先抽樣最多 100 筆全景中繼資料，不下載照片、不跟隨分頁、不使用 demo Token。Token 讀取順序為 `MAPILLARY_TOKEN`，其次 `db/mapillary-token.txt`（單行 Client Token，db 已排除 Git 並禁止 HTTP 存取）。Token 經 Authorization header 傳送，不寫入 URL 或輸出 JSON。

取得 Token 後可先執行：

```powershell
python -X utf8 cli/mapillary-coverage.py --bbox=121.412,25.1695,121.424,25.1815
```

輸出有影像筆數、相機資料筆數、SfM cluster 筆數、部分 image IDs 與是否還有分頁。抽樣有影像不等於可貼滿每面牆；需要下一步實際下載、校正與品質判讀。

本工具的三項單元測試先 RED 再 GREEN，涵蓋範圍驗證、自有 Token 來源、只查 metadata 與 Token 不進 URL。尚無 Token 時會明確回報設定缺失，不進行 HTTP 請求。

實測指定 bbox 回傳 91 張全景，91 筆都有姿態與 SfM cluster，沒有分頁。三棟試轉共下載 8 張 6080×3040 全景與 3 個 SfM cluster；整區有影像不等於能貼滿外牆。單棟校準續接見 [交接文件](calibration-next.md)。
