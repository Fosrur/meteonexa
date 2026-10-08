<?php
declare(strict_types=1);















function meteonexa_diagnostics_admin_emails(array $config): array
{
    $emails = [];
    foreach ((array)($config['qa']['admin_emails'] ?? []) as $candidate) {
        $candidate = strtolower(trim((string)$candidate));
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) $emails[$candidate] = true;
    }
    return array_keys($emails);
}

function meteonexa_diagnostics_authorized(PDO $pdo, array $config, array $session): bool
{
    $email = strtolower(trim((string)($session['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    foreach (meteonexa_diagnostics_admin_emails($config) as $allowed) {
        if (hash_equals($allowed, $email)) return true;
    }

    try {
        $statement = $pdo->prepare("SELECT meta_value FROM app_metadata WHERE meta_key='qa_admin_email_hashes' LIMIT 1");
        $statement->execute();
        $raw = trim((string)($statement->fetchColumn() ?: ''));
        if ($raw !== '') {
            $candidateHash = meteonexa_hmac_identifier('qa-admin-email:' . $email, auth_secret($config));
            foreach (preg_split('/[,;\s]+/', $raw) ?: [] as $stored) {
                $stored = strtolower(trim((string)$stored));
                if (preg_match('/^[a-f0-9]{64}$/', $stored) === 1 && hash_equals($stored, $candidateHash)) return true;
            }
        }
    } catch (Throwable $ignored) { }
    return false;
}

function require_diagnostics_admin(PDO $pdo, array $config, array $session): void
{
    if (!meteonexa_diagnostics_authorized($pdo, $config, $session)) {
        respond(['ok'=>false,'code'=>'ADMIN_REQUIRED','message'=>'api.security.access_denied'], 403);
    }
}
