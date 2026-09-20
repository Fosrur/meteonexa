<?php
declare(strict_types=1);

// Same-origin, allowlisted vendor/static asset gateway.
//
// Executable MapLibre assets are sourced from a cryptographically pinned npm
// package. The browser never chooses the upstream URL and no executable asset
// is accepted unless the complete package matches the hardcoded SHA-512 npm
// integrity value for maplibre-gl@5.24.0.
//
// Non-executable geographic datasets remain fetched from fixed HTTPS URLs,
// validated as GeoJSON and cached in protected runtime storage.

require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/http_helpers.php';

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$maplibrePackage = [
    'url' => 'https://registry.npmjs.org/maplibre-gl/-/maplibre-gl-5.24.0.tgz',
    'file' => 'maplibre-gl-5.24.0.tgz',
    // npm package integrity for maplibre-gl@5.24.0. This pins the complete
    // package, not only a filename/version string.
    'integrity' => 'sha512-ALyFxgtd5R+65UqZ/++lOqwWcC0SNho9c27fYSyLmG7AfnAul2o46F05aDJGPbFU57wos9dgcIySHs0Xe6ia3A==',
    'max_bytes' => 16777216,
];

$assets = [
    'maplibre-js' => [
        'source' => 'pinned_npm',
        'package_path' => 'package/dist/maplibre-gl.js',
        // New cache name prevents a pre-hardening CDN cache entry from being
        // reused after upgrading to this package.
        'file' => 'maplibre-gl-5.24.0-npm-pinned.js',
        'type' => 'application/javascript; charset=utf-8',
        'kind' => 'js',
    ],
    'maplibre-css' => [
        'source' => 'pinned_npm',
        'package_path' => 'package/dist/maplibre-gl.css',
        'file' => 'maplibre-gl-5.24.0-npm-pinned.css',
        'type' => 'text/css; charset=utf-8',
        'kind' => 'css',
    ],
    'italy-regions' => [
        'source' => 'remote_data',
        'url' => 'https://raw.githubusercontent.com/guglielmo/geojson-italy/refs/heads/main/geojson/limits_IT_regions.geojson',
        'file' => 'limits_IT_regions.geojson',
        'type' => 'application/geo+json; charset=utf-8',
        'accept' => 'application/geo+json,application/json;q=0.9,*/*;q=0.1',
        'max_bytes' => 12000000,
        'ttl' => 604800,
        'kind' => 'geojson',
    ],
    'italy-metros' => [
        'source' => 'remote_data',
        'url' => 'https://raw.githubusercontent.com/guglielmo/geojson-italy/refs/heads/main/geojson/limits_IT_metropolitan_cities.geojson',
        'file' => 'limits_IT_metropolitan_cities.geojson',
        'type' => 'application/geo+json; charset=utf-8',
        'accept' => 'application/geo+json,application/json;q=0.9,*/*;q=0.1',
        'max_bytes' => 12000000,
        'ttl' => 604800,
        'kind' => 'geojson',
    ],
];

$key = strtolower(trim((string)($_GET['asset'] ?? '')));
if (!isset($assets[$key])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
$asset = $assets[$key];
header('Content-Type: ' . $asset['type']);
header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');

$validate = static function (string $body, string $kind): bool {
    if ($body === '') return false;
    if ($kind === 'js') return strlen($body) > 100000 && str_contains($body, 'maplibregl');
    if ($kind === 'css') return strlen($body) > 5000 && str_contains($body, '.maplibregl-');
    if ($kind === 'geojson') {
        $decoded = json_decode($body, true);
        return is_array($decoded)
            && (string)($decoded['type'] ?? '') === 'FeatureCollection'
            && isset($decoded['features'])
            && is_array($decoded['features'])
            && count($decoded['features']) > 0;
    }
    return false;
};

$integrityMatches = static function (string $body, string $integrity): bool {
    if (!preg_match('/^sha512-([A-Za-z0-9+\/=]+)$/', trim($integrity), $match)) return false;
    $actual = base64_encode(hash('sha512', $body, true));
    return hash_equals($match[1], $actual);
};

$serve = static function (string $path, string $cacheState): never {
    $etag = '"' . hash_file('sha256', $path) . '"';
    header('ETag: ' . $etag);
    header('X-MeteoNexa-Asset-Cache: ' . $cacheState);
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    $size = @filesize($path);
    if (is_int($size) && $size >= 0) header('Content-Length: ' . $size);
    readfile($path);
    exit;
};

$writeAtomic = static function (string $path, string $body): void {
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('VENDOR_CACHE_WRITE_FAILED');
    }
    @chmod($path, 0660);
};

