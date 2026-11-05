# 2026-11-06 第一版驗證

## 功能

- PHP fixture：Geometry 17/17、App 60 checks、Auth 25 checks；共 102 項。
- 前端控制器：重新繪製時禁止提交舊範圍；檢視任務 A 時不能誤匯出工作範圍 B；返回後顯示與匯出均為 B。
- PHP 全檔語法、JS 語法、UTF-8 無 BOM、git diff --check 通過。
- 隔離 HTTP：未登入 401、CSRF 403、建立 201、非法 Polygon 422、GD PNG、改密碼與登出、取消重試、ZIP、安全模型 404。
- 正式來源以 SQLITE3_OPEN_READONLY + query_only 查詢，不修改來源資料。
- 本機 public docroot 下 /db/app.sqlite、/src/Auth.php、/config.php 均 404。
- db/.htaccess、db/web.config 經靜態審查、XML 解析；未部署正式 Apache／IIS，不宣稱其伺服器流程已通過。

## 真實瀏覽器流程

使用 Codex 內建 IAB（非 Playwright CLI fallback）。使用者完成初次登入，已觀察授權後台頁；GD、帳密、改密碼流程另以隔離 HTTP 與 Auth 測試驗證，未變更實際 admin 密碼。

1. E8 CDN 啟動並載入新北建物，2D 與 3D 皆成功。
2. 實際點擊畫 Polygon，精確選取 90 棟、面積估計 56,416 m²，建立「淡江大橋北岸－流程驗證」。
3. worker --batch=30 只準備 30 棟，圖台顯示 30/90；第二次 --batch=100 準備剩餘 60 棟。
4. 完成後前置 90/90、貼皮 0/90、狀態「待轉換」，沒有假完成。
5. 切到匯出，另畫 Polygon 選取 66 棟；查看 90 棟任務時下載禁用，返回後恢復 66 棟。
6. 真實下載 ZIP，檢查含 selection.geojson、buildings.geojson、manifest.json；features=66、total=66、completed=0、missing=66。
7. 桌面 1440×900、目前面板約 957px 寬與行動版 390×844，無水平溢出。窄螢幕切換的 3D 重繪曾有空白，加入 ResizeObserver 後已重驗顯示正常。

瀏覽器暫時 viewport override 已清除，工作站 tab 與 PHP 本機服務保留。未額外安裝排程。

## 設計對照

參考圖為內建 Image Gen 產生的完整工作站概念，已由使用者回覆「看起來不錯」。`../snapshot/design-reference.png` 保留原始輸出；實作保持原生 HTML/CSS/JS，地圖由 E8 真實渲染。

本次使用 view_image 同一輪檢視概念與最新 `workstation-3d.jpg`，並實際檢查以下項目：

| 項目 | 參考方向 | 實作與驗證 |
|---|---|---|
| 主配置 | 左側操作、右側大地圖、底部狀態圖例 | 桌面 360px 側欄＋其餘地圖，長任務資訊於側欄捲動 |
| 配色 | 白／淺灰、青綠主色、細分隔 | #087f86，狀態灰／黃／紅／青綠；3D 光照影響外觀色度 |
| 字型 | 正體中文無襯線、清楚操作階層 | 系統字型，按鈕/欄位明確字級；側欄 12–15px、標題 18px、品牌 25px |
| 控制與文案 | 處理任務／成果匯出、繪製 Polygon、建立任務 | CTA 與模式保留，增加必要的退一步／取消／返回框選與帳號功能 |
| 容器 | 平面表單、少量框線、無卡片堆疊 | 一致 5–6px 圓角、摘要表與扁平任務列 |
| 地圖 | 淡江大橋附近、建物與 Polygon | 使用實際 E8 底圖、真實 90 棟範圍與狀態，無假地圖圖片 |
| 響應式 | 地圖為主要操作面 | 行動版地圖在上、表單在下，390px 實測無水平溢出 |

首屏文案比對：品牌、兩模式、核心 CTA 均保留。增加的帳號設定／登出、強制重做、檢視期間提示，是使用者補充或避免誤操作所需；即時筆數、面積、進度依真實資料更新。未增加行銷區、裝飾徽章或假統計。

刻意差異：使用真實 SDK 底圖取代概念生成地圖；工程工作站採較緊湊的字級，增加登入與完整任務操作。概念圖原始尺寸為生成器決定，實作原生桌面驗證為 1440×900，另測預設與行動版尺寸，未硬把地圖資料當成像素素材重現。

已對照驗證主要配置、字型、配色、間距與控制，無尚待修正的實質視覺阻擋；上述差異為真實功能與資料帶來的預期差異。

## 截圖

- `snapshot/design-reference.png`：概念參考。
- `snapshot/login-desktop.jpg`：GD 登入。
- `snapshot/account-desktop.jpg`：改密碼頁。
- `snapshot/task-progress-partial.jpg`：30/90 分批進度。
- `snapshot/export-desktop.jpg`：獨立匯出完成。
- `snapshot/workstation-3d.jpg`：最新 1440×900 真實 3D／任務。
- `snapshot/workstation-mobile.jpg`、`workstation-mobile-3d.jpg`：行動版。
- `snapshot/workstation-current.jpg`：恢復預設 viewport 後的工作站。

## 審查修正與限制

獨立代理唯讀審查已完成。空 meshes 可被當成果的問題以可達 scene/node/mesh、accessor/view/buffer 與實際 POSITION/indices 資料檢查修正，先 RED 再 GREEN；範圍 A/B 問題同樣有回歸。

真實照片校正／貼皮轉換器、完整 Khronos glTF Validator、正式 Apache/IIS 與系統 crontab 不在本階段交付內。GLB 支援範圍見 README，不支援的壓縮／稀疏／必要擴充不會標完成。沒有額外定案的待辦 minor finding。

## 2026-11-06 真實貼皮試轉增補

後續已接入 Mapillary＋orthofacade CLI，結果以 history.md 最新紀錄為準。三棟試轉中一棟產生 GLB，但對位包含路面、尚未目視驗收；另兩棟在本次影像下無通過門檻的立面。沒有以 A tier 或 CLI exit 0 當成對位正確。

新增獨立 viewer、partial／trial_unverified、圖片與覆蓋 manifest 檢查、E8 固定日照及任務定位。真實瀏覽器 ZIP total2/completed0/partial1/missing1，取出 GLB hash 相符，在無 asset_id 的 viewer 成功開啟；作者、授權與品質隨檔保留。390×844 與桌面完成驗證，viewport 已重設。

- `snapshot/pilot-texture-map.jpg`：E8 真實材質及待核對提示。
- `snapshot/pilot-texture-export.jpg`：ZIP 取出後的獨立 GLB。
- `snapshot/pilot-texture-mobile.jpg`：行動版 GLB、來源與品質。

本輪 geometry17／app104／auth25、Python30＋5 subtests、frontend/model-quality 回歸及 PHP/JS lint 通過。仍未完成可靠立面對位、自動引擎排程或正式 Apache/IIS 部署。
