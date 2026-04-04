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

function countMatchingMeadows(PDO $pdo, array $where, array $params): int
{
    $sql = 'SELECT COUNT(*) FROM meadows m WHERE ' . buildWhereClause($where);
    $statement = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value);
    }
    $statement->execute();

    return (int) $statement->fetchColumn();
}

function clusterSteps(array $bbox, int $zoom): array
{
    [$west, $south, $east, $north] = $bbox;

    if ($zoom <= 6) {
        $columns = 8.0;
        $rows = 6.0;
    } elseif ($zoom === 7) {
        $columns = 8.0;
        $rows = 6.0;
    } elseif ($zoom === 8) {
        $columns = 10.0;
        $rows = 8.0;
    } elseif ($zoom === 9) {
        $columns = 14.0;
        $rows = 10.0;
    } elseif ($zoom === 10) {
        $columns = 14.0;
        $rows = 10.0;
    } elseif ($zoom === 11) {
        $columns = 16.0;
        $rows = 12.0;
    } elseif ($zoom === 12) {
        $columns = 18.0;
        $rows = 14.0;
    } else {
        $columns = 80.0;
        $rows = 60.0;
    }

    $lngStep = max(($east - $west) / $columns, 0.01);
    $latStep = max(($north - $south) / $rows, 0.01);

    return [$lngStep, $latStep];
}

try {
    $config = meadowFinderConfig();
    $pdo = meadowFinderPdo();

    [$west, $south, $east, $north] = readBbox();
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

    $sessionUserId = meadowFinderSessionUserId();

    $features = [];
    $totalCount = countMatchingMeadows($pdo, $where, $params);

    if ($mode === 'clusters') {
        [$lngStep, $latStep] = clusterSteps([$west, $south, $east, $north], $zoom);
        $clusterParams = $params + [
            ':clusterWest' => $west,
            ':clusterSouth' => $south,
            ':lngStep' => $lngStep,
            ':latStep' => $latStep,
        ];

        if ($sessionUserId !== null) {
            $clusterParams[':favUser'] = $sessionUserId;
            $sql = '
                SELECT
                    AVG(m.centroid_lat) AS centroid_lat,
                    AVG(m.centroid_lng) AS centroid_lng,
                    COUNT(*) AS meadow_count,
                    MAX(CASE WHEN f.meadow_id IS NOT NULL THEN 1 ELSE 0 END) AS has_favourite
                FROM meadows m
                LEFT JOIN user_favourite_meadows f
                    ON f.meadow_id = m.id AND f.user_id = :favUser
                WHERE ' . buildWhereClause($where) . '
                GROUP BY
                    FLOOR((m.centroid_lng - :clusterWest) / :lngStep),
                    FLOOR((m.centroid_lat - :clusterSouth) / :latStep)
                ORDER BY meadow_count DESC
            ';
        } else {
            $sql = '
                SELECT
                    AVG(m.centroid_lat) AS centroid_lat,
                    AVG(m.centroid_lng) AS centroid_lng,
                    COUNT(*) AS meadow_count
                FROM meadows m
                WHERE ' . buildWhereClause($where) . '
                GROUP BY
                    FLOOR((m.centroid_lng - :clusterWest) / :lngStep),
                    FLOOR((m.centroid_lat - :clusterSouth) / :latStep)
                ORDER BY meadow_count DESC
            ';
        }

        $statement = $pdo->prepare($sql);
        foreach ($clusterParams as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();
        $rows = $statement->fetchAll();

        foreach ($rows as $row) {
            $lat = isset($row['centroid_lat']) ? (float) $row['centroid_lat'] : null;
            $lng = isset($row['centroid_lng']) ? (float) $row['centroid_lng'] : null;
            if ($lat === null || $lng === null) {
                continue;
            }

            $props = [
                'centroid_lat' => $lat,
                'centroid_lng' => $lng,
                'cluster_count' => (int) $row['meadow_count'],
            ];
            if ($sessionUserId !== null) {
                $props['has_favourite'] = ((int) ($row['has_favourite'] ?? 0)) === 1;
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
                    (CASE WHEN f.meadow_id IS NOT NULL THEN 1 ELSE 0 END) AS is_favourite
                FROM meadows m
                INNER JOIN meadow_geometries g ON g.meadow_id = m.id
                LEFT JOIN user_favourite_meadows f
                    ON f.meadow_id = m.id AND f.user_id = :favUser
                WHERE ' . buildWhereClause($where) . '
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
                WHERE ' . buildWhereClause($where) . '
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
                'total_count' => $totalCount,
                'truncated' => $mode === 'polygons' && $totalCount > POLYGON_RESULT_LIMIT,
                'bbox' => [$west, $south, $east, $north],
            ],
        ],
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    respondWithError(500, 'Došlo k chybě serveru.');
}
