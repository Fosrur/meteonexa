<?php
declare(strict_types=1);

/**
 * Resolve MeteoNexa runtime storage outside the web server document root.
 *
 * Priority:
 *  1. METEONEXA_STORAGE_PATH (recommended for production and path-stable deploys)
 *  2. the hosting account home directory (HOME / USERPROFILE)
 *  3. the parent of DOCUMENT_ROOT
 *  4. the parent of the application directory (CLI/dev fallback)
 *
 * The automatically selected directory is deployment-specific to avoid clashes
 * between multiple copies of the application owned by the same account.
 */
function meteonexa_application_root(): string
{
    $root = realpath(dirname(__DIR__));
    return is_string($root) && $root !== '' ? $root : dirname(__DIR__);
}

function meteonexa_normalize_filesystem_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') return '';
    $path = rtrim($path, '/');
    if (DIRECTORY_SEPARATOR === '\\') $path = strtolower($path);
    return $path;
}

function meteonexa_document_root_path(): string
{
    $raw = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($raw === '') return '';
    $real = realpath($raw);
    return meteonexa_normalize_filesystem_path(is_string($real) && $real !== '' ? $real : $raw);
}

function meteonexa_path_is_within(string $path, string $parent): bool
{
    $path = meteonexa_normalize_filesystem_path($path);
    $parent = meteonexa_normalize_filesystem_path($parent);
    if ($path === '' || $parent === '') return false;
    return $path === $parent || str_starts_with($path . '/', $parent . '/');
}

function meteonexa_external_storage_candidate(): string
{
    $applicationRoot = meteonexa_application_root();
    $suffix = substr(hash('sha256', meteonexa_normalize_filesystem_path($applicationRoot)), 0, 12);
    $directoryName = '.meteonexa-storage-' . $suffix;
    $documentRoot = meteonexa_document_root_path();

    $safeCandidates = [];
    foreach (['HOME', 'USERPROFILE'] as $variable) {
        $home = trim((string)(getenv($variable) ?: ''));
        if ($home === '') continue;
        $realHome = realpath($home);
        $home = is_string($realHome) && $realHome !== '' ? $realHome : $home;
        $candidate = rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $directoryName;
        if ($documentRoot === '' || !meteonexa_path_is_within($candidate, $documentRoot)) $safeCandidates[] = $candidate;
    }

    if ($documentRoot !== '') {
        $candidate = dirname($documentRoot) . DIRECTORY_SEPARATOR . $directoryName;
        if (!meteonexa_path_is_within($candidate, $documentRoot)) $safeCandidates[] = $candidate;
    }

    $candidate = dirname($applicationRoot) . DIRECTORY_SEPARATOR . $directoryName;
    if ($documentRoot === '' || !meteonexa_path_is_within($candidate, $documentRoot)) $safeCandidates[] = $candidate;

    foreach (array_values(array_unique($safeCandidates)) as $candidate) {
        $parent = dirname($candidate);
        if (is_dir($parent) && is_writable($parent)) return $candidate;
    }

    // Shared hosting (notably configurations where DOCUMENT_ROOT is the only
    // writable account directory) may legitimately have no writable parent
    // outside the web root. Do not return a path that we already know cannot be
    // created: the resolver below will use the protected compatibility storage.
    throw new RuntimeException('EXTERNAL_STORAGE_PATH_UNAVAILABLE');
}

function meteonexa_storage_path(): string
{
    static $resolved = null;
    if (is_string($resolved) && $resolved !== '') return $resolved;

    $configured = trim((string)(getenv('METEONEXA_STORAGE_PATH') ?: ''));
    $documentRoot = meteonexa_document_root_path();

    if ($configured !== '') {
        $candidate = rtrim($configured, DIRECTORY_SEPARATOR);
        if ($candidate === '') throw new RuntimeException('STORAGE_PATH_INVALID');
        if ($documentRoot !== '' && meteonexa_path_is_within($candidate, $documentRoot)) {
            // An explicitly configured production path is always strict: never
            // silently weaken a deployment that asked for external storage.
            throw new RuntimeException('STORAGE_INSIDE_DOCUMENT_ROOT');
        }
        return $resolved = $candidate;
    }

    try {
        return $resolved = meteonexa_external_storage_candidate();
    } catch (Throwable $error) {
        // Compatibility fallback for shared hosting accounts that expose no
        // writable persistent directory outside DOCUMENT_ROOT. The directory is
        // protected at three levels: root .htaccess, api/.htaccess and its own
        // Require-all-denied .htaccess. This keeps existing installations usable
        // instead of turning public APIs (especially i18n) into HTTP 503 errors.
        $legacy = meteonexa_legacy_storage_path();
        if (!is_dir($legacy) && !@mkdir($legacy, 0770, true) && !is_dir($legacy)) {
            throw new RuntimeException('STORAGE_UNAVAILABLE');
        }
        meteonexa_ensure_protected_web_storage($legacy);
        return $resolved = $legacy;
    }
}


