<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $options=getopt('',['batch:']);
    $batch=filter_var($options['batch'] ?? '100',FILTER_VALIDATE_INT);
    if ($batch===false) { throw new InvalidArgumentException('--batch 必須是整數'); }
    echo json_encode(['data'=>geoTextureApp()->runBatch($batch)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
