<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

meadowFinderStartSession();

$userId = meadowFinderSessionUserId();

if ($userId === null) {
    echo json_encode(
        [
            'user' => null,
            'oauth_configured' => meadowFinderOAuthConfigured(),
        ],
        JSON_THROW_ON_ERROR
    );
    exit;
}

try {
    $pdo = meadowFinderPdo();
    $stmt = $pdo->prepare(
        'SELECT id, display_name, email, avatar_url FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        meadowFinderClearSessionUser();
        echo json_encode(
            [
                'user' => null,
                'oauth_configured' => meadowFinderOAuthConfigured(),
            ],
            JSON_THROW_ON_ERROR
        );
        exit;
    }

    echo json_encode(
        [
            'user' => [
                'id' => (int) $row['id'],
                'display_name' => $row['display_name'],
                'email' => $row['email'],
                'avatar_url' => $row['avatar_url'],
            ],
            'oauth_configured' => meadowFinderOAuthConfigured(),
        ],
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error'], JSON_THROW_ON_ERROR);
}