function meteonexa_database_config_path(): string
{
    return meteonexa_storage_path() . DIRECTORY_SEPARATOR . 'database.json';
}

function meteonexa_database_settings(): array
{
    $driver = strtolower(trim((string)(getenv('METEONEXA_DB_DRIVER') ?: '')));
    if ($driver === 'mysql') {
        return [
            'driver'=>'mysql',
            'host'=>trim((string)(getenv('METEONEXA_DB_HOST') ?: 'localhost')),
            'port'=>max(1, min(65535, (int)(getenv('METEONEXA_DB_PORT') ?: 3306))),
            'database'=>trim((string)(getenv('METEONEXA_DB_NAME') ?: '')),
            'username'=>trim((string)(getenv('METEONEXA_DB_USER') ?: '')),
            'password'=>(string)(getenv('METEONEXA_DB_PASSWORD') ?: ''),
            'charset'=>'utf8mb4',
            'source'=>'environment',
        ];
    }
    try {
        $path = meteonexa_database_config_path();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($data) && strtolower((string)($data['driver'] ?? '')) === 'mysql') {
                return [
                    'driver'=>'mysql',
                    'host'=>trim((string)($data['host'] ?? 'localhost')),
                    'port'=>max(1, min(65535, (int)($data['port'] ?? 3306))),
                    'database'=>trim((string)($data['database'] ?? '')),
                    'username'=>trim((string)($data['username'] ?? '')),
                    'password'=>(string)($data['password'] ?? ''),
                    'charset'=>'utf8mb4',
                    'source'=>'storage',
                ];
            }
        }
    } catch (Throwable $ignored) { }
    return ['driver'=>'sqlite','source'=>'runtime'];
}

function meteonexa_database_driver(): string
{
    return (string)(meteonexa_database_settings()['driver'] ?? 'sqlite');
}

function meteonexa_write_database_settings(array $settings): void
{
    $host = trim((string)($settings['host'] ?? ''));
    $database = trim((string)($settings['database'] ?? ''));
    $username = trim((string)($settings['username'] ?? ''));
    if ($host === '' || $database === '' || $username === '') throw new RuntimeException('MYSQL_CONFIG_INVALID');
    $payload = json_encode([
        'driver'=>'mysql',
        'host'=>$host,
        'port'=>max(1, min(65535, (int)($settings['port'] ?? 3306))),
        'database'=>$database,
        'username'=>$username,
        'password'=>(string)($settings['password'] ?? ''),
        'charset'=>'utf8mb4',
        'updated_at'=>gmdate('c'),
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) throw new RuntimeException('MYSQL_CONFIG_ENCODE_FAILED');
    $path = meteonexa_database_config_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('STORAGE_UNAVAILABLE');
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $payload . "\n", LOCK_EX) === false || !@rename($tmp,$path)) {
        @unlink($tmp); throw new RuntimeException('MYSQL_CONFIG_WRITE_FAILED');
    }
    @chmod($path,0600);
}

function meteonexa_baseline_sqlite_path(): string
{
    return __DIR__ . '/install/meteonexa-baseline.sqlite';
}

function meteonexa_legacy_storage_path(): string
{
    return __DIR__ . '/storage';
}


/**
 * Harden the shared-hosting compatibility storage before any runtime file is
 * created there. This is a fallback only; external storage remains preferred.
 */
function meteonexa_ensure_protected_web_storage(string $directory): void
{
    if (!is_dir($directory)) return;
    $htaccess = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    $rules = "Options -Indexes\nRequire all denied\n";
    if (!is_file($htaccess) || trim((string)@file_get_contents($htaccess)) !== trim($rules)) {
        @file_put_contents($htaccess, $rules, LOCK_EX);
    }
    @chmod($htaccess, 0600);
    $index = $directory . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) @file_put_contents($index, "<!doctype html><meta charset=\"utf-8\"><title>404</title>\n", LOCK_EX);
    @chmod($index, 0640);
}

