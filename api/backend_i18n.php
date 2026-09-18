<?php
declare(strict_types=1);

require_once __DIR__ . '/storage_helpers.php';

function meteonexa_backend_language(mixed $explicit = null): string
{
    $payload = is_array($GLOBALS['meteonexa_request_payload'] ?? null)
        ? $GLOBALS['meteonexa_request_payload']
        : [];
    $candidates = [
        $explicit,
        $payload['language'] ?? null,
        $payload['locale'] ?? null,
        $_GET['language'] ?? null,
        $_GET['locale'] ?? null,
        $_GET['lang'] ?? null,
        $_GET['browserLanguage'] ?? null,
        $_SERVER['HTTP_X_METEONEXA_LANGUAGE'] ?? null,
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $raw = strtolower(trim((string)$candidate));
        if ($raw === '') continue;
        $raw = str_replace('_', '-', $raw);
        $code = explode('-', explode(',', $raw)[0])[0] ?: 'it';
        if (in_array($code, ['it', 'en', 'fr', 'es', 'de'], true)) return $code;
    }
    return 'it';
}

/**
 * Resolve a single backend translation lazily.
 *
 * Backend/API calls usually need only a handful of strings. Older builds loaded
 * two or three complete ~2.8k-row catalogs on the first translation lookup of
 * every PHP request. This keyed cache keeps direct DB editability while reducing
 * SQLite reads and memory pressure on shared hosting.
 */
function meteonexa_backend_text(
    string $key,
    array $params = [],
    mixed $language = null
): string {
    static $cache = [];
    static $pdo = null;
    static $databaseUnavailable = false;

    $locale = meteonexa_backend_language($language);
    $cacheKey = $locale . '|' . $key;

    if (!array_key_exists($cacheKey, $cache)) {
        $value = $key;
        if (!$databaseUnavailable) {
            try {
                if (!$pdo instanceof PDO) {
                    $settings = meteonexa_database_settings();
                    if (($settings['driver'] ?? 'sqlite') === 'mysql') {
                        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
                        $host = trim((string)($settings['host'] ?? ''));
                        $database = trim((string)($settings['database'] ?? ''));
                        $username = trim((string)($settings['username'] ?? ''));
                        $port = max(1, min(65535, (int)($settings['port'] ?? 3306)));
                        if ($host === '' || $database === '' || $username === '') throw new RuntimeException('I18N_DATABASE_MISSING');
                        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, (string)($settings['password'] ?? ''), [
                            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES=>true,
                            PDO::ATTR_TIMEOUT=>3,
                        ]);
                    } else {
                        $path = meteonexa_translation_sqlite_path();
                        if (!is_file($path)) throw new RuntimeException('I18N_DATABASE_MISSING');
                        $pdo = new PDO('sqlite:' . $path, null, null, [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_TIMEOUT => 2,
                        ]);
                        $pdo->exec('PRAGMA query_only = ON');
                        $pdo->exec('PRAGMA busy_timeout = 1500');
                    }
                }

                $statement = $pdo->prepare(
                    "SELECT locale, translation FROM translations
                     WHERE text_key = :key AND locale IN (:locale, 'en', 'it')"
                );
                $statement->execute([':key' => $key, ':locale' => $locale]);
                $rows = $statement->fetchAll();
                $resolved = [];
                foreach ($rows as $row) {
                    $rowLocale = (string)($row['locale'] ?? '');
                    if ($rowLocale !== '') $resolved[$rowLocale] = (string)($row['translation'] ?? '');
                }
                foreach (array_values(array_unique([$locale, 'en', 'it'])) as $candidate) {
                    if (array_key_exists($candidate, $resolved) && $resolved[$candidate] !== '') {
                        $value = $resolved[$candidate];
                        break;
                    }
                }
            } catch (Throwable $error) {
                // If the translation DB itself is unavailable there is no DB-backed
                // message we can safely resolve. Keep the stable key as last-resort
                // diagnostic fallback and avoid repeated failing connection attempts.
                $databaseUnavailable = true;
                $pdo = null;
            }
        }
        $cache[$cacheKey] = $value;
    }

    $value = (string)$cache[$cacheKey];
    foreach ($params as $name => $replacement) {
        $value = str_replace(
            ['{' . $name . '}', '${' . $name . '}'],
            (string)$replacement,
            $value
        );
    }
    return $value;
}
