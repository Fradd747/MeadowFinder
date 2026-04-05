<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function favouritesRespondError(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
    exit;
}

$userId = meadowFinderSessionUserId();
if ($userId === null) {
    favouritesRespondError(401, 'Vyžadováno přihlášení.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = meadowFinderPdo();
} catch (Throwable $e) {
    favouritesRespondError(500, 'Chyba serveru.');
}

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT source_id FROM user_favourite_meadows WHERE user_id = :uid ORDER BY source_id');
    $stmt->execute([':uid' => $userId]);
    $sourceIds = array_map('strval', array_column($stmt->fetchAll(), 'source_id'));
    echo json_encode(['source_ids' => $sourceIds], JSON_THROW_ON_ERROR);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    try {
        /** @var array<string, mixed> $body */
        $body = $raw !== '' && $raw !== false
            ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
            : [];
    } catch (JsonException $e) {
        favouritesRespondError(400, 'Neplatné JSON tělo.');
    }

    $sourceId = isset($body['source_id']) && is_scalar($body['source_id'])
        ? trim((string) $body['source_id'])
        : '';
    if ($sourceId === '') {
        favouritesRespondError(400, 'Chybí platné source_id.');
    }

    $check = $pdo->prepare('SELECT 1 FROM meadows WHERE source_id = :sourceId LIMIT 1');
    $check->execute([':sourceId' => $sourceId]);
    if (!$check->fetchColumn()) {
        favouritesRespondError(404, 'Louka neexistuje.');
    }

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO user_favourite_meadows (user_id, source_id) VALUES (:uid, :sourceId)'
    );
    $ins->execute([':uid' => $userId, ':sourceId' => $sourceId]);

    echo json_encode(['ok' => true, 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
    exit;
}

if ($method === 'DELETE') {
    $sourceId = $_GET['source_id'] ?? '';
    if (!is_scalar($sourceId) || trim((string) $sourceId) === '') {
        favouritesRespondError(400, 'Chybí platné source_id.');
    }
    $sourceId = trim((string) $sourceId);

    $del = $pdo->prepare(
        'DELETE FROM user_favourite_meadows WHERE user_id = :uid AND source_id = :sourceId'
    );
    $del->execute([':uid' => $userId, ':sourceId' => $sourceId]);

    echo json_encode(['ok' => true, 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
    exit;
}

favouritesRespondError(405, 'Metoda není podporována.');
