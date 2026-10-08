<?php
declare(strict_types=1);

function meteonexa_translation_version(PDO $pdo, string $locale): string
{
    $locale = strtolower(trim($locale));
    if (!in_array($locale, ['it','en','fr','es','de'], true)) $locale = 'it';
    $revision = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='translation_revision'),'0')")->fetchColumn() ?: '0');
    
    
    
    
    $statement = $pdo->prepare("SELECT COUNT(*) AS row_count, COALESCE(MAX(updated_at), '') AS newest_update FROM translations WHERE locale=:locale");
    $statement->execute([':locale'=>$locale]);
    $row = $statement->fetch();
    $count = (int)($row['row_count'] ?? 0);
    $newestUpdate = (string)($row['newest_update'] ?? '');
    return hash('sha256', $locale . '|' . $revision . '|' . $count . '|' . $newestUpdate);
}












