<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'ruts36',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'enabled' => filter_var(getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'from' => getenv('MAIL_FROM') ?: '',
    ],
    'sso' => [
        'enabled' => filter_var(getenv('SSO_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'issuer' => getenv('SSO_ISSUER') ?: '',
        'client_id' => getenv('SSO_CLIENT_ID') ?: '',
        'client_secret' => getenv('SSO_CLIENT_SECRET') ?: '',
    ],
];
