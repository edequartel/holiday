<?php

function holiday_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $envPath = getenv('HOLIDAY_SECRETS_PATH');
    $homePath = dirname(__DIR__, 3);
    $paths = array_filter([
        $envPath ?: null,
        $homePath . '/private/holiday-secrets.php',
        $homePath . '/private/secrets.php',
        $homePath . '/holiday-private/secrets.php',
        dirname(__DIR__) . '/secrets.php',
    ]);

    foreach ($paths as $path) {
        if (is_readable($path)) {
            $loaded = require $path;
            if (!is_array($loaded)) {
                throw new RuntimeException('Secrets file must return a PHP array.');
            }
            $config = $loaded;
            return $config;
        }
    }

    throw new RuntimeException(
        'Missing secrets file. Put it in /private/holiday-secrets.php outside public_html, ' .
        'or set HOLIDAY_SECRETS_PATH.'
    );
}
