<?php
declare(strict_types=1);

function meteonexa_translation_version(PDO $pdo, string $locale): string
{
    $locale = strtolower(trim($locale));
    if (!in_array($locale, ['it','en','fr','es','de'], true)) $locale = 'it';
    $revision = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='translation_revision'),'0')")->fetchColumn() ?: '0');
    // Include the newest row timestamp as well as the revision counter. This
    // keeps cache invalidation reliable on MySQL/MariaDB hosts where the
    // account is not allowed to create triggers: ordinary translation edits
    // still change updated_at and therefore change the catalogue version.
    $statement = $pdo->prepare("SELECT COUNT(*) AS row_count, COALESCE(MAX(updated_at), '') AS newest_update FROM translations WHERE locale=:locale");
    $statement->execute([':locale'=>$locale]);
    $row = $statement->fetch();
    $count = (int)($row['row_count'] ?? 0);
    $newestUpdate = (string)($row['newest_update'] ?? '');
    return hash('sha256', $locale . '|' . $revision . '|' . $count . '|' . $newestUpdate);
}

/**
 * Persist SMTP credentials only after they can be protected by the active
 * deployment secret. This supports two safe upgrade paths:
 *   1. import METEONEXA_SMTP_* environment values once and encrypt the password;
 *   2. recover the previous encrypted password using an app secret that is
 *      already present on the server, then immediately re-encrypt it with the
 *      current runtime secret.
 *
 * The distributable baseline remains unbound: it contains neither plaintext
 * credentials nor a decryption key.
 */
