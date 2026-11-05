<?php
declare(strict_types=1);

namespace GeoTexture;

use InvalidArgumentException;

final class Geometry
{
    public const MAX_VERTICES = 500;
    private const MAX_SOURCE_VERTICES = 20000;
    private const EPSILON = 1.0e-12;
    private const EARTH_RADIUS = 6371008.8;

    public static function validatePolygon(array $geometry): array
    {
        if (($geometry['type'] ?? null) !== 'Polygon') {
            throw new InvalidArgumentException('範圍必須是 GeoJSON Polygon。');
        }
        if (!isset($geometry['coordinates']) || !is_array($geometry['coordinates'])) {
            throw new InvalidArgumentException('Polygon 座標必須是環陣列。');
        }
        $total = 0;
        $rings = self::normalizePolygon($geometry['coordinates'] ?? [], $total, self::MAX_VERTICES);
        foreach ($rings as $ring) {
            self::validateSimpleRing($ring);
        }
        for ($i = 1, $count = count($rings); $i < $count; $i++) {
            if (self::ringsIntersect($rings[0], $rings[$i]) || self::pointInRing($rings[$i][0], $rings[0]) !== 1) {
                throw new InvalidArgumentException('Polygon 內洞必須完整位於外環內，且不可接觸外環。');
            }
            for ($j = 1; $j < $i; $j++) {
                if (self::ringsIntersect($rings[$i], $rings[$j])
                    || self::pointInRing($rings[$i][0], $rings[$j]) !== 0
                    || self::pointInRing($rings[$j][0], $rings[$i]) !== 0) {
                    throw new InvalidArgumentException('Polygon 內洞不可重疊、接觸或互相包覆。');
                }
            }
        }
        $bbox = self::ringsBbox($rings);
        if ($bbox[2] - $bbox[0] > 180) {
            throw new InvalidArgumentException('目前不支援跨越國際換日線的範圍。');
        }
        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    /** @return array{0:float,1:float,2:float,3:float} west, south, east, north */
    public static function bbox(array $geometry): array
    {
        $bbox = [INF, INF, -INF, -INF];
        foreach (self::polygonParts($geometry) as $rings) {
            $part = self::ringsBbox($rings);
            $bbox = [min($bbox[0], $part[0]), min($bbox[1], $part[1]), max($bbox[2], $part[2]), max($bbox[3], $part[3])];
        }
        return $bbox;
    }

    /** 以 WGS84 經緯度計算球面面積；用途為任務摘要，單位 m²。 */
    public static function area(array $geometry): float
    {
        $area = 0.0;
        foreach (self::polygonParts($geometry) as $rings) {
            $part = 0.0;
            foreach ($rings as $index => $ring) {
                $sum = 0.0;
                for ($i = 0, $count = count($ring) - 1; $i < $count; $i++) {
                    $a = self::readPoint($ring[$i]);
                    $b = self::readPoint($ring[$i + 1]);
                    $sum += deg2rad($b[0] - $a[0]) * (2 + sin(deg2rad($a[1])) + sin(deg2rad($b[1])));
                }
                $ringArea = abs($sum) * self::EARTH_RADIUS * self::EARTH_RADIUS / 2;
                $part += $index === 0 ? $ringArea : -$ringArea;
            }
            $area += max(0.0, $part);
        }
        return $area;
    }

    /** 將來源的面集合展平，保留每個 Polygon 的所有內洞。 */
    public static function fromWkt(string $wkt): array
    {
        $wkt = trim($wkt);
        if ($wkt === '' || strlen($wkt) > 2000000) {
            throw new InvalidArgumentException('來源 WKT 空白或超過大小上限。');
        }
        $tokens = [];
        $offset = 0;
        $length = strlen($wkt);
        while ($offset < $length) {
            if (!preg_match('/\G\s*([A-Za-z]+|[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?|[(),])/', $wkt, $match, 0, $offset)) {
                throw new InvalidArgumentException('無法辨識來源 WKT。');
            }
            $tokens[] = strtoupper($match[1]);
            $offset += strlen($match[0]);
            if (count($tokens) > 130000) {
                throw new InvalidArgumentException('來源 WKT 超過頂點上限。');
            }
        }
        $position = 0;
        $total = 0;
        $polygons = self::parseWktGeometry($tokens, $position, 0, $total);
        if ($position !== count($tokens) || $polygons === []) {
            throw new InvalidArgumentException('來源 WKT 結構不完整。');
        }
        return count($polygons) === 1
            ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }

    /** 邊界接觸也算相交；bbox 只作快速排除，仍檢查環交叉及包含關係。 */
    public static function intersects(array $a, array $b): bool
    {
        foreach (self::polygonParts($a) as $ringsA) {
            $bboxA = self::ringsBbox($ringsA);
            foreach (self::polygonParts($b) as $ringsB) {
                if (!self::bboxesIntersect($bboxA, self::ringsBbox($ringsB))) {
                    continue;
                }
                foreach ($ringsA as $ringA) {
                    foreach ($ringsB as $ringB) {
                        if (self::ringsIntersect($ringA, $ringB)) {
                            return true;
                        }
                    }
                }
                if (self::pointInPolygon($ringsA[0][0], $ringsB) !== 0
                    || self::pointInPolygon($ringsB[0][0], $ringsA) !== 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function normalizePolygon(array $rings, int &$total, int $limit): array
    {
        if (!array_is_list($rings) || $rings === [] || count($rings) > 64) {
            throw new InvalidArgumentException('Polygon 必須包含外環，且環數不可超過 64。');
        }
        $normalized = [];
        foreach ($rings as $ring) {
            if (!is_array($ring) || !array_is_list($ring) || count($ring) < 4) {
                throw new InvalidArgumentException('每個環至少需要三個頂點與封閉點。');
            }
            $total += count($ring);
            if ($total > $limit) {
                throw new InvalidArgumentException('Polygon 頂點數超過上限 ' . $limit . '。');
            }
            $points = [];
            foreach ($ring as $point) {
                $points[] = self::readPoint($point);
            }
            if ($points[0] !== $points[count($points) - 1]) {
                throw new InvalidArgumentException('Polygon 的環必須封閉。');
            }
            for ($i = 0, $count = count($points) - 1; $i < $count; $i++) {
                if ($points[$i] === $points[$i + 1]) {
                    throw new InvalidArgumentException('Polygon 含連續重複頂點。');
                }
            }
            $origin = $points[0];
            $signedArea = 0.0;
            for ($i = 1, $count = count($points) - 1; $i < $count; $i++) {
                $signedArea += ($points[$i][0] - $origin[0]) * ($points[$i + 1][1] - $origin[1])
                    - ($points[$i + 1][0] - $origin[0]) * ($points[$i][1] - $origin[1]);
            }
            if (abs($signedArea) <= 1.0e-14) {
                throw new InvalidArgumentException('Polygon 的環面積為零或已退化。');
            }
            $normalized[] = $points;
        }
        return $normalized;
    }

    private static function readPoint(mixed $point): array
    {
        if (!is_array($point) || !array_is_list($point) || count($point) !== 2) {
            throw new InvalidArgumentException('座標必須是 [longitude, latitude]。');
        }
        foreach ($point as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('座標必須是有限數值。');
            }
        }
        if ($point[0] < -180 || $point[0] > 180 || $point[1] < -90 || $point[1] > 90) {
            throw new InvalidArgumentException('座標必須使用 WGS84 經緯度。');
        }
        return [(float) $point[0], (float) $point[1]];
    }

    private static function polygonParts(array $geometry): array
    {
        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates) || !array_is_list($coordinates) || $coordinates === []) {
            throw new InvalidArgumentException('面幾何座標不可空白。');
        }
        if (($geometry['type'] ?? null) === 'Polygon') {
            return [$coordinates];
        }
        if (($geometry['type'] ?? null) === 'MultiPolygon') {
            foreach ($coordinates as $rings) {
                if (!is_array($rings) || !array_is_list($rings) || $rings === []) {
                    throw new InvalidArgumentException('MultiPolygon 包含無效的面。');
                }
            }
            return $coordinates;
        }
        throw new InvalidArgumentException('僅支援 Polygon 與 MultiPolygon。');
    }

    private static function ringsBbox(array $rings): array
    {
        $bbox = [INF, INF, -INF, -INF];
        foreach ($rings as $ring) {
            if (!is_array($ring) || count($ring) < 4) {
                throw new InvalidArgumentException('Polygon 的環座標不完整。');
            }
            foreach ($ring as $point) {
                [$x, $y] = self::readPoint($point);
                $bbox = [min($bbox[0], $x), min($bbox[1], $y), max($bbox[2], $x), max($bbox[3], $y)];
            }
        }
        return $bbox;
    }

    private static function validateSimpleRing(array $ring): void
    {
        $count = count($ring) - 1;
        for ($i = 0; $i < $count; $i++) {
            $previous = $ring[($i + $count - 1) % $count];
            $current = $ring[$i];
            $next = $ring[$i + 1];
            if (self::orientation($previous, $current, $next) === 0
                && ($previous[0] - $current[0]) * ($next[0] - $current[0])
                    + ($previous[1] - $current[1]) * ($next[1] - $current[1]) > 0) {
                throw new InvalidArgumentException('Polygon 含折返重疊線段。');
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $count - 1)) {
                    continue;
                }
                if (self::segmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    throw new InvalidArgumentException('Polygon 不可自交或自我接觸。');
                }
            }
        }
    }

