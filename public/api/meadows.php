<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

const POLYGON_RESULT_LIMIT = 2500;

/** WGS84 envelope of Czech Republic; keep in sync with app.js and prepare_meadows.py */
const CZECH_REPUBLIC_WEST = 12.07;
const CZECH_REPUBLIC_SOUTH = 48.53;
const CZECH_REPUBLIC_EAST = 18.88;
const CZECH_REPUBLIC_NORTH = 51.07;
const SMALL_CLUSTER_NEIGHBOUR_MERGE_PX = 400.0;

/**
 * Pad cluster centroid bbox so meadows just off-screen still count toward the same bucket (see clusterWhere).
 * Fraction is applied to max(lngSpan, latSpan) so portrait/narrow viewports still get enough east–west buffer
 * (per-axis % alone would starve longitude when latSpan ≫ lngSpan).
 */
const CLUSTER_BBOX_INFLATE_SPAN_FRACTION = 0.2;
const CLUSTER_BBOX_INFLATE_MIN_PAD_LAT_DEG = 0.0045;
const CLUSTER_BBOX_INFLATE_MIN_PAD_LNG_DEG = 0.007;

function respondWithError(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    exit;
}

function readFloatParam(string $name): ?float
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return null;
    }

    if (!is_scalar($_GET[$name]) || !is_numeric((string) $_GET[$name])) {
        respondWithError(400, sprintf('Neplatný číselný parametr: %s', $name));
    }

    return (float) $_GET[$name];
}

function readIntParam(string $name, int $default): int
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $default;
    }

    if (!is_scalar($_GET[$name]) || !is_numeric((string) $_GET[$name])) {
        respondWithError(400, sprintf('Neplatný číselný parametr: %s', $name));
    }

    return (int) $_GET[$name];
}

function readStringParam(string $name, string $default): string
{
    if (!isset($_GET[$name]) || $_GET[$name] === '') {
        return $default;
    }

    if (!is_scalar($_GET[$name])) {
        respondWithError(400, sprintf('Neplatný parametr: %s', $name));
    }

    return trim((string) $_GET[$name]);
}

function readBbox(): array
{
    $defaultBbox = sprintf(
        '%s,%s,%s,%s',
        CZECH_REPUBLIC_WEST,
        CZECH_REPUBLIC_SOUTH,
        CZECH_REPUBLIC_EAST,
        CZECH_REPUBLIC_NORTH
    );
    $bbox = $_GET['bbox'] ?? $defaultBbox;
    if (!is_scalar($bbox)) {
        respondWithError(400, 'Neplatný parametr bbox.');
    }

    $parts = array_map('trim', explode(',', (string) $bbox));
    if (count($parts) !== 4) {
        respondWithError(400, 'bbox musí mít formát západ,jih,východ,sever.');
    }

    $values = [];
    foreach ($parts as $part) {
        if (!is_numeric($part)) {
            respondWithError(400, 'bbox musí obsahovat číselné hodnoty.');
        }
        $values[] = (float) $part;
    }

    [$west, $south, $east, $north] = $values;
    if ($west >= $east || $south >= $north) {
        respondWithError(400, 'Hranice bbox jsou neplatné.');
    }

    $west = max($west, CZECH_REPUBLIC_WEST);
    $south = max($south, CZECH_REPUBLIC_SOUTH);
    $east = min($east, CZECH_REPUBLIC_EAST);
    $north = min($north, CZECH_REPUBLIC_NORTH);
    if ($west >= $east || $south >= $north) {
        respondWithError(400, 'bbox po oříznutí na ČR nemá platný rozsah.');
    }

    return [$west, $south, $east, $north];
}

