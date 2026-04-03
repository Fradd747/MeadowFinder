<?php

declare(strict_types=1);

/**
 * Shared session + URL helpers for OAuth and favourites.
 */
function meadowFinderAppHomeUrl(): string
{
    $config = meadowFinderConfig();
    if (!empty($config['app_home_url'])) {
        return rtrim((string) $config['app_home_url'], '/') . '/';
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/auth_callback.php';
    $script = str_replace('\\', '/', (string) $script);
    $apiDir = dirname($script);
    $parent = dirname($apiDir);
    if ($parent === '/' || $parent === '.' || $parent === '') {
        return '/';
    }

    return rtrim($parent, '/') . '/';
}

function meadowFinderIsHttpsRequest(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return strtolower((string) $forwarded) === 'https';
}

function meadowFinderStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = meadowFinderIsHttpsRequest();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function meadowFinderSessionUserId(): ?int
{
    meadowFinderStartSession();
    if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}

function meadowFinderSetSessionUserId(int $userId): void
{
    meadowFinderStartSession();
    $_SESSION['user_id'] = $userId;
}

function meadowFinderClearSessionUser(): void
{
    meadowFinderStartSession();
    unset($_SESSION['user_id'], $_SESSION['oauth_state']);
}

function meadowFinderOAuthConfigured(): bool
{
    $c = meadowFinderConfig();

    return $c['google_client_id'] !== ''
        && $c['google_client_secret'] !== ''
        && $c['oauth_redirect_uri'] !== '';
}
