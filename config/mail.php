<?php
// config/mail.php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(
    dirname(__DIR__) . '/monitoring'
);

$dotenv->safeLoad();

$required = [
    'MAIL_HOST',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_PORT',
    'MAIL_ENCRYPTION',
    'MAIL_FROM_EMAIL',
    'MAIL_FROM_NAME',
    'APP_URL',
];

$config = [];

foreach ($required as $key) {
    $value = $_ENV[$key] ?? '';

    if (trim((string) $value) === '') {
        throw new RuntimeException(
            "Missing required mail configuration: {$key}"
        );
    }

    $config[$key] = $value;
}

$port = filter_var(
    $config['MAIL_PORT'],
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);

if ($port === false) {
    throw new RuntimeException('Invalid MAIL_PORT configuration.');
}

$encryption = strtolower($config['MAIL_ENCRYPTION']);

if (!in_array($encryption, ['tls', 'ssl'], true)) {
    throw new RuntimeException('MAIL_ENCRYPTION must be tls or ssl.');
}

$appUrl = rtrim($config['APP_URL'], '/');

if (!filter_var($appUrl, FILTER_VALIDATE_URL) ||
    !in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
    throw new RuntimeException('Invalid APP_URL configuration.');
}

return [
    'host'       => $config['MAIL_HOST'],
    'username'   => $config['MAIL_USERNAME'],
    'password'   => $config['MAIL_PASSWORD'],
    'port'       => $port,
    'encryption' => $encryption,
    'from_email' => $config['MAIL_FROM_EMAIL'],
    'from_name'  => $config['MAIL_FROM_NAME'],
    'app_url'    => $appUrl,
];
