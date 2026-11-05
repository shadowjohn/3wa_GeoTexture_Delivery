<?php
declare(strict_types=1);

use GeoTexture\BuildingSource;
use GeoTexture\Geometry;

$root = dirname(__DIR__);
if (!is_file($root . '/src/Geometry.php') || !is_file($root . '/src/BuildingSource.php')) {
    fwrite(STDERR, "FAIL: 幾何與唯讀來源模組尚未實作。\n");
    exit(1);
}
require_once $root . '/src/Geometry.php';
require_once $root . '/src/BuildingSource.php';

$tests = [];
$test = static function (string $name, callable $body) use (&$tests): void {
    $tests[$name] = $body;
};
$expect = static function (bool $condition, string $message = '條件不成立'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$reject = static function (callable $body) use ($expect): void {
    try {
        $body();
    } catch (InvalidArgumentException $error) {
        return;
    }
    $expect(false, '應拒絕無效輸入');
};
$polygon = static fn (array $ring, array ...$holes): array => [
    'type' => 'Polygon', 'coordinates' => array_merge([$ring], $holes),
];
$square = static fn (float $x, float $y, float $size): array => $polygon([
    [$x, $y], [$x + $size, $y], [$x + $size, $y + $size], [$x, $y + $size], [$x, $y],
]);

$test('正規化 Polygon 與外接範圍', static function () use ($square, $expect): void {
    $geometry = Geometry::validatePolygon($square(121.4, 25.1, 0.01));
    $expect($geometry['type'] === 'Polygon');
    $bbox = Geometry::bbox($geometry);
    foreach ([121.4, 25.1, 121.41, 25.11] as $index => $value) {
        $expect(abs($bbox[$index] - $value) < 1e-10);
    }
});
$test('球面面積扣除內洞且不受環方向影響', static function () use ($polygon, $expect): void {
    $outer = [[0, 0], [1, 0], [1, 1], [0, 1], [0, 0]];
    $hole = [[0.25, 0.25], [0.75, 0.25], [0.75, 0.75], [0.25, 0.75], [0.25, 0.25]];
    $area = Geometry::area($polygon($outer));
    $withHole = Geometry::area($polygon(array_reverse($outer), $hole));
    $expect(abs($area - 12363718145.18) / $area < 0.00001, '一度赤道方格面積不符');
    $expect(abs($withHole / $area - 0.75) < 0.00002, '內洞面積未扣除');
});
$test('拒絕自交退化重複與未封閉環', static function () use ($polygon, $reject): void {
    foreach ([
        [[0, 0], [1, 1], [0, 1], [1, 0], [0, 0]],
        [[0, 0], [1, 0], [2, 0], [0, 0]],
        [[0, 0], [1, 0], [1, 0], [0, 1], [0, 0]],
        [[0, 0], [1, 0], [1, 1], [0, 1]],
        [[0, 0], [2, 0], [1, 0], [1, 1], [0, 0]],
        [[0, 0], [3, 0], [0, 3], [3, 3], [1, 0], [0, 0]],
    ] as $ring) {
        $reject(static fn () => Geometry::validatePolygon($polygon($ring)));
    }
});
$test('拒絕非法座標與頂點過量', static function () use ($square, $polygon, $reject): void {
    foreach ([[INF, 0], [NAN, 0], [181, 0], [0, 91], ['121.4', 25.1], [121.4, 25.1, 8]] as $point) {
        $geometry = $square(121.4, 25.1, 0.01);
        $geometry['coordinates'][0][1] = $point;
        $reject(static fn () => Geometry::validatePolygon($geometry));
    }
    $ring = [];
    for ($i = 0; $i < 600; $i++) {
        $angle = 2 * M_PI * $i / 600;
        $ring[] = [121.4 + cos($angle) * 0.001, 25.1 + sin($angle) * 0.001];
    }
    $ring[] = $ring[0];
    $reject(static fn () => Geometry::validatePolygon($polygon($ring)));
    $reject(static fn () => Geometry::validatePolygon(['type' => 'MultiPolygon', 'coordinates' => []]));
});
$test('錯誤 Polygon 座標結構回傳可辨識的輸入錯誤', static function () use ($reject): void {
    foreach (['bad', 1, true, [1, 2, 3], ['outer' => []]] as $coordinates) {
        $reject(static fn () => Geometry::validatePolygon(['type' => 'Polygon', 'coordinates' => $coordinates]));
    }
});
$test('拒絕洞在外環外接觸外環重疊或巢狀', static function () use ($square, $polygon, $reject): void {
    $outer = $square(0, 0, 5)['coordinates'][0];
    foreach ([
        [$square(6, 6, 1)['coordinates'][0]],
        [$square(0, 1, 1)['coordinates'][0]],
        [$square(1, 1, 2)['coordinates'][0], $square(2, 2, 2)['coordinates'][0]],
        [$square(1, 1, 3)['coordinates'][0], $square(2, 2, 1)['coordinates'][0]],
    ] as $holes) {
        $reject(static fn () => Geometry::validatePolygon($polygon($outer, ...$holes)));
    }
});
$test('保留來源 WKT collection 的 Polygon 與洞', static function () use ($expect): void {
    $geometry = Geometry::fromWkt(' geometrycollection ( polygon ((121 25,122 25,122 26,121 26,121 25),(121.2 25.2,121.4 25.2,121.4 25.4,121.2 25.4,121.2 25.2))) ');
    $expect($geometry['type'] === 'Polygon');
    $expect(count($geometry['coordinates']) === 2, '來源內洞遺失');
    $expect($geometry['coordinates'][1][0] === [121.2, 25.2]);
});
$test('讀取 MultiPolygon 與多 Polygon collection', static function () use ($expect): void {
    $partA = '((121 25,121.1 25,121.1 25.1,121 25))';
    $partB = '((122 26,122.1 26,122.1 26.1,122 26))';
    foreach ([
        'MULTIPOLYGON(' . $partA . ',' . $partB . ')',
        'GEOMETRYCOLLECTION(POLYGON' . $partA . ',POLYGON' . $partB . ')',
        'GEOMETRYCOLLECTION(GEOMETRYCOLLECTION(POLYGON' . $partA . '),POLYGON' . $partB . ')',
    ] as $wkt) {
        $geometry = Geometry::fromWkt($wkt);
        $expect($geometry['type'] === 'MultiPolygon');
        $expect(count($geometry['coordinates']) === 2);
    }
    $geometry = Geometry::fromWkt('POLYGON((1.21e2 2.5e1,121.1 25,121.1 25.1,121 25))');
    $expect($geometry['coordinates'][0][0] === [121.0, 25.0]);
    $geometry = Geometry::fromWkt('MULTIPOLYGON(' . $partA . ',' . $partB . ')');
    $expect(Geometry::bbox($geometry) === [121.0, 25.0, 122.1, 26.1]);
    $expect(Geometry::area($geometry) > 100000000);
});
$test('拒絕畸形與非面 WKT', static function () use ($reject): void {
    foreach (['', 'POINT(121 25)', 'POLYGON EMPTY', 'POLYGON((121 25,122 25,121 25))',
        'POLYGON((121 25,122 25,122 26,121 25)) trailing',
        'GEOMETRYCOLLECTION(POLYGON((121 25,122 25,122 26,121 25)),POINT(121 25))',
        'POLYGON Z((121 25 0,122 25 0,122 26 0,121 25 0))'] as $wkt) {
        $reject(static fn () => Geometry::fromWkt($wkt));
    }
});
$test('相交包含邊與頂點接觸及包覆', static function () use ($square, $expect): void {
    $base = $square(0, 0, 2);
    foreach ([$square(2, 0, 1), $square(2, 2, 1), $square(0.5, 0.5, 0.2), $base] as $other) {
        $expect(Geometry::intersects($base, $other));
        $expect(Geometry::intersects($other, $base));
    }
    $expect(!Geometry::intersects($base, $square(2.01, 0, 1)));
});
$test('洞內不相交洞邊界仍相交', static function () use ($square, $polygon, $expect): void {
    $donut = $polygon($square(0, 0, 5)['coordinates'][0], $square(1, 1, 3)['coordinates'][0]);
    $expect(!Geometry::intersects($donut, $square(2, 2, 1)), '洞內建物不可選取');
    $expect(Geometry::intersects($donut, $square(1, 2, 1)), '洞邊界接觸應納入');
});
$test('凹形缺口排除 bbox 假陽性且線段穿越可相交', static function () use ($square, $polygon, $expect): void {
    $concave = $polygon([[0, 0], [3, 0], [3, 1], [1, 1], [1, 3], [0, 3], [0, 0]]);
    $expect(!Geometry::intersects($concave, $square(1.5, 1.5, 0.5)));
    $horizontal = $polygon([[0, 1], [3, 1], [3, 2], [0, 2], [0, 1]]);
    $vertical = $polygon([[1, 0], [2, 0], [2, 3], [1, 3], [1, 0]]);
    $expect(Geometry::intersects($horizontal, $vertical));
    $multi = ['type' => 'MultiPolygon', 'coordinates' => [$square(8, 8, 1)['coordinates'], $square(0, 0, 1)['coordinates']]];
    $expect(Geometry::intersects($multi, $square(0.2, 0.2, 0.1)));
});

$fixturePath = tempnam(sys_get_temp_dir(), 'geotexture-test-');
if ($fixturePath === false) {
    throw new RuntimeException('無法建立測試資料庫');
}
$db = new SQLite3($fixturePath);
$db->exec('CREATE TABLE [8418] (ogc_fid INTEGER PRIMARY KEY, build_id TEXT, buildname TEXT, build_h REAL, build_no INTEGER, GEOMETRY_WKT TEXT)');
$db->exec('CREATE VIRTUAL TABLE idx_8418_GEOMETRY USING rtree(pkid,xmin,xmax,ymin,ymax)');
$insert = $db->prepare('INSERT INTO [8418] VALUES (:fid,:id,:name,:height,:floors,:wkt)');
$index = $db->prepare('INSERT INTO idx_8418_GEOMETRY VALUES (:fid,:xmin,:xmax,:ymin,:ymax)');
$rows = [
    [1, 'A', '測試甲', 12.5, 4, 'POLYGON((121.400 25.100,121.401 25.100,121.401 25.101,121.400 25.101,121.400 25.100))', [121.400, 121.401, 25.100, 25.101]],
    [2, 'B', 'NA', null, 0, 'GEOMETRYCOLLECTION(POLYGON((121.410 25.110,121.411 25.110,121.411 25.111,121.410 25.111,121.410 25.110)))', [121.410, 121.411, 25.110, 25.111]],
    [3, 'C', '測試丙', 0, 0, 'POLYGON((121.420 25.120,121.421 25.120,121.421 25.121,121.420 25.121,121.420 25.120))', [121.420, 121.421, 25.120, 25.121]],
    [4, 'D', '另一棟', 8.0, 2, 'POLYGON((121.430 25.130,121.431 25.130,121.431 25.131,121.430 25.131,121.430 25.130))', [121.430, 121.431, 25.130, 25.131]],
];
foreach ($rows as [$fid, $id, $name, $height, $floors, $wkt, $bounds]) {
    foreach (['fid' => $fid, 'id' => $id, 'name' => $name, 'height' => $height, 'floors' => $floors, 'wkt' => $wkt] as $key => $value) {
        $type = $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : (is_float($value) ? SQLITE3_FLOAT : SQLITE3_TEXT));
        $insert->bindValue(':' . $key, $value, $type);
    }
    $insert->execute()->finalize();
    foreach (['fid' => $fid, 'xmin' => $bounds[0], 'xmax' => $bounds[1], 'ymin' => $bounds[2], 'ymax' => $bounds[3]] as $key => $value) {
        $index->bindValue(':' . $key, $value, $key === 'fid' ? SQLITE3_INTEGER : SQLITE3_FLOAT);
    }
    $index->execute()->finalize();
}
$insert->close();
$index->close();
$db->close();

