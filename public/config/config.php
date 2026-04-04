<?php

declare(strict_types=1);

function meadowFinderConfig(): array
{
    $config = [
        'db_host' => getenv('MEADOW_DB_HOST') ?: '127.0.0.1',
        'db_port' => getenv('MEADOW_DB_PORT') ?: '3306',
        'db_name' => getenv('MEADOW_DB_NAME') ?: 'meadow_finder',
        'db_user' => getenv('MEADOW_DB_USER') ?: 'root',
        'db_password' => getenv('MEADOW_DB_PASSWORD') ?: '',
        'max_results' => (int) (getenv('MEADOW_MAX_RESULTS') ?: 600),
        'google_client_id' => getenv('MEADOW_GOOGLE_CLIENT_ID') ?: '',
        'google_client_secret' => getenv('MEADOW_GOOGLE_CLIENT_SECRET') ?: '',
        'oauth_redirect_uri' => getenv('MEADOW_OAUTH_REDIRECT_URI') ?: '',
        // Optional absolute URL to the app home (e.g. https://example.com/). If empty, derived from SCRIPT_NAME.
        'app_home_url' => getenv('MEADOW_APP_HOME_URL') ?: '',
    ];

    $localConfigFile = __DIR__ . '/config.local.php';
    if (is_file($localConfigFile)) {
        /** @var array<string, mixed> $localConfig */
        $localConfig = require $localConfigFile;
        $config = array_merge($config, $localConfig);
    }

    return $config;
}

function meadowFinderPdo(): PDO
{
    $config = meadowFinderConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_port'],
        $config['db_name']
    );

    return new PDO(
        $dsn,
        (string) $config['db_user'],
        (string) $config['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => true,
        ]
    );
}
