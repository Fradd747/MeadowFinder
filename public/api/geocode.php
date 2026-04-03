<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/** WGS84 envelope of Czech Republic; keep in sync with meadows.php and app.js */
const CZECH_REPUBLIC_WEST = 12.07;
const CZECH_REPUBLIC_SOUTH = 48.53;
const CZECH_REPUBLIC_EAST = 18.88;
const CZECH_REPUBLIC_NORTH = 51.07;

const NOMINATIM_SEARCH_URL = 'https://nominatim.openstreetmap.org/search';

/** Identifiable User-Agent per https://operations.osmfoundation.org/policies/nominatim/ */
const NOMINATIM_USER_AGENT = 'Vyhledavac-luk-MeadowFinder/1.0 (Czech meadow map; +https://github.com/)';

function respondWithError(int $statusCode, string $message): never
{
    http_response_code($statusCode);
    echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    exit;
}

$q = isset($_GET['q']) && is_scalar($_GET['q']) ? trim((string) $_GET['q']) : '';
if ($q === '') {
    respondWithError(400, 'Chybí parametr q.');
}
if (strlen($q) > 200) {
    respondWithError(400, 'Dotaz je příliš dlouhý.');
}

// viewbox: left, top, right, bottom (min lon, max lat, max lon, min lat)
$viewbox = sprintf(
    '%F,%F,%F,%F',
    CZECH_REPUBLIC_WEST,
    CZECH_REPUBLIC_NORTH,
    CZECH_REPUBLIC_EAST,
    CZECH_REPUBLIC_SOUTH
);

$query = http_build_query(
    [
        'q' => $q,
        'format' => 'json',
        'limit' => 5,
        'countrycodes' => 'cz',
        'viewbox' => $viewbox,
        'bounded' => '1',
        'addressdetails' => '0',
    ],
    '',
    '&',
    PHP_QUERY_RFC3986
);

$url = NOMINATIM_SEARCH_URL . '?' . $query;

if (!function_exists('curl_init')) {
    respondWithError(500, 'Geokódování není na serveru dostupné.');
}

$ch = curl_init($url);
if ($ch === false) {
    respondWithError(500, 'Geokódování se nepodařilo spustit.');
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
    ],
    CURLOPT_USERAGENT => NOMINATIM_USER_AGENT,
    CURLOPT_TIMEOUT => 12,
]);

$body = curl_exec($ch);
$errno = curl_errno($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0 || $body === false) {
    respondWithError(502, 'Služba geokódování je nedostupná.');
}

if ($status < 200 || $status >= 300) {
    respondWithError(502, 'Služba geokódování vrátila chybu.');
}

try {
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respondWithError(502, 'Neplatná odpověď geokódování.');
}

if (!is_array($decoded)) {
    respondWithError(502, 'Neplatná odpověď geokódování.');
}

$out = [];
foreach ($decoded as $row) {
    if (!is_array($row)) {
        continue;
    }
    $item = [
        'lat' => isset($row['lat']) ? (string) $row['lat'] : '',
        'lon' => isset($row['lon']) ? (string) $row['lon'] : '',
        'display_name' => isset($row['display_name']) ? (string) $row['display_name'] : '',
    ];
    if (
        isset($row['boundingbox'])
        && is_array($row['boundingbox'])
        && count($row['boundingbox']) === 4
    ) {
        $item['boundingbox'] = [
            (string) $row['boundingbox'][0],
            (string) $row['boundingbox'][1],
            (string) $row['boundingbox'][2],
            (string) $row['boundingbox'][3],
        ];
    }
    $out[] = $item;
}

echo json_encode($out, JSON_THROW_ON_ERROR);
