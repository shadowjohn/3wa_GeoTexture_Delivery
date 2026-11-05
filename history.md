# 開發紀錄

## 2026-11-05：第一版 PHP 圖台、任務與匯出

### 已確認範圍

使用 PHP 網站＋E8 CDN，新北市建物為來源；Polygon 建任務，crontab 分批處理，另畫範圍匯出。圖台需辨識未處理／排隊／失敗／已有成果，已有真實模型可顯示貼皮外觀。同棟以 build_id 及版本重用。這階段先完成圖台、範圍、任務，真正貼皮轉換器下一步接。

後續加入 admin/admin 初始登入、GD captcha、改密碼；SQLite 集中 db/，Apache .htaccess 與 IIS web.config 保護整個資料目錄。參考與驗證圖片放 snapshot/。

### 工作區與資料

外層 `D:\mytools\3wa_GeoTexture_Delivery` 不是 git；真正 repo 為內層同名目錄，初始 commit e2b0c8a。開始已有 LICENSE 刪除，外層存在 LICENSE，保留原狀。新增內容在 codex/php-map-task-mvp，尚未提交或推送。

來源 `../建物資料/F_新北建物8418.db` 約 553 MB，460,840 個唯一且非空 build_id。主表 [8418]，ogc_fid 對應 idx_8418_GEOMETRY.pkid。EPSG:4326、GEOMETRYCOLLECTION(POLYGON)，有內洞、有效高度，直接讀 GEOMETRY_WKT，不需 SpatiaLite extension。

### 已實作

幾何與唯讀來源、持久化任務與逐棟輸入版本、有限批次 CLI、取消重試、成果登錄與完整性檢查、範圍 ZIP、登入與一次性 GD 驗證碼、E8 圖台與範圍介面。公開目錄為 public/。

### 重要語意

- worker 只準備 db/inputs，不會轉模型、呼叫 AI 或取得照片。
- prepared 代表前置建檔；completed 只代表真實成果，兩種進度分開。
- 同棟重用還需來源版本、recipe、檔案存在與完整性相符。
- 匯出 Polygon 與處理 Polygon 獨立；超量不允許靜默截斷。
- 沒有部署或安裝系統排程。Apache／IIS 防護仍需部署環境實測。

### 驗證紀錄

2026-11-06 實測與整合完成：geometry 17、app 60、auth 25，共 102 項 PHP 檢查通過；另有前端範圍狀態回歸、PHP/JS 語法與 git diff --check 通過。獨立審查發現空 mesh 假成果與任務檢視/匯出範圍不一致，皆補 RED→GREEN 回歸修正。

CDN 初次因 ES module 缺 CORS 失敗，使用者已在 3wa.tw 的 easymap8/ 加入標頭，runtime／bridge／Three 實測 HTTP 200 + Access-Control-Allow-Origin:*，圖台成功。

真實瀏覽器在淡江大橋北岸畫 Polygon 建立「淡江大橋北岸－流程驗證」90 棟任務，worker --batch=30 後為 30/90，再跑 --batch=100 完成剩餘 60，狀態待轉換、貼皮 0/90。另框 66 棟成功下載 ZIP，GeoJSON/manifest 皆 66、completed=0、missing=66。此驗證任務與 90 筆 inputs 保留在本機 db/，未納 Git。

桌面 1440×900、目前面板寬度與 390×844 行動版已檢查，補上容器 ResizeObserver 與重繪。截圖在 snapshot/；本機 PHP 服務保留於 http://127.0.0.1:8088，登入 admin/admin 未變更。

後續從轉換器開始：逐筆消費 db/inputs，產生具照片來源與版本的 GLB，透過 cli/register-result.php 登錄。尚未執行實際貼皮、系統 crontab、正式 Apache/IIS 部署或 Git commit/push。

## 2026-11-06：修正 3D 色罩，接回 orthofacade 方向

使用者指出 3D 土黃半透明外觀難看。原因為整棟狀態色＋extrusion opacity=0.9，加上 SDK 即時夜間燈光與 above3D 選取填色。已改成 opacity=1 的淺灰白模、固定中性 MapLibre 光，狀態改用 line3d 屋頂輪廓，3D 隱藏選取填色。保留 2D 狀態色及既有 GLB 材質；屋頂線涵蓋洞/MultiPolygon、按完整環分成最多 80k positions 的圖層，切回 2D 會移除。

IAB 實測同一筆 90 棟任務，桌面與 390×844 皆顯示不透明白模，底圖文字不再透進牆面；2D⇄3D 切換正常，console 無相關 error/warn。JS syntax、既有 frontend 回歸、index.php lint、diff check 通過。前後照片為 snapshot/3d-material-before.jpg、3d-material-after.jpg、3d-material-mobile.jpg。

