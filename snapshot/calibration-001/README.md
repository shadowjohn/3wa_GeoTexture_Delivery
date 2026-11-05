# 單棟相機校準診斷資料包

此包僅重現「貼圖帶入路面」的人工控制點診斷。**尚未修好，也不是完成品。**

照片柱線還沒有對應到官方建物的可信牆角，沿牆平移仍無法決定；右側比較圖雖減少路面，也移到了卡車及店面左側。不能據此宣稱照片屬於 `2R8HS7FQLB`，或直接產生、登錄校準模型。

## 內容

- `source.jpg`：Mapillary image `1709407170479969`，原始 6080×3040 JPEG；逐位元保留。
- `inputs.json`：官方單棟 Feature／高度、原始與 global 相機、來源牆面、配準與地面推估摘要、兩組初估像素控制點。
- `attribution.json`：來源、作者、CC-BY-SA-4.0 與診斷修改記錄。
- `manual-ray-calibration.py`：相對本目錄讀取資料；只需 NumPy、Pillow、OpenCV，不需要資料庫、原 SfM 點雲、上游 vendor 或網路服務。
- `proof-comparison.png`：原設定／只改相機相對高度／改垂牆距離與高度的重投影比較。
- `control-pixels.png`、`summary.json`：人工控制點與反算結果。

照片與衍生診斷圖依 `attribution.json` 標示為 CC-BY-SA-4.0。單棟幾何另外記為使用者提供的 `F_新北建物8418.db` 摘錄；來源授權尚未確認，**不將照片授權套用到官方幾何**，也不附完整資料庫。

原圖 SHA256：

```text
097fde62a10c7fe9d229af7635a36b3529fccdd593abf60f08fba86ce58c2707
```

## 重跑

在 repository 根目錄，用既有隔離環境執行：

```powershell
& '.\.venv-orthofacade\Scripts\python.exe' '.\snapshot\calibration-001\manual-ray-calibration.py' --out '.\db\diagnostics\calibration-001'
```

公司電腦若沒有該環境，建立獨立 Python 3.12 venv；不要安裝到全域：

```powershell
py -3.12 -m venv .venv-orthofacade
& '.\.venv-orthofacade\Scripts\python.exe' -m pip install -r '.\snapshot\calibration-001\requirements.txt'
& '.\.venv-orthofacade\Scripts\python.exe' '.\snapshot\calibration-001\manual-ray-calibration.py' --out '.\db\diagnostics\calibration-001'
```

`--out` 是新診斷檔的輸出位置，可指定任意可寫目錄。腳本以自身位置找到輸入，從其他工作目錄呼叫也可；執行時不下載資料、不修改原圖、既有 GLB 或產品設定。

## 已確認與尚缺資料

兩組控制點反算相機離牆腳高度約 `1.787 / 1.802 m`，垂牆距離約 `10.590 / 10.823 m`；目前投影使用 `2.306 m` 與 `4.513 m`。這支持問題同時涉及高度及平面位置，單調高度或取消 global 都不足。

這些數值假設官方 `2.97 m` 等於照片檐口至牆腳／階緣高度，尚無現場量測。像素 Y 各 ±3 px 約造成高度 ±5 cm、距離 ±0.4 m，不包含此高度假設的誤差。原相機近距地面雲為 0 點；包內僅保留摘要，不能重跑完整點雲地面估計。

後續至少需要可信的「照片牆角 ↔ 官方 footprint 頂點」對應；最好有兩個分離牆角，並確認檐口、牆腳與官方高度的關係。取得之前，保留診斷狀態；全自動流程遇到無可靠對位時應跳過。

原圖作者 `irvinfly`，來源 [Mapillary](https://www.mapillary.com/app/?pKey=1709407170479969)，授權 [CC-BY-SA-4.0](https://creativecommons.org/licenses/by-sa/4.0/)。原圖未修改，診斷圖的重投影、放大與標記另列於 `attribution.json`。