function meteonexa_directory_has_runtime_state(string $directory): bool
{
    if (!is_dir($directory)) return false;
    foreach (['meteonexa.sqlite', '.app-secret', 'vapid.json', 'database.json'] as $name) {
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_file($path) && (int)@filesize($path) > 0) return true;
    }
    foreach (['radar-archive', 'cache', 'locks'] as $name) {
        if (is_dir($directory . DIRECTORY_SEPARATOR . $name)) return true;
    }
    return false;
}

function meteonexa_remove_directory_tree(string $directory): void
{
    if (!is_dir($directory) || is_link($directory)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isLink() || $item->isFile()) @unlink($path);
        elseif ($item->isDir()) @rmdir($path);
    }
    @rmdir($directory);
}

function meteonexa_copy_directory_tree(string $source, string $destination): void
{
    if (!is_dir($source) || is_link($source)) throw new RuntimeException('LEGACY_STORAGE_INVALID');
    if (!is_dir($destination) && !@mkdir($destination, 0770, true) && !is_dir($destination)) {
        throw new RuntimeException('STORAGE_UNAVAILABLE');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) throw new RuntimeException('LEGACY_STORAGE_SYMLINK_DENIED');
        $relative = substr($item->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1);
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0770, true) && !is_dir($target)) {
                throw new RuntimeException('LEGACY_STORAGE_COPY_FAILED');
            }
            continue;
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
            throw new RuntimeException('LEGACY_STORAGE_COPY_FAILED');
        }
        if (!@copy($item->getPathname(), $target)) throw new RuntimeException('LEGACY_STORAGE_COPY_FAILED');
    }
}

/**
 * One-shot upgrade path from releases that stored runtime files in api/storage.
 * It runs only when the external destination is still uninitialized. If both
 * locations contain runtime state, fail closed instead of guessing which copy
 * is authoritative.
 */
function meteonexa_migrate_legacy_storage(string $destination): void
{
    $legacy = meteonexa_legacy_storage_path();
    // On shared-hosting fallback the legacy directory is intentionally also the
    // active destination. There is nothing to migrate and, importantly, this is
    // not a conflict.
    if (meteonexa_normalize_filesystem_path($legacy) === meteonexa_normalize_filesystem_path($destination)) {
        meteonexa_ensure_protected_web_storage($legacy);
        return;
    }
    if (!is_dir($legacy) || !meteonexa_directory_has_runtime_state($legacy)) return;

    $parent = dirname($destination);
    if (!is_dir($parent) || !is_writable($parent)) throw new RuntimeException('STORAGE_UNAVAILABLE');

    $lockPath = $parent . DIRECTORY_SEPARATOR . '.meteonexa-storage-migration-' . substr(hash('sha256', $destination), 0, 12) . '.lock';
    $lock = @fopen($lockPath, 'c');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('STORAGE_MIGRATION_LOCK_FAILED');
    }

    try {
        // Another worker may have completed the move while this request waited.
        if (!is_dir($legacy) || !meteonexa_directory_has_runtime_state($legacy)) return;
        if (meteonexa_directory_has_runtime_state($destination)) {
            throw new RuntimeException('LEGACY_STORAGE_CONFLICT');
        }

        // Atomic rename is preferred: it avoids leaving a sensitive duplicate in
        // the document root and preserves the exact SQLite/secret pairing.
        if (!file_exists($destination) && @rename($legacy, $destination)) {
            @chmod($destination, 0770);
        } else {
            $createdDestination = !is_dir($destination);
            try {
                meteonexa_copy_directory_tree($legacy, $destination);
                if (!meteonexa_directory_has_runtime_state($destination)) {
                    throw new RuntimeException('LEGACY_STORAGE_COPY_FAILED');
                }
                meteonexa_remove_directory_tree($legacy);
            } catch (Throwable $error) {
                if ($createdDestination) meteonexa_remove_directory_tree($destination);
                throw $error;
            }
        }

        foreach (['.app-secret', '.app-secret.lock', 'vapid.json', 'vapid.json.lock', 'database.json'] as $name) {
            $path = $destination . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) @chmod($path, 0600);
        }
        $database = $destination . DIRECTORY_SEPARATOR . 'meteonexa.sqlite';
        if (is_file($database)) @chmod($database, 0660);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
        @chmod($lockPath, 0600);
    }
}

