<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

const POLYGON_RESULT_LIMIT = 2500;

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
    $bbox = $_GET['bbox'] ?? '12.0,48.4,19.0,51.1';
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

function countMatchingMeadows(PDO $pdo, array $where, array $params): int
{
    $sql = 'SELECT COUNT(*) FROM meadows WHERE ' . buildWhereClause($where);
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
    } elseif ($zoom <= 8) {
        $columns = 12.0;
        $rows = 9.0;
    } elseif ($zoom <= 10) {
        $columns = 18.0;
        $rows = 14.0;
    } elseif ($zoom <= 12) {
        $columns = 26.0;
        $rows = 20.0;
    } else {
        $columns = 36.0;
        $rows = 28.0;
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

    $where = [
        'min_lng <= :east',
        'max_lng >= :west',
        'min_lat <= :north',
        'max_lat >= :south',
    ];
    $params = [
        ':west' => $west,
        ':south' => $south,
        ':east' => $east,
        ':north' => $north,
    ];

    if ($minArea !== null) {
        $where[] = 'area_m2 >= :minArea';
        $params[':minArea'] = $minArea;
    }
    if ($maxArea !== null) {
        $where[] = 'area_m2 <= :maxArea';
        $params[':maxArea'] = $maxArea;
    }
    if ($minRoad !== null) {
        $where[] = 'nearest_road_m >= :minRoad';
        $params[':minRoad'] = $minRoad;
    }
    if ($maxRoad !== null) {
        $where[] = 'nearest_road_m <= :maxRoad';
        $params[':maxRoad'] = $maxRoad;
    }
    if ($minPath !== null) {
        $where[] = 'nearest_path_m >= :minPath';
        $params[':minPath'] = $minPath;
    }
    if ($maxPath !== null) {
        $where[] = 'nearest_path_m <= :maxPath';
        $params[':maxPath'] = $maxPath;
    }
    if ($minWater !== null) {
        $where[] = 'nearest_water_m >= :minWater';
        $params[':minWater'] = $minWater;
    }
    if ($maxWater !== null) {
        $where[] = 'nearest_water_m <= :maxWater';
        $params[':maxWater'] = $maxWater;
    }
    if ($minRiver !== null) {
        $where[] = 'nearest_river_m >= :minRiver';
        $params[':minRiver'] = $minRiver;
    }
    if ($maxRiver !== null) {
        $where[] = 'nearest_river_m <= :maxRiver';
        $params[':maxRiver'] = $maxRiver;
    }
    if ($minSettlement !== null) {
        $where[] = 'nearest_settlement_m >= :minSettlement';
        $params[':minSettlement'] = $minSettlement;
    }
    if ($maxSettlement !== null) {
        $where[] = 'nearest_settlement_m <= :maxSettlement';
        $params[':maxSettlement'] = $maxSettlement;
    }

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

        $sql = '
            SELECT
                AVG(centroid_lat) AS centroid_lat,
                AVG(centroid_lng) AS centroid_lng,
                COUNT(*) AS meadow_count
            FROM meadows
            WHERE ' . buildWhereClause($where) . '
            GROUP BY
                FLOOR((centroid_lng - :clusterWest) / :lngStep),
                FLOOR((centroid_lat - :clusterSouth) / :latStep)
            ORDER BY meadow_count DESC
        ';

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

            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$lng, $lat],
                ],
                'properties' => [
                    'centroid_lat' => $lat,
                    'centroid_lng' => $lng,
                    'cluster_count' => (int) $row['meadow_count'],
                ],
            ];
        }
    } else {
        $sql = '
            SELECT
                source_id,
                source_type,
                land_cover_code,
                area_ha,
                area_m2,
                nearest_road_m,
                nearest_path_m,
                nearest_water_m,
                nearest_river_m,
                nearest_settlement_m,
                centroid_lat,
                centroid_lng,
                geom_geojson
            FROM meadows
            WHERE ' . buildWhereClause($where) . '
            ORDER BY area_m2 DESC
            LIMIT :limit
        ';

        $statement = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
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

            $features[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'source_id' => $row['source_id'],
                    'source_type' => $row['source_type'],
                    'land_cover_code' => $row['land_cover_code'],
                    'area_ha' => $row['area_ha'],
                    'area_m2' => $row['area_m2'],
                    'nearest_road_m' => $row['nearest_road_m'],
                    'nearest_path_m' => $row['nearest_path_m'],
                    'nearest_water_m' => $row['nearest_water_m'],
                    'nearest_river_m' => $row['nearest_river_m'],
                    'nearest_settlement_m' => $row['nearest_settlement_m'],
                    'centroid_lat' => $row['centroid_lat'],
                    'centroid_lng' => $row['centroid_lng'],
                ],
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
