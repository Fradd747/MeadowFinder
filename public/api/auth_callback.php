<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/session_bootstrap.php';

meadowFinderStartSession();

function authFailRedirect(string $message): never
{
    $home = meadowFinderAppHomeUrl();
    $q = http_build_query(['auth_error' => $message], '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $home . '?' . $q, true, 302);
    exit;
}

if (!meadowFinderOAuthConfigured()) {
    authFailRedirect('oauth_not_configured');
}

$state = isset($_GET['state']) && is_scalar($_GET['state']) ? (string) $_GET['state'] : '';
$expected = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    authFailRedirect('invalid_state');
}

if (isset($_GET['error'])) {
    authFailRedirect('provider_denied');
}

$code = isset($_GET['code']) && is_scalar($_GET['code']) ? (string) $_GET['code'] : '';
if ($code === '') {
    authFailRedirect('missing_code');
}

$config = meadowFinderConfig();
$tokenPayload = http_build_query(
    [
        'code' => $code,
        'client_id' => $config['google_client_id'],
        'client_secret' => $config['google_client_secret'],
        'redirect_uri' => $config['oauth_redirect_uri'],
        'grant_type' => 'authorization_code',
    ],
    '',
    '&'
);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $tokenPayload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$tokenResponse = curl_exec($ch);
$tokenHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($tokenResponse === false || $tokenHttp !== 200) {
    authFailRedirect('token_exchange_failed');
}

try {
    /** @var array<string, mixed> $tokenJson */
    $tokenJson = json_decode($tokenResponse, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    authFailRedirect('token_invalid');
}

$accessToken = isset($tokenJson['access_token']) && is_string($tokenJson['access_token'])
    ? $tokenJson['access_token']
    : '';
if ($accessToken === '') {
    authFailRedirect('no_access_token');
}

$ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$userResponse = curl_exec($ch);
$userHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($userResponse === false || $userHttp !== 200) {
    authFailRedirect('userinfo_failed');
}

try {
    /** @var array<string, mixed> $userJson */
    $userJson = json_decode($userResponse, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    authFailRedirect('userinfo_invalid');
}

$sub = isset($userJson['sub']) && is_string($userJson['sub']) ? $userJson['sub'] : '';
if ($sub === '') {
    authFailRedirect('missing_sub');
}

$emailVerified = !empty($userJson['email_verified']);
if (!$emailVerified) {
    authFailRedirect('email_not_verified');
}

$email = isset($userJson['email']) && is_string($userJson['email']) ? $userJson['email'] : '';
if ($email === '' || strlen($email) > 320) {
    authFailRedirect('invalid_email');
}

$name = isset($userJson['name']) && is_string($userJson['name']) ? $userJson['name'] : '';
if ($name === '') {
    $name = $email;
}
if (strlen($name) > 255) {
    $name = substr($name, 0, 255);
}

$picture = null;
if (isset($userJson['picture']) && is_string($userJson['picture']) && $userJson['picture'] !== '') {
    $picture = strlen($userJson['picture']) > 2048 ? substr($userJson['picture'], 0, 2048) : $userJson['picture'];
}

try {
    $pdo = meadowFinderPdo();
    $sql = '
        INSERT INTO users (google_sub, email, display_name, avatar_url)
        VALUES (:sub, :email, :name, :avatar)
        ON DUPLICATE KEY UPDATE
            email = VALUES(email),
            display_name = VALUES(display_name),
            avatar_url = VALUES(avatar_url),
            updated_at = CURRENT_TIMESTAMP
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sub' => $sub,
        ':email' => $email,
        ':name' => $name,
        ':avatar' => $picture,
    ]);

    $id = (int) $pdo->lastInsertId();
    if ($id === 0) {
        $sel = $pdo->prepare('SELECT id FROM users WHERE google_sub = :sub LIMIT 1');
        $sel->execute([':sub' => $sub]);
        $row = $sel->fetch();
        $id = $row ? (int) $row['id'] : 0;
    }

    if ($id <= 0) {
        authFailRedirect('user_persist_failed');
    }

    meadowFinderSetSessionUserId($id);
} catch (Throwable $e) {
    authFailRedirect('database_error');
}

header('Location: ' . meadowFinderAppHomeUrl(), true, 302);
exit;
