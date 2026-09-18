<?php
declare(strict_types=1);

require_once __DIR__ . '/backend_i18n.php';

/**
 * HTTP error renderer for browser navigations.
 *
 * Translation values are never embedded in PHP/HTML. Runtime DB translations
 * are preferred; a release-packaged JSON catalog (generated from the same DB
 * seed) is used only when the runtime database itself is unavailable.
 */
function meteonexa_error_language(): string
{
    $cookie = strtolower(trim((string)($_COOKIE['meteonexa_language'] ?? '')));
    if (in_array($cookie, ['it','en','fr','es','de'], true)) return $cookie;
    return meteonexa_backend_language();
}

function meteonexa_error_fallback_catalog(string $language): array
{
    static $cache = [];
    $locale = in_array($language, ['it','en','fr','es','de'], true) ? $language : 'it';
    if (isset($cache[$locale])) return $cache[$locale];
    $path = __DIR__ . '/install/error-translations.json';
    if (!is_file($path)) return $cache[$locale] = [];
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !is_array($decoded['translations'] ?? null)) return $cache[$locale] = [];
    $rows = $decoded['translations'][$locale] ?? [];
    return $cache[$locale] = (is_array($rows) ? $rows : []);
}

function meteonexa_error_text(string $key, array $params = [], ?string $language = null): string
{
    $locale = $language ?: meteonexa_error_language();
    $value = meteonexa_backend_text($key, $params, $locale);
    if ($value !== $key) return $value;

    $catalog = meteonexa_error_fallback_catalog($locale);
    $value = (string)($catalog[$key] ?? '');
    if ($value === '') {
        $fallback = meteonexa_error_fallback_catalog('it');
        $value = (string)($fallback[$key] ?? $key);
    }
    foreach ($params as $name => $replacement) {
        $value = str_replace(['{' . $name . '}', '${' . $name . '}'], (string)$replacement, $value);
    }
    return $value;
}

function meteonexa_error_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Browser-page security headers.
 *
 * api/bootstrap.php deliberately starts from `default-src 'none'` because
 * JSON endpoints do not need browser assets. Browser surfaces that include
 * bootstrap (QA / Diagnostics) MUST replace that API-only CSP before emitting
 * HTML, otherwise their self-hosted CSS, JS and i18n runtime are blocked and
 * the page appears completely blank.
 */
function meteonexa_browser_page_security_headers(): void
{
    if (headers_sent()) return;
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Origin-Agent-Cluster: ?1');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; script-src-attr 'none'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self'");
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') header('Strict-Transport-Security: max-age=31536000');
}

function meteonexa_render_error_page(int $status, array $options = []): never
{
    $allowed = [400,401,403,404,405,408,413,415,422,429,500,502,503,504];
    if (!in_array($status, $allowed, true)) $status = 500;

    $language = meteonexa_error_language();
    $path = (string)($options['path'] ?? ($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/'));
    $parsedPath = parse_url($path, PHP_URL_PATH);
    $safePath = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
    $requestId = substr(bin2hex(random_bytes(8)), 0, 12);
    $retryable = in_array($status, [408,429,500,502,503,504], true);
    $login = $status === 401;

    http_response_code($status);
    meteonexa_browser_page_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    if ($status === 429 && !headers_sent()) header('Retry-After: 60');

    $titleKey = 'error.' . $status . '.title';
    $messageKey = 'error.' . $status . '.message';
    $hintKey = 'error.' . $status . '.hint';
    $homeHref = '/';
    $retryHref = $safePath;
    $brand = meteonexa_error_text('error.page.brand', [], $language);
    $pageTitle = meteonexa_error_text($titleKey, [], $language);
    $message = meteonexa_error_text($messageKey, [], $language);
    $hint = meteonexa_error_text($hintKey, [], $language);
    $kicker = meteonexa_error_text('error.page.kicker', [], $language);
    $statusLabel = meteonexa_error_text('error.meta.status', [], $language);
    $pathLabel = meteonexa_error_text('error.meta.path', [], $language);
    $requestLabel = meteonexa_error_text('error.meta.request', [], $language);
    $homeLabel = meteonexa_error_text($login ? 'error.action.login' : 'error.action.home', [], $language);
    $retryLabel = meteonexa_error_text('error.action.retry', [], $language);
    $footer = meteonexa_error_text('error.footer.security', [], $language);
    $backLabel = meteonexa_error_text('error.action.back', [], $language);

    echo '<!doctype html><html lang="' . meteonexa_error_html($language) . '"><head>';
    echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
    echo '<meta name="robots" content="noindex,nofollow,noarchive">';
    echo '<title>' . meteonexa_error_html($brand . ' · ' . $pageTitle) . '</title>';
    echo '<link rel="icon" href="/assets/icons/favicon-32.png?v=20.1">';
    echo '<link rel="stylesheet" href="/dist/error.8dead21d322a.css">';
    echo '</head><body>';
    echo '<main class="error-shell">';
    echo '<section class="error-card" aria-labelledby="error-title">';
    echo '<div class="error-brand"><span class="error-mark" aria-hidden="true"></span><div><span class="error-kicker">' . meteonexa_error_html($kicker) . '</span><strong>' . meteonexa_error_html($brand) . '</strong></div></div>';
    echo '<div class="error-code" aria-hidden="true">' . $status . '</div>';
    echo '<h1 id="error-title">' . meteonexa_error_html($pageTitle) . '</h1>';
    echo '<p class="error-message">' . meteonexa_error_html($message) . '</p>';
    echo '<p class="error-hint">' . meteonexa_error_html($hint) . '</p>';
    echo '<div class="error-meta">';
    echo '<div><small>' . meteonexa_error_html($statusLabel) . '</small><strong>' . $status . '</strong></div>';
    echo '<div><small>' . meteonexa_error_html($pathLabel) . '</small><strong>' . meteonexa_error_html($safePath) . '</strong></div>';
    echo '<div><small>' . meteonexa_error_html($requestLabel) . '</small><strong>' . meteonexa_error_html($requestId) . '</strong></div>';
    echo '</div>';
    echo '<div class="error-actions">';
    echo '<a class="error-button primary" href="' . meteonexa_error_html($homeHref) . '">' . meteonexa_error_html($homeLabel) . '</a>';
    if ($retryable) echo '<a class="error-button" href="' . meteonexa_error_html($retryHref) . '">' . meteonexa_error_html($retryLabel) . '</a>';
    echo '<button class="error-button" id="error-back" type="button" data-home="' . meteonexa_error_html($homeHref) . '">' . meteonexa_error_html($backLabel) . '</button>';
    echo '</div>';
    echo '<footer>' . meteonexa_error_html($footer) . '</footer>';
    echo '</section></main><script src="/dist/error.6e5f66445e36.js" defer></script></body></html>';
    exit;
}
