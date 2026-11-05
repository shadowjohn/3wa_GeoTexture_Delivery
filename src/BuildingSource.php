<?php
declare(strict_types=1);

namespace GeoTexture;

use InvalidArgumentException;
use RuntimeException;
use SQLite3;
use Throwable;

require_once __DIR__ . '/Geometry.php';

final class BuildingSource
{
    public const SOURCE_KEY = 'new-taipei-8418';
    public const MAX_QUERY_LIMIT = 10000;
    public const MAX_BBOX_SPAN = 0.25;
    private SQLite3 $db;

    public function __construct(string $path)
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new InvalidArgumentException('找不到可讀取的新北建物資料庫。');
        }
        if (!class_exists(SQLite3::class)) {
            throw new RuntimeException('請啟用 PHP sqlite3 擴充套件。');
        }
        // 明確使用唯讀開啟旗標，來源不存在時也不會自動建立新資料庫。
        $this->db = new SQLite3($resolved, SQLITE3_OPEN_READONLY);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(2000);
        $this->db->exec('PRAGMA query_only=ON');
        try {
            $result = $this->db->query('SELECT ogc_fid,build_id,buildname,build_h,build_no,GEOMETRY_WKT FROM [8418] LIMIT 0');
            $result->finalize();
            $result = $this->db->query('SELECT pkid,xmin,xmax,ymin,ymax FROM idx_8418_GEOMETRY LIMIT 0');
            $result->finalize();
        } catch (Throwable $error) {
            throw new RuntimeException('來源資料庫缺少 8418 建物資料或空間索引。', 0, $error);
        }
    }

    public function __destruct()
    {
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function meta(): array
    {
        $bounds = $this->db->querySingle('SELECT MIN(xmin) AS west,MIN(ymin) AS south,MAX(xmax) AS east,MAX(ymax) AS north FROM idx_8418_GEOMETRY', true);
        return [
            'ready' => true,
            'name' => '新北市建物（8418）',
            'source_key' => self::SOURCE_KEY,
            'crs' => 'EPSG:4326',
            'count' => (int) $this->db->querySingle('SELECT COUNT(*) FROM [8418]'),
            'bbox' => $bounds['west'] === null ? null : array_map('floatval', [$bounds['west'], $bounds['south'], $bounds['east'], $bounds['north']]),
        ];
    }

    public function query(array $bbox, int $limit = 2000, ?array $polygon = null): array
    {
        $bbox = self::validateBbox($bbox);
        if ($limit < 1 || $limit > self::MAX_QUERY_LIMIT) {
            throw new InvalidArgumentException('建物查詢筆數必須介於 1 與 10000。');
        }
        if ($polygon !== null) {
            $polygon = Geometry::validatePolygon($polygon);
        }
        $sql = 'SELECT b.ogc_fid,b.build_id,b.buildname,b.build_h,b.build_no,b.GEOMETRY_WKT '
            . 'FROM idx_8418_GEOMETRY AS r JOIN [8418] AS b ON b.ogc_fid=r.pkid '
            . 'WHERE r.xmax>=:west AND r.xmin<=:east AND r.ymax>=:south AND r.ymin<=:north '
            . 'ORDER BY b.ogc_fid';
        // Polygon 篩選必須先完成精確比對，再取 limit+1；不可讓 bbox 假陽性佔用名額。
        if ($polygon === null) {
            $sql .= ' LIMIT :take';
        }
        $statement = $this->db->prepare($sql);
        foreach (['west' => $bbox[0], 'south' => $bbox[1], 'east' => $bbox[2], 'north' => $bbox[3]] as $name => $value) {
            $statement->bindValue(':' . $name, $value, SQLITE3_FLOAT);
        }
        if ($polygon === null) {
            $statement->bindValue(':take', $limit + 1, SQLITE3_INTEGER);
        }
        $result = $statement->execute();
        $features = [];
        $truncated = false;
        try {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                try {
                    $geometry = Geometry::fromWkt((string) $row['GEOMETRY_WKT']);
                } catch (InvalidArgumentException $error) {
                    throw new RuntimeException('來源建物 ' . $row['build_id'] . ' 的幾何資料無法讀取。', 0, $error);
                }
                if ($polygon !== null && !Geometry::intersects($geometry, $polygon)) {
                    continue;
                }
                if (count($features) === $limit) {
                    $truncated = true;
                    break;
                }
                $features[] = self::feature($row, $geometry);
            }
        } finally {
            $result->finalize();
            $statement->close();
        }
        return [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => ['returned' => count($features), 'truncated' => $truncated, 'limit' => $limit, 'bbox' => $bbox, 'source_key' => self::SOURCE_KEY],
        ];
    }

    private static function feature(array $row, array $geometry): array
    {
        $height = $row['build_h'] === null ? null : (float) $row['build_h'];
        $missing = $height === null || !is_finite($height) || $height <= 0;
        $name = trim((string) ($row['buildname'] ?? ''));
        if (in_array(strtoupper($name), ['NA', 'N/A', 'NULL'], true)) {
            $name = '';
        }
        $hashInput = [self::SOURCE_KEY, (string) $row['build_id'], (string) ($row['buildname'] ?? ''),
            $row['build_h'], $row['build_no'], (string) $row['GEOMETRY_WKT']];
        return [
            'type' => 'Feature',
            'id' => (string) $row['build_id'],
            'geometry' => $geometry,
            'properties' => [
                'build_id' => (string) $row['build_id'],
                'name' => $name,
                'height' => $missing ? null : $height,
                'height_missing' => $missing,
                'source_hash' => hash('sha256', json_encode($hashInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'source_key' => self::SOURCE_KEY,
            ],
        ];
    }

    private static function validateBbox(array $bbox): array
    {
        if (!array_is_list($bbox) || count($bbox) !== 4) {
            throw new InvalidArgumentException('bbox 必須是 [west,south,east,north]。');
        }
        foreach ($bbox as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('bbox 必須包含有限數值。');
            }
        }
        [$west, $south, $east, $north] = array_map('floatval', $bbox);
        if ($west < -180 || $east > 180 || $south < -90 || $north > 90 || $west >= $east || $south >= $north) {
            throw new InvalidArgumentException('bbox 經緯度範圍或座標順序錯誤。');
        }
        if ($east - $west > self::MAX_BBOX_SPAN || $north - $south > self::MAX_BBOX_SPAN) {
            throw new InvalidArgumentException('查詢範圍過大，請放大地圖或縮小 Polygon（每軸最多 0.25 度）。');
        }
        return [$west, $south, $east, $north];
    }
}
