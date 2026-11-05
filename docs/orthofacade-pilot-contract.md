# 單棟真實貼皮試轉契約

## 目標

用自己的 Mapillary Token、淡江大橋附近的真實影像及新北建物 build_id，經 orthofacade 產生至少一面有影像依據的紋理，組成可獨立開啟的內嵌貼圖 GLB，再回載 E8、隨範圍匯出。缺面保持白模，整棟只能標記部分貼皮，不假稱完成。

目前 121.412,25.1695,121.424,25.1815 查詢得到 91 張全景，91 筆皆有姿態與 SfM cluster；第一棟已產出真實影像 GLB 並回載 E8，但影像投影包含路面，僅為試轉，對位尚未通過。只試少量候選建物／影像，不直接抓整區。

## 共用輸入／輸出

`db/pilots/<run>/pilot.json` 是 adapter → GLB exporter 的契約，使用絕對檔案路徑僅限 CLI／本機；不直接提供 HTTP：

```json
{
  "version": 1,
  "source_key": "new-taipei-8418",
  "build_id": "原始建物ID",
  "source_hash": "PHP輸入中的原始SHA256",
  "recipe_key": "texture-v1",
  "engine_commit": "a24e4ca349a1ceecec5c64517e62b6828f4aebac",
  "feature": {"type":"Feature","geometry":{},"properties":{}},
  "origin": {"longitude":121.418,"latitude":25.175},
  "walls": [
    {"key":"穩定牆key","a_lonlat":[121.418,25.175],"b_lonlat":[121.419,25.175],
     "height_m":10,"tex_path":null,"status":"missing","tier":"D",
     "observed_share":0,"extent":{"s_l":0,"s_r":10},"views":[]}
  ],
  "attribution": [],
  "imagery_hash": "排序後影像與相機/SfM內容hash"
}
```

- `feature` 必須保留官方輪廓及實際高度，包含 Polygon／MultiPolygon 內洞。
- `walls` 包含成功與缺圖的牆。`status=textured` 只給實際存在且可解碼的 A/B 級紋理、有 observed pixels 的結果；其他標 missing/low_quality，不將 blocks 分類 alpha 當影像透明度。
- `height_m` 是官方模型高度；`extent.s_l/s_r` 為上游貼圖水平米座標，`h_bot/h_top` 必須是實際 resample rect（不是未取整的推估 height_used_m），圖片 row0 為頂。保存引擎品質，不為了出圖放寬門檻冒充成功。
- `attribution` 保存每個來源 image ID、作者（若 API 提供）、來源連結、CC-BY-SA 授權與變更記錄；不記 Token 或簽名 URL 作版本。

Exporter `pipeline/glb_export.py` 提供 `export_pilot(pilot_path, output_path) -> dict`，寫出 GLB 與同名 `.manifest.json`。回傳包含兩檔路徑、定位、counts。

Manifest 為：

```json
{
  "version":1,"source_key":"new-taipei-8418","build_id":"...","source_hash":"...",
  "recipe_key":"texture-v1","engine_commit":"...","imagery_hash":"...",
  "surfaces_total":5,"surfaces_textured":1,"walls_total":4,"walls_textured":1,
  "texture_status":"partial","model_sha256":"...","attribution":[],
  "longitude":121.418,"latitude":25.175,"altitude":0,"heading":0,"scale":1,
  "up_axis":"Y","forward_axis":"-Z"
}
```

每個牆 primitive 的 extras 保存 `wall_key` 及 `textured`；屋頂 primitive extras.kind=roof。封閉底面 kind=base、required_texture=false，不算需紋理的 surfaces_total。GLB 使用標準 Y-up、本地 x東/z南；E8 manifest up_axis=Y、forward_axis=-Z、heading=0，使模型北向對齊。屋頂未貼也算缺面，因此本期多半為 partial；全數必要面完成時 texture_status=complete。

同一來源牆可因裁切而有多個 primitives，但需依唯一 surface key 統計；不能重複計面。逐面 coverage 保存實際觀測與幾何覆蓋，所有必要面至少 95% 才算完整。影像缺洞保持白色，不能將 inpaint 補洞當真實觀測。單視角使用 fuse 前 RGBA，保留 raw mask。照片材質採 unlit 避免即時夜光染色，白模採中性 PBR。

GLB `asset.extras.geotexture` 保存安全的 build_id、texture_status、verification_status、attribution、wall_quality、engine_quality，讓獨立下載也附來源與品質。不得包含 Token、簽名 URL 或本機檔案路徑。`trial_unverified` 與 A/B tier 分開；A 級仍可能是錯誤對位。圖台與獨立預覽都需顯示待核對、整體對位、預設地面與缺點雲的警示。

## 後台接續

`register-result.php` 支援 `--manifest-path=...`（相對 artifacts 根目錄）。驗證 manifest 的 build/source/recipe/hash 與模型 SHA；只把真正材質鏈、UV、可解碼內嵌影像計為貼圖，不接受白模冒充。

model_json 保存 quality/texture_status、counts、axes、attribution。部分成果可回載與匯出，但 source.status 保持 queued，model.texture_status=partial，任務新增 partial count/status；completed 不增加。部分成果不當成全棟完成重用。既有無 manifest 的手動模型登錄契約先保持相容。

先以 CLI 完成單棟證據，再擴充 worker 自動派工，不先做無法驗收的整區自動排程。