/**
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function inflateBboxForClusters(float $west, float $south, float $east, float $north): array
{
    $lngSpan = $east - $west;
    $latSpan = $north - $south;
    $refSpan = max($lngSpan, $latSpan);
    $fracPad = $refSpan * CLUSTER_BBOX_INFLATE_SPAN_FRACTION;
    $padLng = max($fracPad, CLUSTER_BBOX_INFLATE_MIN_PAD_LNG_DEG);
    $padLat = max($fracPad, CLUSTER_BBOX_INFLATE_MIN_PAD_LAT_DEG);

    $w = $west - $padLng;
    $s = $south - $padLat;
    $e = $east + $padLng;
    $n = $north + $padLat;

    $w = max($w, CZECH_REPUBLIC_WEST);
    $s = max($s, CZECH_REPUBLIC_SOUTH);
    $e = min($e, CZECH_REPUBLIC_EAST);
    $n = min($n, CZECH_REPUBLIC_NORTH);

    if ($w >= $e || $s >= $n) {
        return [$west, $south, $east, $north];
    }

    return [$w, $s, $e, $n];
}

function readMode(): string
{
    $mode = readStringParam('mode', 'clusters');
    if (!in_array($mode, ['clusters', 'polygons'], true)) {
        respondWithError(400, 'Neplatný parametr mode.');
    }

    return $mode;
}

function buildWhereClause(array $where): string
{
    return implode(' AND ', $where);
}

function buildBboxPolygonWkt(float $west, float $south, float $east, float $north): string
{
    return sprintf(
        'POLYGON((%1$.12F %2$.12F,%1$.12F %4$.12F,%3$.12F %4$.12F,%3$.12F %2$.12F,%1$.12F %2$.12F))',
        $west,
        $south,
        $east,
        $north
    );
}

function clusterTierForRequest(int $zoom): int
{
    // Keep this mapping in sync with CLUSTER_TIERS tier_id values in prepare_meadows.py.
    if ($zoom <= 7) {
        return 5;
    } elseif ($zoom === 8) {
        return 6;
    } elseif ($zoom === 9) {
        return 8;
    } elseif ($zoom === 10) {
        return 9;
    } elseif ($zoom === 11) {
        return 10;
    } elseif ($zoom === 12) {
        return 11;
    }

    return 13;
}

function normalizeClusterRows(array $rows, bool $includeFavourite): array
{
    $clusters = [];
    foreach ($rows as $row) {
        if (
            !isset($row['bucket_x'], $row['bucket_y'], $row['representative_lat'], $row['representative_lng'], $row['meadow_count']) ||
            !is_numeric((string) $row['bucket_x']) ||
            !is_numeric((string) $row['bucket_y']) ||
            !is_numeric((string) $row['representative_lat']) ||
            !is_numeric((string) $row['representative_lng']) ||
            !is_numeric((string) $row['meadow_count'])
        ) {
            continue;
        }

        $clusters[] = [
            'bucket_x' => (int) $row['bucket_x'],
            'bucket_y' => (int) $row['bucket_y'],
            'representative_lat' => (float) $row['representative_lat'],
            'representative_lng' => (float) $row['representative_lng'],
            'meadow_count' => (int) $row['meadow_count'],
            'has_favourite' => $includeFavourite && ((int) ($row['has_favourite'] ?? 0)) === 1,
        ];
    }

    return $clusters;
}

function clusterBucketKey(int $bucketX, int $bucketY): string
{
    return $bucketX . ':' . $bucketY;
}

function webMercatorMetersPerPixel(float $latitude, int $zoom): float
{
    $cosLatitude = max(0.01, cos(deg2rad($latitude)));
    return 156543.03392 * $cosLatitude / (2 ** max(0, $zoom));
}

function clusterDistanceMeters(float $latA, float $lngA, float $latB, float $lngB): float
{
    $earthRadiusMeters = 6371008.8;
    $lat1 = deg2rad($latA);
    $lat2 = deg2rad($latB);
    $deltaLat = $lat2 - $lat1;
    $deltaLng = deg2rad($lngB - $lngA);
    $sinLat = sin($deltaLat / 2.0);
    $sinLng = sin($deltaLng / 2.0);
    $a = ($sinLat * $sinLat) + cos($lat1) * cos($lat2) * ($sinLng * $sinLng);
    return 2.0 * $earthRadiusMeters * asin(min(1.0, sqrt($a)));
}

function smallClusterMergeDistanceMeters(int $zoom, float $latitude): float
{
    return max(120.0, min(900.0, SMALL_CLUSTER_NEIGHBOUR_MERGE_PX * webMercatorMetersPerPixel($latitude, $zoom)));
}

function mergeSmallNeighbourClusters(array $clusters, int $zoom): array
{
    if (count($clusters) < 2) {
        return $clusters;
    }

    $indexByBucket = [];
    foreach ($clusters as $index => $cluster) {
        $indexByBucket[clusterBucketKey($cluster['bucket_x'], $cluster['bucket_y'])] = $index;
    }

    $parents = array_keys($clusters);
    $find = static function (int $index) use (&$parents, &$find): int {
        if ($parents[$index] !== $index) {
            $parents[$index] = $find($parents[$index]);
        }

        return $parents[$index];
    };
    $union = static function (int $left, int $right) use (&$parents, $find): void {
        $leftRoot = $find($left);
        $rightRoot = $find($right);
        if ($leftRoot !== $rightRoot) {
            $parents[$rightRoot] = $leftRoot;
        }
    };

    foreach ($clusters as $index => $cluster) {
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }

                $neighborIndex = $indexByBucket[
                    clusterBucketKey($cluster['bucket_x'] + $dx, $cluster['bucket_y'] + $dy)
                ] ?? null;
                if ($neighborIndex === null || $neighborIndex <= $index) {
                    continue;
                }

                $neighbor = $clusters[$neighborIndex];
                $avgLatitude = ($cluster['representative_lat'] + $neighbor['representative_lat']) / 2.0;
                $distanceMeters = clusterDistanceMeters(
                    $cluster['representative_lat'],
                    $cluster['representative_lng'],
                    $neighbor['representative_lat'],
                    $neighbor['representative_lng']
                );
                if ($distanceMeters <= smallClusterMergeDistanceMeters($zoom, $avgLatitude)) {
                    $union($index, $neighborIndex);
                }
            }
        }
    }

    $components = [];
    foreach (array_keys($clusters) as $index) {
        $root = $find($index);
        $components[$root][] = $index;
    }

    $mergedClusters = [];
    foreach ($components as $indices) {
        if (count($indices) === 1) {
            $mergedClusters[] = $clusters[$indices[0]];
            continue;
        }

        $totalCount = 0;
        $weightedLat = 0.0;
        $weightedLng = 0.0;
        $hasFavourite = false;
        foreach ($indices as $index) {
            $cluster = $clusters[$index];
            $count = $cluster['meadow_count'];
            $totalCount += $count;
            $weightedLat += $cluster['representative_lat'] * $count;
            $weightedLng += $cluster['representative_lng'] * $count;
            $hasFavourite = $hasFavourite || $cluster['has_favourite'];
        }

        $firstCluster = $clusters[$indices[0]];
        $mergedClusters[] = [
            'bucket_x' => $firstCluster['bucket_x'],
            'bucket_y' => $firstCluster['bucket_y'],
            'representative_lat' => $weightedLat / $totalCount,
            'representative_lng' => $weightedLng / $totalCount,
            'meadow_count' => $totalCount,
            'has_favourite' => $hasFavourite,
        ];
    }

    return $mergedClusters;
}

try {
    $config = meadowFinderConfig();
    $pdo = meadowFinderPdo();

    [$west, $south, $east, $north] = readBbox();
    [$clusterWest, $clusterSouth, $clusterEast, $clusterNorth] = inflateBboxForClusters($west, $south, $east, $north);
    $mode = readMode();
    $zoom = readIntParam('zoom', 7);
    $minArea = readFloatParam('minArea');
    $maxArea = readFloatParam('maxArea');
    $minRoad = readFloatParam('minRoad');
    $maxRoad = readFloatParam('maxRoad');
    $minPath = readFloatParam('minPath');
    $maxPath = readFloatParam('maxPath');
    $minWater = readFloatParam('minWater');
    $maxWater = readFloatParam('maxWater');
    $minRiver = readFloatParam('minRiver');
    $maxRiver = readFloatParam('maxRiver');
    $minSettlement = readFloatParam('minSettlement');
    $maxSettlement = readFloatParam('maxSettlement');
    $minBuilding = readFloatParam('minBuilding');
    $maxBuilding = readFloatParam('maxBuilding');
    $minLargestFlatPatchShare = readFloatParam('minLargestFlatPatchShare');
    $minFlatAreaShare = readFloatParam('minFlatAreaShare');
    $maxTerrainRoughnessP80M = readFloatParam('maxTerrainRoughnessP80M');

    $where = [
        'MBRIntersects(m.bbox_polygon, ST_GeomFromText(:bboxPolygon))',
    ];
    $params = [
        ':bboxPolygon' => buildBboxPolygonWkt($west, $south, $east, $north),
    ];

    if ($minArea !== null) {
        $where[] = 'm.area_m2 >= :minArea';
        $params[':minArea'] = $minArea;
    }
    if ($maxArea !== null) {
        $where[] = 'm.area_m2 <= :maxArea';
        $params[':maxArea'] = $maxArea;
    }
    if ($minRoad !== null) {
        $where[] = 'm.nearest_road_m >= :minRoad';
        $params[':minRoad'] = $minRoad;
    }
    if ($maxRoad !== null) {
        $where[] = 'm.nearest_road_m <= :maxRoad';
        $params[':maxRoad'] = $maxRoad;
    }
    if ($minPath !== null) {
        $where[] = 'm.nearest_path_m >= :minPath';
        $params[':minPath'] = $minPath;
    }
    if ($maxPath !== null) {
        $where[] = 'm.nearest_path_m <= :maxPath';
        $params[':maxPath'] = $maxPath;
    }
    if ($minWater !== null) {
        $where[] = 'm.nearest_water_m >= :minWater';
        $params[':minWater'] = $minWater;
    }
    if ($maxWater !== null) {
        $where[] = 'm.nearest_water_m <= :maxWater';
        $params[':maxWater'] = $maxWater;
    }
    if ($minRiver !== null) {
        $where[] = 'm.nearest_river_m >= :minRiver';
        $params[':minRiver'] = $minRiver;
    }
    if ($maxRiver !== null) {
        $where[] = 'm.nearest_river_m <= :maxRiver';
        $params[':maxRiver'] = $maxRiver;
    }
    if ($minSettlement !== null) {
        $where[] = 'm.nearest_settlement_m >= :minSettlement';
        $params[':minSettlement'] = $minSettlement;
    }
    if ($maxSettlement !== null) {
        $where[] = 'm.nearest_settlement_m <= :maxSettlement';
        $params[':maxSettlement'] = $maxSettlement;
    }
    if ($minBuilding !== null) {
        $where[] = 'm.nearest_building_m >= :minBuilding';
        $params[':minBuilding'] = $minBuilding;
    }
    if ($maxBuilding !== null) {
        $where[] = 'm.nearest_building_m <= :maxBuilding';
        $params[':maxBuilding'] = $maxBuilding;
    }
    if ($minLargestFlatPatchShare !== null) {
        $where[] = 'm.largest_flat_patch_share >= :minLargestFlatPatchShare';
        $params[':minLargestFlatPatchShare'] = $minLargestFlatPatchShare;
    }
    if ($minFlatAreaShare !== null) {
        $where[] = 'm.flat_area_share >= :minFlatAreaShare';
        $params[':minFlatAreaShare'] = $minFlatAreaShare;
    }
    if ($maxTerrainRoughnessP80M !== null) {
        $where[] = 'm.terrain_roughness_p80_m <= :maxTerrainRoughnessP80M';
        $params[':maxTerrainRoughnessP80M'] = $maxTerrainRoughnessP80M;
    }

    $hasClusterFilters = count($params) > 1;
    $polygonWhere = $where;
    $clusterWhere = array_merge(
        [
            'm.centroid_lng BETWEEN :clusterWest AND :clusterEast',
            'm.centroid_lat BETWEEN :clusterSouth AND :clusterNorth',
        ],
        array_slice($where, 1)
    );

    $sessionUserId = meadowFinderSessionUserId();
    session_write_close();

    $features = [];

    if ($mode === 'clusters') {
        $clusterTier = clusterTierForRequest($zoom);
        $clusterParams = array_filter(
            $params,
            static fn (string $key): bool => $key !== ':bboxPolygon',
            ARRAY_FILTER_USE_KEY
        ) + [
            ':clusterTier' => $clusterTier,
            ':clusterWest' => $clusterWest,
            ':clusterSouth' => $clusterSouth,
            ':clusterEast' => $clusterEast,
            ':clusterNorth' => $clusterNorth,
        ];

        if ($sessionUserId !== null) {
            $clusterParams[':favUser'] = $sessionUserId;
            if ($hasClusterFilters) {
                $sql = '
                    SELECT
                        cm.bucket_x,
                        cm.bucket_y,
                        AVG(m.centroid_lat) AS representative_lat,
                        AVG(m.centroid_lng) AS representative_lng,
                        COUNT(*) AS meadow_count,
                        MAX(CASE WHEN f.source_id IS NOT NULL THEN 1 ELSE 0 END) AS has_favourite
                    FROM meadows m
                    INNER JOIN meadow_cluster_memberships cm
                        ON cm.meadow_id = m.id AND cm.cluster_tier = :clusterTier
                    LEFT JOIN user_favourite_meadows f
                        ON f.source_id = m.source_id AND f.user_id = :favUser
                    WHERE ' . buildWhereClause($clusterWhere) . '
                    GROUP BY cm.bucket_x, cm.bucket_y
                ';
            } else {
                $sql = '
                    SELECT
                        cm.bucket_x,
                        cm.bucket_y,
                        bp.representative_lat,
                        bp.representative_lng,
                        COUNT(*) AS meadow_count,
                        MAX(CASE WHEN f.source_id IS NOT NULL THEN 1 ELSE 0 END) AS has_favourite
                    FROM meadows m
                    INNER JOIN meadow_cluster_memberships cm
                        ON cm.meadow_id = m.id AND cm.cluster_tier = :clusterTier
                    INNER JOIN meadow_cluster_bucket_points bp
                        ON bp.cluster_tier = cm.cluster_tier
                        AND bp.bucket_x = cm.bucket_x
                        AND bp.bucket_y = cm.bucket_y
                    LEFT JOIN user_favourite_meadows f
                        ON f.source_id = m.source_id AND f.user_id = :favUser
                    WHERE ' . buildWhereClause($clusterWhere) . '
                    GROUP BY
                        cm.bucket_x,
                        cm.bucket_y,
                        bp.representative_lat,
                        bp.representative_lng
                ';
            }
        } else {
            if ($hasClusterFilters) {
                $sql = '
                    SELECT
                        cm.bucket_x,
                        cm.bucket_y,
                        AVG(m.centroid_lat) AS representative_lat,
                        AVG(m.centroid_lng) AS representative_lng,
                        COUNT(*) AS meadow_count
                    FROM meadows m
                    INNER JOIN meadow_cluster_memberships cm
                        ON cm.meadow_id = m.id AND cm.cluster_tier = :clusterTier
                    WHERE ' . buildWhereClause($clusterWhere) . '
                    GROUP BY cm.bucket_x, cm.bucket_y
                ';
            } else {
                $sql = '
                    SELECT
                        cm.bucket_x,
                        cm.bucket_y,
                        bp.representative_lat,
                        bp.representative_lng,
                        COUNT(*) AS meadow_count
                    FROM meadows m
                    INNER JOIN meadow_cluster_memberships cm
                        ON cm.meadow_id = m.id AND cm.cluster_tier = :clusterTier
                    INNER JOIN meadow_cluster_bucket_points bp
                        ON bp.cluster_tier = cm.cluster_tier
                        AND bp.bucket_x = cm.bucket_x
                        AND bp.bucket_y = cm.bucket_y
                    WHERE ' . buildWhereClause($clusterWhere) . '
                    GROUP BY
                        cm.bucket_x,
                        cm.bucket_y,
                        bp.representative_lat,
                        bp.representative_lng
                ';
            }
        }

        $statement = $pdo->prepare($sql);
        foreach ($clusterParams as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();
        $clusters = normalizeClusterRows($statement->fetchAll(), $sessionUserId !== null);
        $clusters = mergeSmallNeighbourClusters($clusters, $zoom);

        foreach ($clusters as $cluster) {
            $lat = $cluster['representative_lat'];
            $lng = $cluster['representative_lng'];

            $props = [
                'centroid_lat' => $lat,
                'centroid_lng' => $lng,
                'cluster_count' => $cluster['meadow_count'],
            ];
            if ($sessionUserId !== null) {
                $props['has_favourite'] = $cluster['has_favourite'];
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$lng, $lat],
                ],
                'properties' => $props,
            ];
        }
    } else {
        if ($sessionUserId !== null) {
            $polyParams = $params + [':favUser' => $sessionUserId];
            $sql = '
                SELECT
                    m.id,
                    m.source_id,
                    m.source_type,
                    m.land_cover_code,
                    m.area_ha,
                    m.area_m2,
                    m.average_elevation_deviation_m,
                    m.largest_flat_patch_m2,
                    m.largest_flat_patch_share,
                    m.flat_area_share,
                    m.terrain_roughness_p80_m,
                    m.nearest_road_m,
                    m.nearest_path_m,
                    m.nearest_water_m,
                    m.nearest_river_m,
                    m.nearest_settlement_m,
                    m.nearest_building_m,
                    m.centroid_lat,
                    m.centroid_lng,
                    g.geom_geojson,
                    (CASE WHEN f.source_id IS NOT NULL THEN 1 ELSE 0 END) AS is_favourite
                FROM meadows m
                INNER JOIN meadow_geometries g ON g.meadow_id = m.id
                LEFT JOIN user_favourite_meadows f
                    ON f.source_id = m.source_id AND f.user_id = :favUser
                WHERE ' . buildWhereClause($polygonWhere) . '
                ORDER BY m.area_m2 DESC
                LIMIT :limit
            ';
        } else {
            $polyParams = $params;
            $sql = '
                SELECT
                    m.id,
                    m.source_id,
                    m.source_type,
                    m.land_cover_code,
                    m.area_ha,
                    m.area_m2,
                    m.average_elevation_deviation_m,
                    m.largest_flat_patch_m2,
                    m.largest_flat_patch_share,
                    m.flat_area_share,
                    m.terrain_roughness_p80_m,
                    m.nearest_road_m,
                    m.nearest_path_m,
                    m.nearest_water_m,
                    m.nearest_river_m,
                    m.nearest_settlement_m,
                    m.nearest_building_m,
                    m.centroid_lat,
                    m.centroid_lng,
                    g.geom_geojson
                FROM meadows m
                INNER JOIN meadow_geometries g ON g.meadow_id = m.id
                WHERE ' . buildWhereClause($polygonWhere) . '
                ORDER BY m.area_m2 DESC
                LIMIT :limit
            ';
        }

        $statement = $pdo->prepare($sql);
        foreach ($polyParams as $key => $value) {
            if ($key === ':limit') {
                continue;
            }
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', POLYGON_RESULT_LIMIT, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        foreach ($rows as $row) {
            try {
                $geometry = json_decode((string) $row['geom_geojson'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                continue;
            }

            $props = [
                'id' => (int) $row['id'],
                'source_id' => $row['source_id'],
                'source_type' => $row['source_type'],
                'land_cover_code' => $row['land_cover_code'],
                'area_ha' => $row['area_ha'],
                'area_m2' => $row['area_m2'],
                'average_elevation_deviation_m' => $row['average_elevation_deviation_m'],
                'largest_flat_patch_m2' => $row['largest_flat_patch_m2'],
                'largest_flat_patch_share' => $row['largest_flat_patch_share'],
                'flat_area_share' => $row['flat_area_share'],
                'terrain_roughness_p80_m' => $row['terrain_roughness_p80_m'],
                'nearest_road_m' => $row['nearest_road_m'],
                'nearest_path_m' => $row['nearest_path_m'],
                'nearest_water_m' => $row['nearest_water_m'],
                'nearest_river_m' => $row['nearest_river_m'],
                'nearest_settlement_m' => $row['nearest_settlement_m'],
                'nearest_building_m' => $row['nearest_building_m'],
                'centroid_lat' => $row['centroid_lat'],
                'centroid_lng' => $row['centroid_lng'],
            ];
            if ($sessionUserId !== null) {
                $props['is_favourite'] = ((int) ($row['is_favourite'] ?? 0)) === 1;
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => $props,
            ];
        }
    }

    echo json_encode(
        [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'mode' => $mode,
                'count' => count($features),
                'bbox' => [$west, $south, $east, $north],
            ],
        ],
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    respondWithError(500, 'Došlo k chybě serveru.');
}