try {
    $storage = meteonexa_storage_path();
    $cacheDir = rtrim($storage, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vendor-assets';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0770, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('VENDOR_CACHE_UNAVAILABLE');
    }
    @chmod($cacheDir, 0770);
    $cachePath = $cacheDir . DIRECTORY_SEPARATOR . $asset['file'];
    $source = (string)($asset['source'] ?? '');

    // Pinned executable assets are immutable for this application release.
    // A valid cache file therefore has no time-based expiry.
    if ($source === 'pinned_npm' && is_file($cachePath)) {
        $cachedBody = (string)@file_get_contents($cachePath);
        if ($validate($cachedBody, (string)$asset['kind'])) $serve($cachePath, 'pinned');
    }

    if ($source === 'remote_data') {
        $fresh = is_file($cachePath) && (time() - (int)@filemtime($cachePath)) <= (int)$asset['ttl'];
        if ($fresh && $validate((string)@file_get_contents($cachePath), (string)$asset['kind'])) {
            $serve($cachePath, 'fresh');
        }
    }

    $lockPath = $cachePath . '.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX)) throw new RuntimeException('VENDOR_CACHE_LOCK_FAILED');
    try {
        clearstatcache(true, $cachePath);

        if ($source === 'pinned_npm') {
            if (is_file($cachePath)) {
                $cachedBody = (string)@file_get_contents($cachePath);
                if ($validate($cachedBody, (string)$asset['kind'])) {
                    @flock($lock, LOCK_UN);
                    @fclose($lock);
                    $serve($cachePath, 'pinned');
                }
            }

            $packagePath = $cacheDir . DIRECTORY_SEPARATOR . $maplibrePackage['file'];
            $packageLockPath = $packagePath . '.lock';
            $packageLock = @fopen($packageLockPath, 'c+');
            if (!is_resource($packageLock) || !@flock($packageLock, LOCK_EX)) {
                throw new RuntimeException('VENDOR_PACKAGE_LOCK_FAILED');
            }
            try {
                $packageBody = is_file($packagePath) ? (string)@file_get_contents($packagePath) : '';
                if (!$integrityMatches($packageBody, (string)$maplibrePackage['integrity'])) {
                    $response = meteonexa_http_request((string)$maplibrePackage['url'], [
                        'timeout' => 35,
                        'max_bytes' => (int)$maplibrePackage['max_bytes'],
                        'pin_dns' => true,
                        'headers' => ['Accept: application/octet-stream,application/gzip;q=0.9,*/*;q=0.1'],
                    ]);
                    $packageBody = (string)($response['body'] ?? '');
                    $status = (int)($response['status'] ?? 0);
                    if ($status < 200 || $status >= 300 || !$integrityMatches($packageBody, (string)$maplibrePackage['integrity'])) {
                        throw new RuntimeException('VENDOR_PACKAGE_INTEGRITY_FAILED');
                    }
                    $writeAtomic($packagePath, $packageBody);
                }
            } finally {
                @flock($packageLock, LOCK_UN);
                @fclose($packageLock);
            }

            try {
                $archive = new PharData($packagePath);
                $entryPath = (string)$asset['package_path'];
                if (!isset($archive[$entryPath])) throw new RuntimeException('VENDOR_PACKAGE_ENTRY_MISSING');
                $body = $archive[$entryPath]->getContent();
                if (!is_string($body) || !$validate($body, (string)$asset['kind'])) {
                    throw new RuntimeException('VENDOR_PACKAGE_ENTRY_INVALID');
                }
                $writeAtomic($cachePath, $body);
            } catch (Throwable $archiveError) {
                throw new RuntimeException('VENDOR_PACKAGE_READ_FAILED', 0, $archiveError);
            }
        } elseif ($source === 'remote_data') {
            $fresh = is_file($cachePath) && (time() - (int)@filemtime($cachePath)) <= (int)$asset['ttl'];
            if ($fresh) {
                $cachedBody = (string)@file_get_contents($cachePath);
                if ($validate($cachedBody, (string)$asset['kind'])) {
                    @flock($lock, LOCK_UN);
                    @fclose($lock);
                    $serve($cachePath, 'fresh');
                }
            }

            try {
                $response = meteonexa_http_request((string)$asset['url'], [
                    'timeout' => 25,
                    'max_bytes' => (int)$asset['max_bytes'],
                    'pin_dns' => true,
                    'headers' => ['Accept: ' . $asset['accept']],
                ]);
                $body = (string)($response['body'] ?? '');
                $status = (int)($response['status'] ?? 0);
                if ($status < 200 || $status >= 300 || !$validate($body, (string)$asset['kind'])) {
                    throw new RuntimeException('VENDOR_REMOTE_INVALID');
                }
                $writeAtomic($cachePath, $body);
            } catch (Throwable $upstreamError) {
                // Stale-if-error is safe for data assets because cached content
                // is still validated as GeoJSON before serving.
                if (!is_file($cachePath)) throw $upstreamError;
                $cachedBody = (string)@file_get_contents($cachePath);
                if (!$validate($cachedBody, (string)$asset['kind'])) throw $upstreamError;
                error_log('[MeteoNexa] event=vendor_asset_stale key=' . preg_replace('/[^a-z0-9_-]/', '_', $key));
                @flock($lock, LOCK_UN);
                @fclose($lock);
                $serve($cachePath, 'stale');
            }
        } else {
            throw new RuntimeException('VENDOR_SOURCE_INVALID');
        }
    } finally {
        if (is_resource($lock)) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
    $serve($cachePath, $source === 'pinned_npm' ? 'pinned-refreshed' : 'refreshed');
} catch (Throwable $error) {
    error_log('[MeteoNexa] event=vendor_asset_unavailable key=' . preg_replace('/[^a-z0-9_-]/', '_', $key) . ' exception=' . get_class($error));
    http_response_code(503);
    exit;
}