使用者接著澄清期待實際影像貼皮，並指出原討論已評估 orthofacade，影像可考慮 WMTS／街景。已找到原專案 https://github.com/louis-e/orthofacade ，使用 Mapillary 街景、OSM footprint 與 SfM 資料產生立面貼圖，CPU only。接續應先查現成引擎輸入輸出、來源覆蓋，再適配新北 build_id 與 GLB 組裝，不能把白模改色當作完成貼皮。目前尚未整合該引擎，尚無真正轉換成果。

## 2026-11-06：真實 Mapillary／orthofacade／GLB 試轉

使用者已自行建立 Client Token，單行存入 db/mapillary-token.txt（受目錄保護且 Git ignored），不用上游 demo token。metadata bbox=121.412,25.1695,121.424,25.1815 得 91 張全景、皆有 pose/SfM cluster。引擎 pin a24e4ca349a1ceecec5c64517e62b6828f4aebac，vendor clean；Python 3.12 隔離 venv 已安裝 CPU 依賴，離線上游測試 28 passed／5 skipped（無 Munich cache）。

已完成官方新北 footprint/build_id/hash adapter、每棟少量影像選取與快取、真實立面校正、內嵌 PNG GLB、manifest 驗證登錄、E8 回載、Polygon ZIP、獨立 GLB viewer。上游預期的 OSM cache 只作介面封裝，實際輪廓與高度來自官方 SQLite，原來源唯讀；不以 bbox 的 OSM 建物取代原任務。

實跑三棟，共下載 8 張 6080×3040 原始全景與 3 個 SfM cluster，結果：

- 2R8HS7FQLB（官方高 2.97m）：一個上游立面拆對原始兩條邊，官方必要面 2/10 有貼圖、外牆 2/9，牆面覆蓋 25.16%。64px/m 從原圖重取樣為 512×256，使用 single-view 補洞前 raw alpha，非放大舊 64×32 圖。影像仍包含柏油／黃線，global shift 約10.15m、0 local registration、地面採 default、無 cloud plane；目視驗收不通過，明確標 partial／trial_unverified。
- 2R9987FQRG：該次沿用的 5 張影像下，12 個引擎牆皆 D、0 可用面，不代表整區沒有影像。
- 2R7JT7FT3G（官方高 23.59m）：建立單棟凍結任務 15e1b5e6411ddd39ec583764，依自己位置選最近 3 張及 2 個新 cluster，4 個引擎牆皆 D、0 可用面。沒有匯出／登錄白模。本輪不再擴候選。

第一棟正式試轉檔：db/artifacts/tamsui-pilot-001-hires/building.glb（166,712 bytes，SHA256 0e9d0f5515bed8dec1f2ba54b812218e19b001519c3eabd9df48fdedb6b7e387）；登錄 asset b3a9322c70c94cd2f23ce0b2cf0bb5a7。舊 pilot-001 及 7f619ec5c550034f419991d986a1ada1 不再修改。GLB 內保存 Mapillary 作者／CC-BY-SA／變更及品質摘要，不含 Token、簽名 URL、本機路徑。完整三棟結論在 db/pilots/tamsui-pilot-001-hires/round-report.json。

partial 可顯示與匯出，但不算 completed、不當全棟成果重用；trial_unverified+complete 直接拒絕。登錄檢查真實材質鏈、UV、圖片可解碼/hash/尺寸、唯一來源面與覆蓋率；封閉底面不算必要面，不能以小貼片或零面積面冒充完成。這些結構檢查不保證對位正確。

實測修正：上游 render rect 高度取整，adapter 改讀實際 rect，不能拿 height_used_m 推估值當 UV 範圍；single-view 保留 inpaint 前 mask，多視角有補洞卻無可靠觀測 mask 時保守標 low_quality；CLI 每個候選各選自己的附近照片，再按 image ID 共用快取。獨立審查抓到後兩項並補回歸。

圖台新增任務「查看已貼圖建物」、部分面數、待核對與來源資訊。E8 公開 setEnvironment 固定白天，再設中性 extrusion 光，避免 PBR 缺面在夜間變深藍；照片材質 unlit 保持原色，白模用 PBR。獨立 viewer 支援旋轉／縮放／本機 GLB、來源品質、換檔非同步序號及 viewport 重配。

真實瀏覽器框選 2 棟下載 ZIP：total2/completed0/partial1/missing1，模型 SHA 與上述一致；取出 db/verification/pilot-export-001.glb，用無 asset_id 的 viewer 開啟成功，照片、警示、作者授權均保留。桌面與390×844實測，無相關 console error/warn。snapshot/pilot-texture-map.jpg、pilot-texture-export.jpg、pilot-texture-mobile.jpg 為本次證據。

