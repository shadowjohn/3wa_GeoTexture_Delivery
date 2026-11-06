<?php
declare(strict_types=1);
namespace GeoTexture;

use InvalidArgumentException;

final class Recipes {
    public static function all(): array {
        return [
            'procedural-tw-v1'=>['label'=>'台灣街區','kind'=>'procedural','description'=>'磁磚牆、窗框與樓層線，依真實輪廓產生示意立面。'],
            'procedural-modern-v1'=>['label'=>'現代簡約','kind'=>'procedural','description'=>'簡潔外牆與玻璃窗格，保留原始建物高度。'],
            'procedural-pastel-v1'=>['label'=>'粉彩插畫','kind'=>'procedural','description'=>'柔和配色與簡化窗格，適合導覽與展示。'],
            'texture-v1'=>['label'=>'實景貼皮','kind'=>'imagery','description'=>'保留街景照片試轉流程；需要另外執行影像轉換與對位核對。'],
        ];
    }

    public static function validate(string $key): string {
        if (!isset(self::all()[$key])) { throw new InvalidArgumentException('不支援的外觀風格'); }
        return $key;
    }

    public static function procedural(string $key): bool {
        return (self::all()[$key]['kind'] ?? null)==='procedural';
    }
}
