<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once dirname(__DIR__) . '/intelligence/engine_helpers.php';
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/official/lifecycle_helpers.php';

require_method('GET');
assert_same_origin();
$config = load_config();
$pdo = meteonexa_db($config);

$globalLimit = max(1000, (int)($config['abuse_limits']['official_alerts_global_hour'] ?? 10000));
require_ip_rate_limit($pdo, 'official_alerts_ip', 360, 3600);
require_global_rate_limit($pdo, 'official_alerts_global', $globalLimit, 3600);

$lat = query_float('lat', 999);
$lon = query_float('lon', 999);
if (abs($lat) > 90 || abs($lon) > 180) {
    respond(['ok' => false, 'code' => 'INVALID_COORDINATES', 'message' => meteonexa_backend_text('api.backend.invalid_coordinates')], 422);
}

$location = clean_text($_GET['location'] ?? '', 120, '');
$admin1 = clean_text($_GET['admin1'] ?? '', 120, '');
$locale = strtolower(clean_text($_GET['lang'] ?? 'en', 8, 'en'));
if (!in_array($locale, ['it', 'en', 'fr', 'es', 'de'], true)) {
    $locale = 'en';
}

$alerts = meteonexa_intelq_meteoalarm_edr($config, $lat, $lon, $locale)
    ?? meteonexa_official_alerts($lat, $lon, $location, $admin1);
if (!isset($alerts['mode'])) {
    $alerts['mode'] = 'atom-text-fallback';
    $alerts['geospatial'] = false;
}
foreach ((array)($alerts['relevant'] ?? []) as $i => $row) {
    if (is_array($row)) {
        $alerts['relevant'][$i] = meteonexa_official_enrich_window($row);
    }
}
$alerts = meteonexa_official_normalize($alerts);

// deviceId is optional only for this public weather surface. clean_device_id()
// deliberately rejects an empty value because private endpoints require_once a real
// device proof. Therefore it must be called only when the client actually sent
// a non-empty deviceId; otherwise the guest branch below would be unreachable
// and every guest request would incorrectly return INVALID_DEVICE (422).
$rawDeviceId = trim((string)($_GET['deviceId'] ?? ''));
if ($rawDeviceId !== '') {
    $deviceId = clean_device_id($rawDeviceId);
    require_authenticated_device_session($pdo, $config, $deviceId);

    // Device throttling is an additive guard: IP + global throttles have already
    // been enforced above. A storage/SQL problem in this optional per-device
    // counter must not turn a public weather read into HTTP 500 for a valid
    // authenticated session. Explicit 429 responses still terminate in
    // require_device_rate_limit(); only unexpected backend exceptions degrade.
    try {
        require_device_rate_limit($pdo, 'official_alerts_device', $deviceId, 180, 3600);
    } catch (Throwable $rateLimitError) {
        meteonexa_log_event('official_alert_device_rate_degraded', $rateLimitError);
    }

    // Lifecycle persistence enriches an alert but is not the alert source. If a
    // write/revision fails (for example a transient MySQL schema/row issue), keep
    // serving the provider result and, when possible, enrich it from the public
    // read-only snapshot. This prevents a valid email session from seeing the
    // generic INTERNAL_ERROR card while guests still receive the same weather.
    try {
        $alerts = meteonexa_official_track($pdo, $lat, $lon, $location, $alerts);
    } catch (Throwable $trackingError) {
        meteonexa_log_event('official_alert_tracking_degraded', $trackingError);
        try {
            $alerts = meteonexa_official_public_snapshot($pdo, $lat, $lon, $alerts, $location, $admin1);
        } catch (Throwable $snapshotError) {
            meteonexa_log_event('official_alert_snapshot_degraded', $snapshotError);
        }
        $alerts['lifecycleTracked'] = false;
        $alerts['lifecycleDegraded'] = true;
    }
} else {
    try {
        $alerts = meteonexa_official_public_snapshot($pdo, $lat, $lon, $alerts, $location, $admin1);
    } catch (Throwable $snapshotError) {
        // The provider response remains useful even if lifecycle storage is
        // temporarily unavailable. Guest reads must fail soft for the same
        // reason as authenticated reads.
        meteonexa_log_event('official_alert_guest_snapshot_degraded', $snapshotError);
        $alerts['lifecycleTracked'] = false;
        $alerts['lifecycleDegraded'] = true;
    }
}

respond(['ok' => true, 'alerts' => $alerts, 'generatedAt' => gmdate('c')]);
