# PHP 圖台與區域任務 Implementation Plan

> **For agentic workers:** 依模組分工，先固定介面、再分別驗證與整合。

**Goal:** 完成 E8 Polygon 選取、新北建物、持久化任務、有限批次前置 worker 與獨立範圍匯出。

**Architecture:** PHP 網站加 SQLite 任務庫，唯讀使用既有 GeoSQLite。E8 CDN 與原生 JS，不引入前端框架；轉換器下階段接。

**Tech Stack:** PHP 8.2+（pdo_sqlite/zip）、E8 preview.5、HTML/CSS/JS。

**Spec:** `docs/design.md`

## Global Constraints

- 原始建物資料唯讀且不納 Git；不覆蓋使用者修改。
- 沒有實際成果不得標示貼皮完成；已完成成果必須存在且 hash 相符。
- 使用正體中文，API 與狀態語意以設計文件為準。

## Review Focus

- Polygon 的洞、邊界及凹形不能以 bbox 替代。
- 查詢上限不可靜默建立不完整任務或匯出。
- 原始資料不可寫入，成果不存在不可計入完成。
- 取消任務不可由 worker 復活；重疊任務不重複成果。
- CDN 失敗、缺資料及窄螢幕需有可理解的狀態。

### Task 1: 唯讀來源與精確範圍

- [x] 在 `tests/geometry.php` 建立幾何與暫存 SQLite fixture 測試並確認缺實作時失敗。
- [x] 實作 `src/Geometry.php`、`src/BuildingSource.php`，遵循 Spec 介面。
- [x] 執行 `php tests/geometry.php`，再用真實資料作範圍 smoke check。

### Task 2: 任務、批次與匯出

- [x] 在 `tests/app.php` 寫任務持久化、取消/重試、批次不假完成、重用及匯出測試，確認 RED。
- [x] 實作 `src/App.php`、`src/bootstrap.php`、`public/api.php`、`cli/worker.php` 與成果登錄契約。
- [x] `php tests/app.php` 與 HTTP API smoke check。

### Task 3: E8 工作站

- [x] 實作 `public/index.php`、`public/assets/`，支援 Polygon、選取摘要、任務建立/詳情/定位及匯出。
- [x] 製作本機啟動器、README 與 history.md。
- [x] 真實瀏覽器驗證 CDN 地圖、範圍、任務與匯出；檢查 JS/PHP 語法與 git diff。

### Task 4: 整合審查

- [x] 獨立代理審查來源唯讀、精確範圍、任務進度與匯出。
- [x] 修正實質問題，重跑必要測試，記錄限制及下階段轉換器介面。
