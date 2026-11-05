# 公司續接：單棟牆角校準

使用者已選「先校準一棟，再研究自動化」，隨後要求先 Git push，到公司繼續。本次保存可重現的程式與證據，尚未完成校準，也沒有把錯誤貼圖當作驗收成果。

## 從這裡開始

Repository 分支為 `codex/php-map-task-mvp`。先讀本文件及 history.md 最新兩節，再看 [最小資料包](../snapshot/calibration-001/README.md)。資料包只有一張來源全景、官方單棟幾何、相機、牆與診斷控制點；可重現問題，不需要 Token、完整原始建物庫或家用機器的 db/。

Python 3.12 與固定依賴的準備步驟見 [引擎接續紀錄](orthofacade-integration.md)。若要啟動整個 PHP 圖台，仍需另外提供官方建物資料庫（預設 ../建物資料/F_新北建物8418.db），或設定 GEOTEXTURE_SOURCE_DB；這個553MB來源及本機app.sqlite沒有上傳。

Mapillary Token 也沒有上傳。離線校準資料包不需要 Token；後續新增街景時再於公司機器設定自己的本機 Token。Apache/IIS 網站根目錄仍為 public/，狀態目錄的保護檔已納入版本。

PHP 的 GEOTEXTURE_STATE_DIR／GEOTEXTURE_SOURCE_DB 不會自動套用到 Python pilot CLI。若使用自訂位置，呼叫 cli/orthofacade-pilot.py 時請明確指定 --app-db、--source-db，必要時另指定 --token-file。離線校準資料包不使用這些資料庫。

## 已證實的問題

- 建物2R8HS7FQLB、官方高2.97m，照片1709407170479969。現有貼圖含路面，僅 partial/trial_unverified。
- 近距地面 SfM 點為0，fallback相機高度2.5m；ground edge窄窗選到柏油線。真牆腳不在搜尋窗內。
- 未額外配準相機與global相機都會錯貼；原官方輪廓 winding／外法線、SfM公尺尺度已驗證正常。
- 兩組同柱照片射線配官方高度，推得相機離地約1.79/1.80m、距牆約10.59/10.82m；現投影使用離地約2.31m、距牆約4.5m。
- 以上柱線尚未對應到可信官方牆角，沿牆位置不可識別。只改高度／距離會移到別段店面或車輛，不能稱為修好；也尚未證實檐口正好等於官方2.97m高度。

## 下一個驗收切片

1. 核對這張照片的實際建物與官方 footprint；標定可辨認的官方頂點和對應照片牆角。不要把現有兩組診斷柱線當作已核對的控制點。
2. 固定可信 SfM axes 時，用同一已知牆角的牆腳、已知高度屋頂射線求相機位置；另用第二牆角作獨立投影誤差檢查。若需同時調姿態，使用至少四個具可信三維位置的立面控制點。
3. 校準結果保存 image SHA、build_id/source_hash、控制點與求解參數／殘差，再從原全景重新取樣。影像的地面、天空、車輛遮擋不算有效牆面。
4. 檢查立面比例、門窗方向及獨立驗證點，再輸出到新的 artifacts 子目錄。不要覆寫已登錄 GLB。
5. 回載 E8，再框選 ZIP、獨立開啟 GLB 核對。只有一面牆貼好仍是 partial，屋頂／缺面保持白模。人工校準須保留來源語意，不能冒稱已完成全自動對位。

## 已完成的程式驗證

PHP geometry17、app104、auth25；Python30 passed＋5 subtests；frontend、model-quality回歸通過。這些測試驗資料與管線，不代表真實照片已對位成功。正式crontab自動呼叫引擎、Apache/IIS部署及可靠外牆校準都還沒有完成。
