<?php
declare(strict_types=1);

function meteonexa_encryption_key(array $config, string $context = 'smtp'): string
{
    $secret = trim((string)($config['auth']['app_secret'] ?? ''));
    if (strlen($secret) < 32) {
        throw new RuntimeException('AUTH_SECRET_TOO_SHORT');
    }
    return hash('sha256', 'meteonexa-' . $context . '|' . $secret, true);
}

function meteonexa_encrypt_value(string $plainText, array $config, string $context): string
{
    if ($plainText === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $aad = 'meteonexa-' . $context . '-v1';
    $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', meteonexa_encryption_key($config, $context), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if (!is_string($cipherText)) throw new RuntimeException('ENCRYPTION_FAILED');
    return 'enc:v1:' . base64_encode($iv . $tag . $cipherText);
}

function meteonexa_decrypt_value(string $encrypted, array $config, string $context): string
{
    if ($encrypted === '') return '';
    if (!str_starts_with($encrypted, 'enc:v1:')) throw new RuntimeException(meteonexa_backend_text('api.backend.protected_format_invalid'));
    $payload = base64_decode(substr($encrypted, 7), true);
    if (!is_string($payload) || strlen($payload) < 29) throw new RuntimeException(meteonexa_backend_text('api.backend.protected_value_invalid'));
    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $cipherText = substr($payload, 28);
    $plainText = openssl_decrypt($cipherText, 'aes-256-gcm', meteonexa_encryption_key($config, $context), OPENSSL_RAW_DATA, $iv, $tag, 'meteonexa-' . $context . '-v1');
    if (!is_string($plainText)) throw new RuntimeException('DECRYPTION_FAILED');
    return $plainText;
}

function meteonexa_encrypt_secret(string $plainText, array $config): string
{
    return meteonexa_encrypt_value($plainText, $config, 'smtp');
}

function meteonexa_decrypt_secret(string $encrypted, array $config): string
{
    return meteonexa_decrypt_value($encrypted, $config, 'smtp');
}

function meteonexa_secret_verifier(array $config): string
{
    $secret = trim((string)($config['auth']['app_secret'] ?? ''));
    if (strlen($secret) < 32) throw new RuntimeException('AUTH_SECRET_TOO_SHORT');
    return hash_hmac('sha256', 'meteonexa-deployment-secret-v1', $secret);
}
