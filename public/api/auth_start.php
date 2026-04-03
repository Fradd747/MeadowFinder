<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session_bootstrap.php';

meadowFinderStartSession();

if (!meadowFinderOAuthConfigured()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Google přihlášení není nakonfigurováno (chybí client ID / secret / redirect URI).';
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = [
    'client_id' => meadowFinderConfig()['google_client_id'],
    'redirect_uri' => meadowFinderConfig()['oauth_redirect_uri'],
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'online',
    'include_granted_scopes' => 'true',
    'prompt' => 'select_account',
];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

header('Location: ' . $url, true, 302);
exit;
