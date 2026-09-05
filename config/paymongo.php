<?php

declare(strict_types=1);

function paymongo_secret_key(): string
{
    $values = [
        $_ENV['PAYMONGO_SECRET_KEY'] ?? $_SERVER['PAYMONGO_SECRET_KEY'] ?? getenv('PAYMONGO_SECRET_KEY'),
        $_ENV['PAYMONGO_TEST_SECRET_KEY'] ?? $_SERVER['PAYMONGO_TEST_SECRET_KEY'] ?? getenv('PAYMONGO_TEST_SECRET_KEY'),
    ];

    foreach ($values as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

function paymongo_webhook_secret(): string
{
    $values = [
        $_ENV['PAYMONGO_WEBHOOK_SECRET'] ?? $_SERVER['PAYMONGO_WEBHOOK_SECRET'] ?? getenv('PAYMONGO_WEBHOOK_SECRET'),
        $_ENV['PAYMONGO_TEST_WEBHOOK_SECRET'] ?? $_SERVER['PAYMONGO_TEST_WEBHOOK_SECRET'] ?? getenv('PAYMONGO_TEST_WEBHOOK_SECRET'),
    ];

    foreach ($values as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

function paymongo_api_base_url(): string
{
    return 'https://api.paymongo.com';
}
