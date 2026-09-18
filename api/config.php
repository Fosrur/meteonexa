<?php
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
/**
 * MeteoNexa runtime configuration.
 *
 * Runtime SMTP and AI credentials are DB-backed and encrypted with the
 * deployment secret. Environment values are optional one-time provisioning
 * inputs for deployments that cannot use the installer. Bootstrap credentials
 * and provider secrets that are not DB-managed remain deployment environment
 * values. Never commit production credentials into this file.
 */
$parseEmailList = static function (string $value): array {
    $emails = [];
    foreach (preg_split('/[;,\s]+/', trim($value)) ?: [] as $candidate) {
        $candidate = strtolower(trim((string)$candidate));
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) $emails[$candidate] = true;
    }
    return array_keys($emails);
};

return [
    'app' => [
        'name' => 'MeteoNexa',
        'base_url' => getenv('METEONEXA_BASE_URL') ?: '',
        'version' => '20.1',
    ],
    'smtp' => [
        // The encrypted DB row is authoritative at runtime. METEONEXA_SMTP_*
        // values are only a safe one-time provisioning fallback and are never
        // shipped in the application/database baseline.
        'host' => getenv('METEONEXA_SMTP_HOST') ?: '',
        'port' => max(1, min(65535, (int)(getenv('METEONEXA_SMTP_PORT') ?: 587))),
        'encryption' => getenv('METEONEXA_SMTP_ENCRYPTION') ?: 'tls',
        'username' => getenv('METEONEXA_SMTP_USERNAME') ?: '',
        'password' => getenv('METEONEXA_SMTP_PASSWORD') ?: '',
        'from_email' => getenv('METEONEXA_SMTP_FROM_EMAIL') ?: 'alerts@meteonexa.com',
        'from_name' => getenv('METEONEXA_SMTP_FROM_NAME') ?: 'MeteoNexa',
        'timeout_seconds' => max(5, min(60, (int)(getenv('METEONEXA_SMTP_TIMEOUT_SECONDS') ?: 12))),
        // Aruba shared hosting normally exposes the local PHP mail transport.
        // SMTP remains the first choice when the encrypted runtime credential is usable;
        // native mail is a deployment-local fallback and never accepts arbitrary headers.
        'native_mail_fallback' => filter_var(getenv('METEONEXA_NATIVE_MAIL_FALLBACK') === false ? '1' : getenv('METEONEXA_NATIVE_MAIL_FALLBACK'), FILTER_VALIDATE_BOOLEAN),
    ],
    'product_metrics' => [
        'enabled' => filter_var(getenv('METEONEXA_PRODUCT_METRICS_ENABLED') === false ? '1' : getenv('METEONEXA_PRODUCT_METRICS_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'retention_days' => max(30, min(180, (int)(getenv('METEONEXA_PRODUCT_METRICS_RETENTION_DAYS') ?: 180))),
        'max_global_hour' => max(100, min(20000, (int)(getenv('METEONEXA_PRODUCT_METRICS_MAX_GLOBAL_HOUR') ?: 6000))),
    ],
    'analytics' => [
        // Plausible is used for cookieless, aggregate traffic analytics only.
        // Product-level feature usage remains in the first-party Product Metrics pipeline.
        'enabled' => filter_var(getenv('METEONEXA_PLAUSIBLE_ENABLED') === false ? '1' : getenv('METEONEXA_PLAUSIBLE_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'domain' => strtolower(trim((string)(getenv('METEONEXA_PLAUSIBLE_DOMAIN') ?: 'meteonexa.com'))),
        'provider' => 'plausible',
        'endpoint' => 'https://plausible.io/api/event',
    ],
    'feedback' => [
        // Optional monitored support inbox. The browser can never choose or
        // override the recipient; bug-report.php resolves server-side fallbacks.
        'recipient_email' => trim((string)(getenv('METEONEXA_BUG_REPORT_EMAIL') ?: '')),
        'max_per_ip_hour' => max(2, min(30, (int)(getenv('METEONEXA_BUG_REPORT_MAX_IP_HOUR') ?: 6))),
        'max_global_hour' => max(20, min(2000, (int)(getenv('METEONEXA_BUG_REPORT_MAX_GLOBAL_HOUR') ?: 180))),
    ],
    'legal' => [
        // Public privacy contact. Prefer a dedicated mailbox; ui-config.php falls back to
        // the DB-authoritative SMTP From address if this is omitted.
        'controller_name' => trim((string)(getenv('METEONEXA_LEGAL_CONTROLLER_NAME') ?: '')),
        'controller_address' => trim((string)(getenv('METEONEXA_LEGAL_CONTROLLER_ADDRESS') ?: '')),
        'privacy_contact_email' => trim((string)(getenv('METEONEXA_PRIVACY_CONTACT_EMAIL') ?: '')),
        'dpo_email' => trim((string)(getenv('METEONEXA_DPO_EMAIL') ?: '')),
        'site_url' => trim((string)(getenv('METEONEXA_LEGAL_SITE_URL') ?: 'https://meteonexa.com/')),
    ],
    'qa' => [
        // Administrative access is deployment-owned. Never commit privileged
        // identities in the package and never infer them from SMTP settings.
        // database.php may persist only deployment-bound HMAC identifiers.
        'admin_emails' => $parseEmailList((string)(getenv('METEONEXA_QA_ADMIN_EMAILS') ?: '')),
    ],
    'auth' => [
        'otp_ttl_seconds' => 600,
        'resend_after_seconds' => 60,
        'max_attempts' => 5,
        'max_requests_per_hour' => 8,
        'max_requests_per_ip_hour' => 30,
        'max_requests_global_hour' => 300,
        'max_verify_requests_per_ip_hour' => 60,
        'max_verify_requests_global_hour' => 2000,
        'session_ttl_seconds' => 2592000,
        // Absolute lifetime above plus an inactivity timeout. A stolen old cookie
        // cannot remain useful indefinitely on an abandoned browser.
        'session_idle_seconds' => max(900, min(2592000, (int)(getenv('METEONEXA_SESSION_IDLE_SECONDS') ?: 604800))),
        // A remembered browser/device may silently re-authenticate the same email
        // after a normal logout. Full cache/device reset revokes this credential.
        'trusted_device_ttl_seconds' => 2592000,
        // Resolved by bootstrap.php from METEONEXA_APP_SECRET or the external runtime .app-secret.
        'app_secret' => '',
    ],
    'device' => [
        'max_credentials' => 10000,
        'max_enrollments_per_ip_hour' => 20,
        'max_enrollments_global_hour' => 500,
    ],
    'request' => [
        'max_json_bytes' => 262144,
    ],
    'radar' => [
        'metadata_url' => getenv('METEONEXA_RADAR_METADATA_URL') ?: 'https://api.librewxr.net/public/weather-maps.json',
        'fallback_metadata_url' => trim((string)(getenv('METEONEXA_RADAR_FALLBACK_METADATA_URL') ?: '')),
        'allowed_hosts' => array_values(array_filter(array_unique(array_merge(['api.librewxr.net'], preg_split('/[,;\s]+/', strtolower((string)(getenv('METEONEXA_RADAR_ALLOWED_HOSTS') ?: ''))) ?: [])))),
    ],
    'radar_archive' => [
        'cron_secret' => getenv('METEONEXA_RADAR_CRON_SECRET') ?: '',
        'interval_minutes' => max(5, min(60, (int)(getenv('METEONEXA_RADAR_ARCHIVE_INTERVAL_MINUTES') ?: 5))),
        'retention_days' => 366,
        'zoom' => 7,
        'max_locations_per_device' => 8,
        'max_total_locations' => 250,
        // Hard disk guard for shared hosting. Override with METEONEXA_RADAR_MAX_STORAGE_MB.
        'max_storage_mb' => max(64, min(5120, (int)(getenv('METEONEXA_RADAR_MAX_STORAGE_MB') ?: 512))),
    ],
    'lightning' => [
        'provider' => 'xweather',
        'client_id' => getenv('METEONEXA_XWEATHER_CLIENT_ID') ?: '',
        'client_secret' => getenv('METEONEXA_XWEATHER_CLIENT_SECRET') ?: '',
        'radius_km' => 100,
    ],
    'ai' => [
        // Optional online LLM. The standard OpenRouter profile is pinned to
        // NVIDIA Nemotron 3 Super's explicit :free endpoint; Groq remains an
        // alternative. Provider URLs are fixed
        // server-side and can never be supplied by the browser.
        'provider' => strtolower(trim((string)(getenv('METEONEXA_AI_PROVIDER') ?: 'openrouter'))),
        // Backward-compatible alias retained for existing deployments.
        'api_key' => getenv('METEONEXA_OPENROUTER_API_KEY') ?: '',
        'openrouter_api_key' => getenv('METEONEXA_OPENROUTER_API_KEY') ?: '',
        'openrouter_model' => getenv('METEONEXA_OPENROUTER_MODEL') ?: 'nvidia/nemotron-3-super-120b-a12b:free',
        // Cost guard: when enabled, runtime and DB-backed OpenRouter settings
        // are forced to the explicit zero-cost NVIDIA endpoint above. Set to 0
        // only when the operator intentionally wants to allow another model.
        'openrouter_free_only' => filter_var(getenv('METEONEXA_OPENROUTER_FREE_ONLY') === false ? '1' : getenv('METEONEXA_OPENROUTER_FREE_ONLY'), FILTER_VALIDATE_BOOLEAN),
        'groq_api_key' => getenv('METEONEXA_GROQ_API_KEY') ?: '',
        'groq_model' => getenv('METEONEXA_GROQ_MODEL') ?: 'openai/gpt-oss-20b',
        'site_url' => getenv('METEONEXA_AI_SITE_URL') ?: '',
        'site_name' => getenv('METEONEXA_AI_SITE_NAME') ?: 'MeteoNexa',
        'timeout_seconds' => 45,
        'max_requests_per_hour' => 40,
        // Deployment-wide guard against distributed API-credit exhaustion.
        'max_requests_global_hour' => 600,
        // Keep a small safety margin below OpenRouter's published free-plan
        // daily request allowance when using the zero-cost router.
        'max_requests_global_day_free' => 45,
    ],
    'calibration' => [
        'cron_secret' => getenv('METEONEXA_CALIBRATION_CRON_SECRET') ?: '',
        'max_locations_per_cycle' => max(5, min(250, (int)(getenv('METEONEXA_CALIBRATION_MAX_LOCATIONS') ?: 60))),
        'retention_days' => max(30, min(730, (int)(getenv('METEONEXA_CALIBRATION_RETENTION_DAYS') ?: 180))),
    ],
    'observations' => [
        'metar_enabled' => filter_var(getenv('METEONEXA_METAR_ENABLED') === false ? '1' : getenv('METEONEXA_METAR_ENABLED'), FILTER_VALIDATE_BOOLEAN),
        'metar_max_distance_km' => max(20, min(300, (int)(getenv('METEONEXA_METAR_MAX_DISTANCE_KM') ?: 140))),
        'synop_url' => trim((string)(getenv('METEONEXA_SYNOP_OBSERVATION_URL') ?: '')),
        'synop_max_distance_km' => max(20, min(300, (int)(getenv('METEONEXA_SYNOP_MAX_DISTANCE_KM') ?: 120))),
        'arpa_url' => trim((string)(getenv('METEONEXA_ARPA_OBSERVATION_URL') ?: '')),
        'arpa_max_distance_km' => max(10, min(200, (int)(getenv('METEONEXA_ARPA_MAX_DISTANCE_KM') ?: 80))),
    ],
    'official' => [
        // Direct MeteoAlarm EDR access is optional and requires an authorised token.
        // Without it MeteoNexa keeps the public Atom fallback and marks it as non-geospatial.
        'meteoalarm_edr_token' => getenv('METEONEXA_METEOALARM_EDR_TOKEN') ?: '',
        'meteoalarm_country' => strtoupper(getenv('METEONEXA_METEOALARM_COUNTRY') ?: 'IT'),
    ],
    'pipeline' => [
        'cron_secret' => getenv('METEONEXA_PIPELINE_CRON_SECRET') ?: '',
        'max_locations_per_cycle' => max(5, min(100, (int)(getenv('METEONEXA_PIPELINE_MAX_LOCATIONS') ?: 30))),
        'official_interval_seconds' => max(120, min(1800, (int)(getenv('METEONEXA_PIPELINE_OFFICIAL_SECONDS') ?: 300))),
        'lightning_interval_seconds' => max(60, min(900, (int)(getenv('METEONEXA_PIPELINE_LIGHTNING_SECONDS') ?: 180))),
        'observation_interval_seconds' => max(180, min(1800, (int)(getenv('METEONEXA_PIPELINE_OBSERVATION_SECONDS') ?: 600))),
        'calibration_interval_seconds' => max(300, min(3600, (int)(getenv('METEONEXA_PIPELINE_CALIBRATION_SECONDS') ?: 900))),
    ],
    'push' => [
        'subject' => getenv('METEONEXA_VAPID_SUBJECT') ?: '',
        'cron_secret' => getenv('METEONEXA_PUSH_CRON_SECRET') ?: '',
        'check_interval_minutes' => 3,
        'max_subscriptions_per_device' => 8,
        // Browser Web Push services accepted by the server. Exact hosts and
        // leading-dot suffixes are supported. Override here if a supported
        // browser uses a different standards-compliant push service.
        'allowed_endpoint_hosts' => [
            'fcm.googleapis.com',
            '.push.services.mozilla.com',
            'web.push.apple.com',
        ],
    ],
    'abuse_limits' => [
        // Deployment-wide guards for public/provider-backed operations.
        'route_global_hour' => 600,
        'synoptic_global_hour' => 600,
        'lightning_global_hour' => 1200,
        'radar_register_global_hour' => 600,
        'radar_tile_upstream_global_hour' => 5000,
        'netatmo_stations_global_hour' => 2000,
        'netatmo_callback_global_hour' => 2000,
        'demo_intelligence_global_hour' => 1500,
        'official_alerts_global_hour' => 3000,
    ],
    'storage_limits' => [
        // Global row guards complement the per-device and time-based retention.
        'forecast_snapshots' => 50000,
        'synoptic_snapshots' => 50000,
        'push_notifications' => 50000,
        'app_preferences' => 50000,
        'oauth_states' => 5000,
        'weather_alert_events' => 50000,
        'radar_eta_predictions' => max(5000, min(250000, (int)(getenv('METEONEXA_RADAR_ETA_MAX_ROWS') ?: 75000))),
        'decision_verification_samples' => max(5000, min(250000, (int)(getenv('METEONEXA_DECISION_VERIFY_MAX_ROWS') ?: 75000))),
        'predictive_alert_verifications' => max(5000, min(250000, (int)(getenv('METEONEXA_ALERT_VERIFY_MAX_ROWS') ?: 75000))),
        'predictive_alert_opportunities' => max(10000, min(500000, (int)(getenv('METEONEXA_ALERT_OPPORTUNITY_MAX_ROWS') ?: 150000))),
        'radar_tracking_evaluations' => max(5000, min(250000, (int)(getenv('METEONEXA_RADAR3_EVAL_MAX_ROWS') ?: 75000))),
        'runtime_metrics' => max(10000, min(500000, (int)(getenv('METEONEXA_RUNTIME_METRICS_MAX_ROWS') ?: 100000))),
    ],
    'verification' => [
        'retention_days' => max(30, min(730, (int)(getenv('METEONEXA_VERIFICATION_RETENTION_DAYS') ?: 180))),
        'runtime_metrics_retention_days' => max(7, min(180, (int)(getenv('METEONEXA_RUNTIME_METRICS_RETENTION_DAYS') ?: 30))),
        'minimum_publish_samples' => max(5, min(200, (int)(getenv('METEONEXA_MINIMUM_TRUST_SAMPLES') ?: 20))),
    ],
    'provider_orchestrator' => [
        'enabled' => filter_var(getenv('METEONEXA_PROVIDER_PARALLEL') === false ? '1' : getenv('METEONEXA_PROVIDER_PARALLEL'), FILTER_VALIDATE_BOOLEAN),
        'total_budget_seconds' => max(3, min(20, (int)(getenv('METEONEXA_PROVIDER_BUDGET_SECONDS') ?: 9))),
        'per_provider_timeout_seconds' => max(3, min(18, (int)(getenv('METEONEXA_PROVIDER_TIMEOUT_SECONDS') ?: 8))),
    ],
    'radar3' => [
        // Shadow is the safe default: object tracking runs and is scored against
        // observed arrivals, while the proven v2 output remains authoritative.
        'mode' => in_array(strtolower((string)(getenv('METEONEXA_RADAR3_MODE') ?: 'active')), ['off','shadow','active'], true)
            ? strtolower((string)(getenv('METEONEXA_RADAR3_MODE') ?: 'active')) : 'active',
    ],
    'netatmo' => [
        'client_id' => getenv('METEONEXA_NETATMO_CLIENT_ID') ?: '',
        'client_secret' => getenv('METEONEXA_NETATMO_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('METEONEXA_NETATMO_REDIRECT_URI') ?: '',
        'scope' => 'read_station',
    ],
];
