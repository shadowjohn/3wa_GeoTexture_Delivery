<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $options=getopt('',['source-key:','build-id:','source-hash:','recipe-key:','path:','manifest-path:','longitude:','latitude:','altitude:','heading:','scale:','help']);
    if (isset($options['help'])) {
        echo 'php cli/register-result.php --source-key=... --build-id=... --source-hash=... --recipe-key=texture-v1 --path=incoming/model.glb [--manifest-path=incoming/model.manifest.json] [--longitude=... --latitude=... --altitude=0 --heading=0 --scale=1]'.PHP_EOL;
        echo 'path 必須相對於 GEOTEXTURE_STATE_DIR/artifacts；成功後另存不可變的 models/<asset_id>.glb。'.PHP_EOL;
        exit;
    }
    $input=[];
    foreach ($options as $key=>$value) { $input[str_replace('-','_',$key)]=$value; }
    echo json_encode(['data'=>geoTextureApp()->registerResult($input)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
