# E8 CDN 的跨來源模組

本機圖台直接引用 `https://3wa.tw/inc/javascript/easymap8/easymap.js`。E8 preview.5 會再動態 import 同目錄 runtime、Three.js、bridge 等 ES module。ES module 跨來源載入需要 CORS 標頭；傳統 script 主檔能載入，並不代表 modules 也可載入。

首次實機驗證時，上述檔案均 HTTP 200、JavaScript MIME 正確，但未帶 `Access-Control-Allow-Origin`，瀏覽器因此拒絕動態 import。

在 CDN **公開靜態 SDK** 的 `easymap8/` 目錄套用下列設定，包含 vendor 子目錄。此設定只給公開 SDK，**不可放在 GeoTexture 的 db/ 目錄**。

Apache（需 mod_headers，且 AllowOverride 允許）：

```apache
<IfModule mod_headers.c>
    Header always set Access-Control-Allow-Origin "*"
</IfModule>
```

IIS 可將以下片段合併進該 CDN 目錄既有 web.config，保留原設定：

```xml
<system.webServer>
  <httpProtocol>
    <customHeaders>
      <remove name="Access-Control-Allow-Origin" />
      <add name="Access-Control-Allow-Origin" value="*" />
    </customHeaders>
  </httpProtocol>
</system.webServer>
```

確認 runtime、maplibre-three-plugin.js 及 vendor/three/*.js 回應均有此標頭，再重新載入本機圖台。設定只能作用於伺服器回應；在本機 HTML 加 meta 或 request header 不能取代。
