# 程序化立面第一版

以下保留初始設計範圍。後續依使用者要求已加入每分鐘自動排程、AJAX 進度及預設 3D 的分批預覽；目前操作與限制以 [程序化風格指南](../../procedural-styles.md) 為準。

使用者已確認：沿用真實輪廓、高度，提供多種示意風格；真實影像流程並存。

- 三種版本：procedural-tw-v1、procedural-modern-v1、procedural-pastel-v1；保留 texture-v1。
- 同棟由 source_key/build_id 與配方決定穩定 seed。依公尺排列窗戶，保留 Polygon、MultiPolygon、內洞及屋頂。高度無效時明確失敗，不捏造官方高度。
- 使用現有 Python GLB 幾何工具、內嵌 PNG、Y-up/Z-south；不使用外部圖像或 World Gacha 素材。
- 每一模型有獨立 procedural manifest，標示 synthetic/procedural、樣式版本、來源版本與 hash。實景品質驗證維持原規則，不能將示意覆蓋率說成照片觀測率。
- API recipe_key 為可選白名單參數，省略仍為 texture-v1。建立任務、查詢地圖、框選與匯出均按同一配方隔離。
- 現有 worker 的檔案鎖與取消檢查繼續使用，程序化配方呼叫受控 Python CLI，再經既有登錄程序保存不可變模型。
- 網頁可選風格、檢視任務風格、手動執行小批次；不依賴新增系統排程。每次最多 10 棟，續跑由使用者按鈕觸發，CLI 可處理較大批次。
- 首次預覽可框選少量建物產生後切 3D，同一範圍改選其他風格建立另一個任務即可比較；匯出明確標示選定風格。
- 保留登入、CSRF、私有成果存取與既有帳密。新 PHP mode 0755。所有測試採暫存 state，不修改正式帳號。

不包含：城市來源切換、夜景照明、輪廓後製、幾何陽台、全市批次、大規模圖磚打包。本版仍為逐棟自含 GLB，不能宣稱不同 GLB 已共享 GPU 材質。