    private static function bboxesIntersect(array $a, array $b): bool
    {
        return $a[0] <= $b[2] + self::EPSILON && $a[2] + self::EPSILON >= $b[0]
            && $a[1] <= $b[3] + self::EPSILON && $a[3] + self::EPSILON >= $b[1];
    }

    private static function ringsIntersect(array $a, array $b): bool
    {
        if (!self::bboxesIntersect(self::ringsBbox([$a]), self::ringsBbox([$b]))) {
            return false;
        }
        for ($i = 0, $countA = count($a) - 1; $i < $countA; $i++) {
            for ($j = 0, $countB = count($b) - 1; $j < $countB; $j++) {
                if (self::segmentsIntersect($a[$i], $a[$i + 1], $b[$j], $b[$j + 1])) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function orientation(array $a, array $b, array $c): int
    {
        $first = ($b[0] - $a[0]) * ($c[1] - $a[1]);
        $second = ($b[1] - $a[1]) * ($c[0] - $a[0]);
        $cross = $first - $second;
        $tolerance = 1.0e-12 * max(1.0e-18, abs($first) + abs($second));
        return abs($cross) <= $tolerance ? 0 : ($cross > 0 ? 1 : -1);
    }

    private static function pointOnSegment(array $point, array $a, array $b): bool
    {
        return self::orientation($a, $b, $point) === 0
            && $point[0] >= min($a[0], $b[0]) - self::EPSILON && $point[0] <= max($a[0], $b[0]) + self::EPSILON
            && $point[1] >= min($a[1], $b[1]) - self::EPSILON && $point[1] <= max($a[1], $b[1]) + self::EPSILON;
    }

    private static function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        if (max($a[0], $b[0]) + self::EPSILON < min($c[0], $d[0])
            || max($c[0], $d[0]) + self::EPSILON < min($a[0], $b[0])
            || max($a[1], $b[1]) + self::EPSILON < min($c[1], $d[1])
            || max($c[1], $d[1]) + self::EPSILON < min($a[1], $b[1])) {
            return false;
        }
        $o1 = self::orientation($a, $b, $c);
        $o2 = self::orientation($a, $b, $d);
        $o3 = self::orientation($c, $d, $a);
        $o4 = self::orientation($c, $d, $b);
        return ($o1 * $o2 < 0 && $o3 * $o4 < 0)
            || ($o1 === 0 && self::pointOnSegment($c, $a, $b))
            || ($o2 === 0 && self::pointOnSegment($d, $a, $b))
            || ($o3 === 0 && self::pointOnSegment($a, $c, $d))
            || ($o4 === 0 && self::pointOnSegment($b, $c, $d));
    }

    /** 0=外部，1=內部，2=邊界。 */
    private static function pointInRing(array $point, array $ring): int
    {
        $inside = false;
        for ($i = 0, $count = count($ring) - 1; $i < $count; $i++) {
            $a = $ring[$i];
            $b = $ring[$i + 1];
            if (self::pointOnSegment($point, $a, $b)) {
                return 2;
            }
            if (($a[1] > $point[1]) !== ($b[1] > $point[1])
                && $point[0] < ($b[0] - $a[0]) * ($point[1] - $a[1]) / ($b[1] - $a[1]) + $a[0]) {
                $inside = !$inside;
            }
        }
        return $inside ? 1 : 0;
    }

    private static function pointInPolygon(array $point, array $rings): int
    {
        $outer = self::pointInRing($point, $rings[0]);
        if ($outer !== 1) {
            return $outer;
        }
        for ($i = 1, $count = count($rings); $i < $count; $i++) {
            $inner = self::pointInRing($point, $rings[$i]);
            if ($inner !== 0) {
                return $inner === 2 ? 2 : 0;
            }
        }
        return 1;
    }

    private static function expectToken(array $tokens, int &$position, string $expected): void
    {
        if (($tokens[$position++] ?? null) !== $expected) {
            throw new InvalidArgumentException('來源 WKT 結構不完整。');
        }
    }

    private static function parseWktGeometry(array $tokens, int &$position, int $depth, int &$total): array
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('來源 WKT 巢狀層數超過上限。');
        }
        $type = $tokens[$position++] ?? '';
        if ($type === 'POLYGON') {
            return [self::parseWktPolygon($tokens, $position, $total)];
        }
        if ($type !== 'MULTIPOLYGON' && $type !== 'GEOMETRYCOLLECTION') {
            throw new InvalidArgumentException('來源 WKT 僅支援二維 Polygon 面幾何。');
        }
        self::expectToken($tokens, $position, '(');
        $polygons = [];
        do {
            $parts = $type === 'MULTIPOLYGON'
                ? [self::parseWktPolygon($tokens, $position, $total)]
                : self::parseWktGeometry($tokens, $position, $depth + 1, $total);
            array_push($polygons, ...$parts);
            if (($tokens[$position] ?? null) !== ',') {
                break;
            }
            $position++;
        } while (true);
        self::expectToken($tokens, $position, ')');
        return $polygons;
    }

    private static function parseWktPolygon(array $tokens, int &$position, int &$total): array
    {
        self::expectToken($tokens, $position, '(');
        $rings = [];
        do {
            self::expectToken($tokens, $position, '(');
            $ring = [];
            do {
                $x = $tokens[$position++] ?? '';
                $y = $tokens[$position++] ?? '';
                if (!is_numeric($x) || !is_numeric($y)) {
                    throw new InvalidArgumentException('來源 WKT 座標格式錯誤。');
                }
                $ring[] = self::readPoint([(float) $x, (float) $y]);
                if (count($ring) + $total > self::MAX_SOURCE_VERTICES) {
                    throw new InvalidArgumentException('來源 WKT 超過頂點上限。');
                }
                if (($tokens[$position] ?? null) !== ',') {
                    break;
                }
                $position++;
            } while (true);
            self::expectToken($tokens, $position, ')');
            $rings[] = $ring;
            if (($tokens[$position] ?? null) !== ',') {
                break;
            }
            $position++;
        } while (true);
        self::expectToken($tokens, $position, ')');
        return self::normalizePolygon($rings, $total, self::MAX_SOURCE_VERTICES);
    }
}
