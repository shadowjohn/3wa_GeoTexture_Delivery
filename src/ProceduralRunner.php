<?php
declare(strict_types=1);
namespace GeoTexture;

use RuntimeException;

final class ProceduralRunner {
    // Trusted executable and fixed module; user data is read from a private JSON file.
    public static function generate(string $input, string $output, string $python): void {
        $pipes=[];
        $process=proc_open([$python,'-m','pipeline.procedural',$input,$output],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if (!is_resource($process)) { throw new RuntimeException('無法啟動立面產生器'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $start=microtime(true); $error=''; $exit=-1;
        try {
            do {
                stream_get_contents($pipes[1]);
                $error=substr($error.stream_get_contents($pipes[2]),-8192);
                $status=proc_get_status($process);
                if (!$status['running']) { $exit=$status['exitcode']; break; }
                if (microtime(true)-$start>20) {
                    proc_terminate($process,9);
                    throw new RuntimeException('單棟立面產生超過 20 秒，請縮小範圍後重試');
                }
                usleep(20000);
            } while (true);
            $error=substr($error.stream_get_contents($pipes[2]),-8192);
        } finally {
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        if ($exit!==0 || !is_file($output)) {
            error_log('[GeoTexture procedural] '.$error);
            throw new RuntimeException('立面產生失敗，請確認建物高度、輪廓及 Python 相依套件');
        }
    }
}
