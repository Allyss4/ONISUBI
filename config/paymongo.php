<?php

declare(strict_types=1);

function paymongo_secret_key(): string
{
    $values = [
    $_ENV['PAYMONGO_TEST_SECRET_KEY'] ?? null,
    $_SERVER['PAYMONGO_TEST_SECRET_KEY'] ?? null,
    getenv('PAYMONGO_TEST_SECRET_KEY') ?: null,
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
    $_ENV['PAYMONGO_TEST_WEBHOOK_SECRET'] ?? null,
    $_SERVER['PAYMONGO_TEST_WEBHOOK_SECRET'] ?? null,
    getenv('PAYMONGO_TEST_WEBHOOK_SECRET') ?: null,
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