$test('來源 bbox 查詢回傳穩定識別高度與輸入 hash', static function () use ($fixturePath, $expect): void {
    $source = new BuildingSource($fixturePath);
    $fc = $source->query([121.399, 25.099, 121.415, 25.115]);
    $expect($fc['type'] === 'FeatureCollection');
    $expect(array_column($fc['features'], 'id') === ['A', 'B']);
    $expect($fc['meta']['returned'] === 2 && $fc['meta']['truncated'] === false);
    $props = $fc['features'][0]['properties'];
    $expect($props['build_id'] === 'A' && $props['name'] === '測試甲');
    $expect($props['height'] === 12.5 && $props['height_missing'] === false);
    $expect($props['source_key'] === 'new-taipei-8418' && preg_match('/^[a-f0-9]{64}$/', $props['source_hash']) === 1);
    $expect($source->query([121.399, 25.099, 121.415, 25.115])['features'][0]['properties']['source_hash'] === $props['source_hash']);
    $missing = $fc['features'][1]['properties'];
    $expect($missing['height'] === null && $missing['height_missing'] === true);
    $meta = $source->meta();
    $expect($meta['ready'] === true && $meta['count'] === 4 && $meta['source_key'] === 'new-taipei-8418');
});
$test('來源 limit 加一判斷且不可靜默截斷', static function () use ($fixturePath, $expect, $reject): void {
    $source = new BuildingSource($fixturePath);
    $one = $source->query([121.399, 25.099, 121.415, 25.115], 1);
    $expect(count($one['features']) === 1 && $one['meta']['truncated'] === true);
    $exact = $source->query([121.399, 25.099, 121.405, 25.105], 1);
    $expect($exact['meta']['returned'] === 1 && $exact['meta']['truncated'] === false);
    $reject(static fn () => $source->query([121.399, 25.099, 121.415, 25.115], 10001));
    $reject(static fn () => $source->query([121.399, 25.099, 121.415, 25.115], 0));
});
$test('來源精確篩選不讓 bbox 外部建物佔用 limit', static function () use ($fixturePath, $polygon, $expect): void {
    $source = new BuildingSource($fixturePath);
    $selection = $polygon([[121.409, 25.099], [121.415, 25.099], [121.415, 25.115], [121.399, 25.115], [121.399, 25.109], [121.409, 25.109], [121.409, 25.099]]);
    $fc = $source->query([121.399, 25.099, 121.415, 25.115], 1, $selection);
    $expect(array_column($fc['features'], 'id') === ['B'], '凹形缺口的 A 不可佔用名額');
    $expect($fc['meta']['truncated'] === false);
    $fc = $source->query([121.419, 25.119, 121.425, 25.125]);
    $expect($fc['features'][0]['properties']['height_missing'] === true);
});
$test('拒絕超大錯序非有限 bbox 與不存在來源且不自建檔案', static function () use ($fixturePath, $expect, $reject): void {
    $source = new BuildingSource($fixturePath);
    foreach ([[121, 25, 122, 26], [122, 25, 121, 25.1], [121, 25, 121, 25.1], [INF, 25, 121.1, 25.1], [121, 91, 121.1, 91.1], [121, 25, 121.1]] as $bbox) {
        $reject(static fn () => $source->query($bbox));
    }
    $missing = $fixturePath . '-missing';
    $reject(static fn () => new BuildingSource($missing));
    $expect(!file_exists($missing), '來源不存在時不可自動建立 SQLite 檔案');
});
$test('來源資料異動改變輸入 hash且查詢不改寫資料庫', static function () use ($fixturePath, $expect): void {
    $bbox = [121.399, 25.099, 121.405, 25.105];
    $before = hash_file('sha256', $fixturePath);
    $source = new BuildingSource($fixturePath);
    $oldHash = $source->query($bbox)['features'][0]['properties']['source_hash'];
    $expect(hash_file('sha256', $fixturePath) === $before);
    $writer = new SQLite3($fixturePath);
    $writer->exec('UPDATE [8418] SET build_h=15 WHERE ogc_fid=1');
    $writer->close();
    $newHash = $source->query($bbox)['features'][0]['properties']['source_hash'];
    $expect($newHash !== $oldHash, '來源高度變更應使既有成果失效');
});

$failed = 0;
try {
    foreach ($tests as $name => $body) {
        try {
            $body();
            fwrite(STDOUT, 'PASS: ' . $name . "\n");
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, 'FAIL: ' . $name . ' — ' . $error->getMessage() . "\n");
        }
    }
} finally {
    @unlink($fixturePath);
}
fwrite(STDOUT, sprintf("%d/%d passed\n", count($tests) - $failed, count($tests)));
exit($failed === 0 ? 0 : 1);