/**
 * Create the per-installation runtime SQLite database from the sanitized
 * read-only baseline. This lives in the storage layer so even endpoints that
 * only need translations cannot accidentally make SQLite create an empty DB.
 */
function meteonexa_prepare_sqlite_database(string $databasePath): void
{
    if (is_file($databasePath) && (int)@filesize($databasePath) > 0) return;

    $seed = meteonexa_baseline_sqlite_path();
    if (!is_file($seed) || (int)@filesize($seed) <= 0) {
        throw new RuntimeException('SQLITE_BASELINE_MISSING');
    }

    $storage = dirname($databasePath);
    if (!is_dir($storage) && !@mkdir($storage, 0770, true) && !is_dir($storage)) {
        throw new RuntimeException('SQLITE_DIRECTORY_UNAVAILABLE');
    }

    $lockPath = $storage . '/.database-init.lock';
    $lock = @fopen($lockPath, 'c');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('SQLITE_INIT_LOCK_FAILED');
    }
    try {
        if (is_file($databasePath) && (int)@filesize($databasePath) > 0) return;

        // The distributable baseline must never contain deployment-bound
        // material. Keep this check here because every DB entry-point passes
        // through this initializer, including i18n-only requests.
        $seedPdo = new PDO('sqlite:' . $seed, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $seedPdo->exec('PRAGMA query_only = ON');
        $verifier = (string)($seedPdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='secret_verifier'), '')")->fetchColumn() ?: '');
        $smtpProtected = (int)$seedPdo->query("SELECT COUNT(*) FROM smtp_settings WHERE COALESCE(password_encrypted,'') <> ''")->fetchColumn();
        $netatmoProtected = (int)$seedPdo->query("SELECT COUNT(*) FROM netatmo_accounts WHERE COALESCE(access_token_enc,'') <> '' OR COALESCE(refresh_token_enc,'') <> ''")->fetchColumn();
        $authProtected = (int)$seedPdo->query('SELECT COUNT(*) FROM auth_sessions')->fetchColumn();
        $trustedProtected = (int)$seedPdo->query('SELECT COUNT(*) FROM trusted_devices')->fetchColumn();
        if ($verifier !== '' || $smtpProtected > 0 || $netatmoProtected > 0 || $authProtected > 0 || $trustedProtected > 0) {
            throw new RuntimeException('BASELINE_DATABASE_NOT_SANITIZED');
        }
        $seedPdo = null;

        $temp = $storage . '/.meteonexa.sqlite.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@copy($seed, $temp)) {
            @unlink($temp);
            throw new RuntimeException('SQLITE_BASELINE_COPY_FAILED');
        }
        @chmod($temp, 0660);
        if (!@rename($temp, $databasePath)) {
            @unlink($temp);
            throw new RuntimeException('SQLITE_BASELINE_COPY_FAILED');
        }
        @chmod($databasePath, 0660);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
        @chmod($lockPath, 0600);
    }
}

/**
 * Resolve a readable translations database without making the public catalog
 * depend on writable runtime storage. Runtime/legacy data wins so DB edits keep
 * working; the sanitized packaged baseline is the final read-only fallback.
 */
function meteonexa_translation_sqlite_path(): string
{
    try {
        return meteonexa_runtime_sqlite_path();
    } catch (Throwable $runtimeError) {
        $legacyDatabase = meteonexa_legacy_storage_path() . DIRECTORY_SEPARATOR . 'meteonexa.sqlite';
        if (is_file($legacyDatabase) && (int)@filesize($legacyDatabase) > 0) return $legacyDatabase;

        $baseline = meteonexa_baseline_sqlite_path();
        if (is_file($baseline) && (int)@filesize($baseline) > 0) return $baseline;
        throw $runtimeError;
    }
}

/**
 * Return a ready-to-open runtime DB path. Migration and baseline creation are
 * deliberately centralized here so mutable API consumers behave consistently.
 */
function meteonexa_runtime_sqlite_path(): string
{
    $storage = meteonexa_storage_path();
    meteonexa_migrate_legacy_storage($storage);
    if (!is_dir($storage) && !@mkdir($storage, 0770, true) && !is_dir($storage)) {
        throw new RuntimeException('SQLITE_DIRECTORY_UNAVAILABLE');
    }
    $databasePath = $storage . DIRECTORY_SEPARATOR . 'meteonexa.sqlite';
    meteonexa_prepare_sqlite_database($databasePath);
    return $databasePath;
}

