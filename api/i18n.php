<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function meteonexa_language(mixed $value): string
{
    $raw = strtolower(trim((string)$value));
    $raw = str_replace('_', '-', $raw);
    $code = explode('-', $raw)[0] ?: 'it';
    return in_array($code, ['it', 'en', 'fr', 'es', 'de'], true) ? $code : 'it';
}

function meteonexa_catalog(PDO $pdo, string $language): array
{
    static $cache = [];
    $locale = meteonexa_language($language);
    if (isset($cache[$locale])) return $cache[$locale];
    $statement = $pdo->prepare('SELECT text_key, translation FROM translations WHERE locale = :locale ORDER BY text_key');
    $statement->execute([':locale' => $locale]);
    $catalog = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)($row['text_key'] ?? '');
        if ($key !== '') $catalog[$key] = (string)($row['translation'] ?? '');
    }
    return $cache[$locale] = $catalog;
}

function meteonexa_baseline_catalog(string $language): array
{
    $path = meteonexa_baseline_sqlite_path();
    if (!is_file($path) || (int)@filesize($path) <= 0) return [];
    try {
        $baseline = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $baseline->exec('PRAGMA query_only = ON');
        $statement = $baseline->prepare('SELECT text_key, translation FROM translations WHERE locale = :locale ORDER BY text_key');
        $statement->execute([':locale'=>meteonexa_language($language)]);
        $catalog = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['text_key'] ?? '');
            if ($key !== '') $catalog[$key] = (string)($row['translation'] ?? '');
        }
        return $catalog;
    } catch (Throwable $error) { return []; }
}

function meteonexa_merged_catalog(PDO $pdo, string $language): array
{
    // Baseline provides new build keys; runtime values win so DB edits remain authoritative.
    return array_replace(meteonexa_baseline_catalog($language), meteonexa_catalog($pdo, $language));
}

function meteonexa_text(PDO $pdo, string $language, string $key, array $params = []): string
{
    $catalog = meteonexa_merged_catalog($pdo, $language);
    $value = (string)($catalog[$key] ?? $key);
    foreach ($params as $name => $replacement) {
        $value = str_replace(['{' . $name . '}', '${' . $name . '}'], (string)$replacement, $value);
    }
    return $value;
}

function meteonexa_i18n_supported_languages(PDO $pdo, string $language): array
{
    $catalog = meteonexa_merged_catalog($pdo, $language);
    $rows = [];
    foreach (['it', 'en', 'fr', 'es', 'de'] as $code) {
        $rows[] = ['code' => $code, 'label' => (string)($catalog['language.' . $code] ?? strtoupper($code))];
    }
    return $rows;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    require_once __DIR__ . '/bootstrap.php';
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') { header('Allow: GET'); respond(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'api.backend.method_not_allowed'],405); }
    try {
        $language = meteonexa_language($_GET['language'] ?? $_GET['browserLanguage'] ?? 'it');
        $runtimeConfig = load_config();
        $pdo = meteonexa_db($runtimeConfig);
        $publicConfig = $runtimeConfig;
        $version = hash('sha256', meteonexa_translation_version($pdo, $language) . '|' . (string)($publicConfig['app']['version'] ?? 'unknown'));
        $knownVersion = trim((string)($_GET['translationsUpdatedAt'] ?? ''));
        $includeCatalog = $knownVersion === '' || !hash_equals($version, $knownVersion);
        $catalog = $includeCatalog ? meteonexa_merged_catalog($pdo, $language) : [];
        if ($includeCatalog && $catalog === []) respond(['ok' => false, 'code' => 'CATALOG_EMPTY', 'message' => 'api.error.catalog'], 503);
        $languageCatalog = $includeCatalog ? $catalog : meteonexa_merged_catalog($pdo, $language);
        $supportedLanguages = array_map(static fn(string $code): array => ['code'=>$code,'label'=>(string)($languageCatalog['language.'.$code] ?? strtoupper($code))], ['it','en','fr','es','de']);
        respond([
            'ok' => true,
            'language' => $language,
            'supportedLanguages' => $supportedLanguages,
            'translations' => $includeCatalog ? $catalog : null,
            'translationsUpdatedAt' => $version,
            'storage' => meteonexa_pdo_driver($pdo),
            'version' => (string)($publicConfig['app']['version'] ?? 'unknown'),
        ]);
    } catch (Throwable $error) {
        respond(['ok' => false, 'code' => 'I18N_DATABASE_ERROR', 'message' => 'api.error.catalog'], 503);
    }
}