最終驗證：geometry17、app104、auth25；Python30 passed＋5 subtests；frontend 與 model-quality 回歸、全部 PHP lint、JS syntax、diff check 通過。原有 LICENSE 刪除仍保留；未 Git commit/push。Token／簽名下載資訊在 pilot JSON/log 掃描均0命中。

目前圖台是可驗證的試轉流程，可靠外牆對位尚未完成。下一步聚焦相機／官方輪廓 XY 及地面高度校準，不能靠任意 UV 裁切隱藏路面。worker 仍僅準備 inputs，沒有自動呼叫引擎、安裝 crontab 或正式 Apache/IIS 部署。原90棟任務 prepared90/completed0/partial1；第三棟任務已前置建檔1/completed0，仍待轉換。

## 2026-11-06：追查路面錯貼

使用者指出連地面都貼入。重新依原始全景、相機、點雲、渲染 rect 與上游程式逐層診斷，沒有將路面裁掉冒充校正。

已排除官方 ring winding：三棟轉接前後幾何差異0，geo 正規化 CCW，21條牆的±10cm法線探針皆正確。上游 NO_CAMERA_IN_FRONT 也涵蓋入射角／距離不過，不全是背面。SfM metric ratio=1.00000000458，沒有兩倍尺度的單位轉換錯誤。

五台相機2.5–8m環帶下方 SfM 點皆0，全部回退相機高2.5m；無牆腳支持點。refine.ground_row 未使用傳入 horizontal segments，僅在初始地面±0.75m取Sobel強峰，選中 h=.19373m 的柏油／黃線；真牆腳目視約在render h1.34–1.64m，完全在窗外。擴窗後仍有多個路面／屋簷／樹冠強峰，無法唯一辨識。高度先驗與不同邊對也有多個合理相機／深度解，不能直接寫成果。

固定相同官方高2.97m、z_base=.4839866、wall/axes，直接從原6080照片分別用未額外配準C與global C重投。兩者仍約半幅路面；global沿牆移10.109m，但法向只改.935m，所以單純取消global不是充分修復。比較圖與數值在 db/diagnostics/sfm-ground-001/；完整地面診斷與可重跑script在 db/pilots/tamsui-pilot-001-hires/diagnostics/ground-diagnosis.md。

此時未修改產品程式／已登錄 GLB、未抓新照片。已向使用者詢問：先以可信牆角控制點校準單棟，再研究自動化；或維持全自動、無可靠對位即跳過並換合適影像。等待此路徑偏好；不能把目前單張梯度偵測稱為可靠自動貼皮。

額外以原全景兩組同柱屋簷/牆腳像素及官方高2.97m反算：A得相機離地1.7866m／法向距10.590m，B得1.8018m／10.823m，兩ray閉合誤差分別2.5mm／8.1mm。現投影離地2.306m／距牆4.563m，顯示高度與深度耦合偏差。但柱線尚未對應到可信官方牆角，沿牆位置仍不可識別。比較重投圖 manual-ray-proof.png 中，只調高度仍有路面；調距離＋高度後沿牆落到卡車／另一段店面，不能當作修好或登錄。全部只存db/diagnostics，產品與既有GLB未動。要正確對build_id，需要可信牆角對應及第二角點驗證，或可靠跨視角／地面牆面幾何支援。

## 2026-11-06：Git checkpoint 與公司續接

使用者已選定先以照片牆角控制點校準一棟、驗證正確貼皮，再研究自動化；接著指示先 git push，到公司繼續。此 checkpoint 保存目前MVP、引擎介接、驗證與診斷，不把尚未完成的單棟校準宣稱完成。

續接分支 codex/php-map-task-mvp。首先讀 docs/calibration-next.md，再看 snapshot/calibration-001/README.md：最小包包含一張6080原全景、單棟官方幾何／牆／相機／診斷控制點、授權資訊及離線可重跑script；不是已校準的產品模型。照片來源／CC-BY-SA與幾何資料來源分別記錄。

Mapillary Token、本機app.sqlite、553MB官方來源、完整照片快取、venv與vendor均排除提交。可攜資料包允許公司端無Token／無原始DB先重現問題；要啟動全區PHP圖台仍需提供原始DB，重新準備venv／固定引擎。requirements-orthofacade.txt保存本次Python3.12實測依賴版本。

本輪重新執行 geometry17／app104／auth25、Python30＋5 subtests、frontend/model-quality、PHP lint／JS syntax皆通過。既有LICENSE刪除不納入本次提交。尚未校準成功、尚未接全自動引擎排程、未正式部署Apache/IIS。
