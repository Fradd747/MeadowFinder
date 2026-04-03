<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
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
    $stmt = $pdo->prepare('SELECT meadow_id FROM user_favourite_meadows WHERE user_id = :uid ORDER BY meadow_id');
    $stmt->execute([':uid' => $userId]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'meadow_id'));
    echo json_encode(['ids' => $ids], JSON_THROW_ON_ERROR);
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

    if (!isset($body['meadow_id']) || !is_numeric($body['meadow_id'])) {
        favouritesRespondError(400, 'Chybí platné meadow_id.');
    }

    $meadowId = (int) $body['meadow_id'];
    if ($meadowId <= 0) {
        favouritesRespondError(400, 'Neplatné meadow_id.');
    }

    $check = $pdo->prepare('SELECT 1 FROM meadows WHERE id = :id LIMIT 1');
    $check->execute([':id' => $meadowId]);
    if (!$check->fetchColumn()) {
        favouritesRespondError(404, 'Louka neexistuje.');
    }

    $ins = $pdo->prepare(
        'INSERT IGNORE INTO user_favourite_meadows (user_id, meadow_id) VALUES (:uid, :mid)'
    );
    $ins->execute([':uid' => $userId, ':mid' => $meadowId]);

    echo json_encode(['ok' => true, 'meadow_id' => $meadowId], JSON_THROW_ON_ERROR);
    exit;
}

if ($method === 'DELETE') {
    $mid = $_GET['meadow_id'] ?? '';
    if (!is_scalar($mid) || !is_numeric((string) $mid)) {
        favouritesRespondError(400, 'Chybí platné meadow_id.');
    }
    $meadowId = (int) $mid;
    if ($meadowId <= 0) {
        favouritesRespondError(400, 'Neplatné meadow_id.');
    }

    $del = $pdo->prepare(
        'DELETE FROM user_favourite_meadows WHERE user_id = :uid AND meadow_id = :mid'
    );
    $del->execute([':uid' => $userId, ':mid' => $meadowId]);

    echo json_encode(['ok' => true, 'meadow_id' => $meadowId], JSON_THROW_ON_ERROR);
    exit;
}

favouritesRespondError(405, 'Metoda není podporována.');
