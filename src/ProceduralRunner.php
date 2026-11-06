<?php
declare(strict_types=1);
namespace GeoTexture;

use RuntimeException;
use Throwable;

final class ProceduralRunner {
    private $process = null;
    private array $pipes = [];

    public function __construct(private string $python) {}

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

    public function generateRequest(array $request, string $output): void {
        $line=json_encode(['request'=>$request,'output_path'=>$output],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        if (strlen($line)>8388608) { throw new RuntimeException('單棟立面輸入超過 8 MiB'); }
        $start=microtime(true); $sent=0; $response=''; $error='';
        try {
            if ($this->process && !proc_get_status($this->process)['running']) { $this->close(); }
            if (!$this->process) {
                // Only the trusted executable and fixed module are command arguments.
                $this->process=proc_open([$this->python,'-u','-m','pipeline.procedural','--batch'],
                    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes,dirname(__DIR__));
                if (!is_resource($this->process)) { throw new RuntimeException('無法啟動立面產生器'); }
                foreach ($this->pipes as $pipe) { stream_set_blocking($pipe,false); }
            }
            while (true) {
                if (microtime(true)-$start>20) { throw new RuntimeException('單棟立面產生超過 20 秒，請縮小範圍後重試'); }
                $read=[$this->pipes[1],$this->pipes[2]];
                $write=$sent<strlen($line) ? [$this->pipes[0]] : [];
                $except=null;
                if (stream_select($read,$write,$except,0,100000)===false) { throw new RuntimeException('無法讀取立面產生器'); }
                if ($write) {
                    $count=@fwrite($this->pipes[0],substr($line,$sent,65536));
                    if ($count===false) { throw new RuntimeException('立面產生器已中斷'); }
                    $sent+=$count;
                }
                foreach ($read as $pipe) {
                    $chunk=fread($pipe,65536);
                    if ($chunk===false) { throw new RuntimeException('無法讀取立面產生器'); }
                    if ($pipe===$this->pipes[1]) { $response.=$chunk; }
                    else { $error=substr($error.$chunk,-8192); }
                }
                if (strlen($response)>65536) { throw new RuntimeException('立面產生器回應過大'); }
                if (str_contains($response,"\n")) {
                    $result=json_decode($response,true,16,JSON_THROW_ON_ERROR);
                    if ($sent!==strlen($line) || !is_array($result) || !isset($result['ok']) || !is_bool($result['ok']) || (!$result['ok'] && !is_string($result['error'] ?? null))) {
                        throw new RuntimeException('立面產生器回應格式錯誤');
                    }
                    if ($result['ok'] && (!is_file($output) || !is_file(substr($output,0,-4).'.manifest.json'))) { throw new RuntimeException('立面產生器缺少成果檔案'); }
                    break;
                }
                if (!proc_get_status($this->process)['running']) { throw new RuntimeException('立面產生器已中斷'); }
            }
        } catch (Throwable $e) {
            $this->close();
            error_log('[GeoTexture procedural] '.$e->getMessage().' '.$error);
            throw new RuntimeException('立面產生失敗：'.$e->getMessage(),0,$e);
        }
        // A valid item error leaves the process healthy for the next building.
        if (!$result['ok']) {
            error_log('[GeoTexture procedural] '.substr($result['error'],0,8192));
            throw new RuntimeException('立面產生失敗，請確認建物高度、輪廓及 Python 相依套件');
        }
    }

    public function close(): void {
        if (is_resource($this->process)) { proc_terminate($this->process,9); }
        foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        if (is_resource($this->process)) { proc_close($this->process); }
        $this->process=null; $this->pipes=[];
    }

    public function __destruct() { $this->close(); }
}
