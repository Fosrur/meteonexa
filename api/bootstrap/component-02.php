<?php
declare(strict_types=1);
function auth_secret(array $config): string
{
    $secret = trim((string)($config['auth']['app_secret'] ?? ''));
    if (strlen($secret) < 32) {
        respond(['ok' => false, 'code' => 'APP_SECRET_MISSING', 'message' => 'api.backend.app_secret_missing'], 503);
    }
    return $secret;
}

function mask_email(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $visible = meteonexa_text_substr($name, 0, min(2, meteonexa_text_length($name)));
    return $visible . str_repeat('•', max(3, meteonexa_text_length($name) - meteonexa_text_length($visible))) . '@' . $domain;
}

function smtp_is_configured(array $config): bool
{
    $smtp = $config['smtp'] ?? [];
    $host = trim((string)($smtp['host'] ?? ''));
    $fromEmail = trim((string)($smtp['from_email'] ?? ''));
    $username = trim((string)($smtp['username'] ?? ''));
    $password = (string)($smtp['password'] ?? '');
    if ($host === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) return false;
    
    
    return $username === '' || $password !== '';
}
