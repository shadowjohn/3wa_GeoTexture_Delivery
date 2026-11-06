<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$startedAt=gmdate('c');
try {
    $options=getopt('',['batch:','time-budget:']);
    $batch=filter_var($options['batch'] ?? '100',FILTER_VALIDATE_INT);
    if ($batch===false) { throw new InvalidArgumentException('--batch 必須是整數'); }
    $timeBudget=filter_var($options['time-budget'] ?? '0',FILTER_VALIDATE_INT);
    if ($timeBudget===false || $timeBudget<0 || $timeBudget>300) { throw new InvalidArgumentException('--time-budget 必須是 0 到 300 秒（0 表示不限制批次時間）'); }
    $stats=geoTextureApp()->runBatch($batch,null,$timeBudget);
    echo json_encode(['started_at'=>$startedAt,'finished_at'=>gmdate('c'),'data'=>$stats],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,json_encode(['started_at'=>$startedAt,'finished_at'=>gmdate('c'),'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(1);
}
