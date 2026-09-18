<?php
declare(strict_types=1);
function meteonexa_run_sqlite_legacy_upgrade(PDO $pdo, int $schemaVersion, int $currentSchema) : int {
    if ($schemaVersion < $currentSchema) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS smtp_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            host TEXT NOT NULL,
            port INTEGER NOT NULL,
            encryption TEXT NOT NULL,
            username TEXT NOT NULL,
            password_encrypted TEXT NOT NULL,
            from_email TEXT NOT NULL,
            from_name TEXT NOT NULL,
            timeout_seconds INTEGER NOT NULL DEFAULT 18,
            updated_at TEXT NOT NULL
        )');
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            provider TEXT NOT NULL DEFAULT 'openrouter' CHECK (provider IN ('openrouter','groq')),
            model TEXT NOT NULL DEFAULT 'nvidia/nemotron-3-super-120b-a12b:free',
            api_key_encrypted TEXT NOT NULL DEFAULT '',
            site_url TEXT NOT NULL DEFAULT '',
            site_name TEXT NOT NULL DEFAULT 'MeteoNexa',
            updated_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE TABLE IF NOT EXISTS translations (
            locale TEXT NOT NULL,
            text_key TEXT NOT NULL,
            translation TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (locale, text_key)
        )');
            $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
            template_key TEXT NOT NULL, locale TEXT NOT NULL DEFAULT 'it', subject_template TEXT NOT NULL,
            kicker TEXT NOT NULL DEFAULT '', heading TEXT NOT NULL DEFAULT '', intro TEXT NOT NULL DEFAULT '',
            footer TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, PRIMARY KEY(template_key,locale)
        )");
            // Kept for upgrade compatibility with early builds. New code uses
            // app_preferences instead.
            $pdo->exec('CREATE TABLE IF NOT EXISTS browser_preferences (
            preference_key TEXT PRIMARY KEY,
            preference_value TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_preferences (
            client_id TEXT PRIMARY KEY,
            language TEXT NOT NULL DEFAULT 'it',
            theme TEXT NOT NULL DEFAULT 'system',
            browser_language TEXT NOT NULL DEFAULT '',
            browser_theme TEXT NOT NULL DEFAULT 'dark',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS ui_visibility (
            feature_key TEXT PRIMARY KEY,
            guest_visible INTEGER NOT NULL DEFAULT 0 CHECK (guest_visible IN (0,1)),
            authenticated_visible INTEGER NOT NULL DEFAULT 1 CHECK (authenticated_visible IN (0,1)),
            updated_at TEXT NOT NULL
        )");
            $visibilityDefaults =['page.home'=>[1, 1], 'page.radar'=>[1, 1], 'page.favorites'=>[1, 1], 'page.details'=>[1, 1], 'page.intelligence'=>[1, 1], 'page.advanced'=>[1, 1], 'page.route'=>[0, 1], 'page.history'=>[0, 1], 'page.alerts'=>[0, 0], 'page.devices'=>[0, 1], 'page.feedback'=>[1, 1], 'feature.assistant'=>[0, 1], 'feature.notifications'=>[0, 1], 'feature.integrations'=>[0, 1], 'section.radar.archive'=>[0, 1], 'feature.impact'=>[1, 1], 'feature.nowcast.pro'=>[1, 1], 'feature.forecast.explain'=>[1, 1], 'feature.model.accuracy'=>[0, 1], 'feature.route.intelligence'=>[0, 1], 'feature.ai.briefing'=>[0, 1], 'feature.ai.proactive'=>[0, 1], 'feature.radar.predictive'=>[1, 1], 'feature.smart.demo'=>[1, 1], 'feature.smart.alerts'=>[0, 1], 'feature.official.alerts'=>[1, 1], 'feature.hyperlocal'=>[0, 1],];
            $visibilitySeed = $pdo->prepare('INSERT OR IGNORE INTO ui_visibility(feature_key,guest_visible,authenticated_visible,updated_at) VALUES(:feature,:guest,:authenticated,:updated)');
            foreach ($visibilityDefaults as $featureKey=>[$guestVisible, $authenticatedVisible]) {
                $visibilitySeed->execute([':feature'=>$featureKey, ':guest'=>$guestVisible, ':authenticated'=>$authenticatedVisible, ':updated'=>gmdate('c'),]);
            }
            $pdo->exec('CREATE TABLE IF NOT EXISTS alert_profiles (
            device_id TEXT PRIMARY KEY,
            profile_json TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS forecast_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            location_key TEXT NOT NULL,
            snapshot_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_forecast_snapshots_device_location ON forecast_snapshots(device_id, location_key, created_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS personal_weather_preferences (
            device_id TEXT PRIMARY KEY,
            activities_json TEXT NOT NULL DEFAULT '[]',
            briefing_enabled INTEGER NOT NULL DEFAULT 0 CHECK (briefing_enabled IN (0,1)),
            briefing_hour INTEGER NOT NULL DEFAULT 8 CHECK (briefing_hour BETWEEN 0 AND 23),
            briefing_hour_set INTEGER NOT NULL DEFAULT 0 CHECK (briefing_hour_set IN (0,1)),
            proactive_enabled INTEGER NOT NULL DEFAULT 0 CHECK (proactive_enabled IN (0,1)),
            updated_at TEXT NOT NULL
        )");
            $personalPreferenceColumns = array_column($pdo->query('PRAGMA table_info(personal_weather_preferences)')->fetchAll(), 'name');
            if (!in_array('briefing_hour_set', $personalPreferenceColumns, true)) {
                $pdo->exec("ALTER TABLE personal_weather_preferences ADD COLUMN briefing_hour_set INTEGER NOT NULL DEFAULT 0 CHECK (briefing_hour_set IN (0,1))");
            }
            if (!in_array('proactive_enabled', $personalPreferenceColumns, true)) {
                $pdo->exec("ALTER TABLE personal_weather_preferences ADD COLUMN proactive_enabled INTEGER NOT NULL DEFAULT 0 CHECK (proactive_enabled IN (0,1))");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS model_forecast_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            location_key TEXT NOT NULL,
            model_name TEXT NOT NULL,
            target_time TEXT NOT NULL,
            horizon_hours INTEGER NOT NULL,
            temperature REAL,
            precipitation REAL,
            wind_gust REAL,
            created_at TEXT NOT NULL,
            UNIQUE(device_id, location_key, model_name, target_time, horizon_hours)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_model_forecast_verify ON model_forecast_samples(device_id, location_key, target_time, model_name)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS model_observation_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            location_key TEXT NOT NULL,
            observed_time TEXT NOT NULL,
            temperature REAL,
            precipitation REAL,
            wind_gust REAL,
            created_at TEXT NOT NULL,
            UNIQUE(device_id, location_key, observed_time)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_model_observation_verify ON model_observation_samples(device_id, location_key, observed_time)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS synoptic_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            location_key TEXT NOT NULL,
            analysis_json TEXT NOT NULL,
            created_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_synoptic_snapshots_lookup ON synoptic_snapshots(device_id, location_key, id)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            location_name TEXT NOT NULL DEFAULT '',
            latitude REAL,
            longitude REAL,
            timezone TEXT NOT NULL DEFAULT 'auto',
            profile_json TEXT NOT NULL DEFAULT '{}',
            last_notice_key TEXT NOT NULL DEFAULT '',
            last_notice_at INTEGER NOT NULL DEFAULT 0,
            last_check_at INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_subscriptions_active ON push_subscriptions(active, last_check_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            notice_key TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            target_url TEXT NOT NULL DEFAULT './#notifications',
            tag TEXT NOT NULL DEFAULT 'meteonexa-push',
            created_at TEXT NOT NULL,
            delivered_at TEXT NOT NULL DEFAULT '',
            read_at TEXT NOT NULL DEFAULT '',
            dismissed_at TEXT NOT NULL DEFAULT '',
            expires_at TEXT NOT NULL DEFAULT '',
            UNIQUE(device_id, notice_key)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_notifications_pending ON push_notifications(device_id, delivered_at, id)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS weather_alert_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            event_key TEXT NOT NULL,
            event_type TEXT NOT NULL,
            severity TEXT NOT NULL,
            confidence INTEGER NOT NULL DEFAULT 0,
            location_name TEXT NOT NULL DEFAULT '',
            starts_at TEXT NOT NULL DEFAULT '',
            ends_at TEXT NOT NULL DEFAULT '',
            payload_json TEXT NOT NULL DEFAULT '{}',
            delivered_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(device_id,event_key)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_weather_alert_events_device ON weather_alert_events(device_id,created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_weather_alert_events_cleanup ON weather_alert_events(created_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS model_skill_samples (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL, location_key TEXT NOT NULL, model_name TEXT NOT NULL,
            metric TEXT NOT NULL, horizon_hours INTEGER NOT NULL, target_time TEXT NOT NULL,
            predicted_value REAL, observed_value REAL, error_value REAL, brier_score REAL,
            issued_at TEXT NOT NULL, verified_at TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
            UNIQUE(device_id,location_key,model_name,metric,horizon_hours,target_time)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_model_skill_verify ON model_skill_samples(device_id,location_key,target_time,verified_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_model_skill_metric ON model_skill_samples(device_id,location_key,metric,horizon_hours,verified_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS forecast_run_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL, location_key TEXT NOT NULL, snapshot_json TEXT NOT NULL, created_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_forecast_run_lookup ON forecast_run_snapshots(device_id,location_key,id)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS observation_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT, device_id TEXT NOT NULL, location_key TEXT NOT NULL, source_type TEXT NOT NULL, source_id TEXT NOT NULL DEFAULT '', observed_at TEXT NOT NULL,
            temperature REAL, precipitation REAL, wind_gust REAL, rain_event REAL, storm_event REAL, snow_event REAL, distance_km REAL, quality_score INTEGER NOT NULL DEFAULT 0,
            payload_json TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL, UNIQUE(device_id,location_key,source_type,source_id,observed_at)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_observation_evidence_lookup ON observation_evidence(device_id,location_key,observed_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS nowcast_fusion_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, device_id TEXT NOT NULL, location_key TEXT NOT NULL, snapshot_json TEXT NOT NULL, created_at TEXT NOT NULL)");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nowcast_fusion_lookup ON nowcast_fusion_snapshots(device_id,location_key,id)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS account_sync_state (
            account_hash TEXT NOT NULL, namespace TEXT NOT NULL, item_key TEXT NOT NULL, payload_json TEXT NOT NULL DEFAULT '{}', deleted INTEGER NOT NULL DEFAULT 0 CHECK(deleted IN (0,1)),
            revision INTEGER NOT NULL DEFAULT 1, updated_by_device TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, PRIMARY KEY(account_hash,namespace,item_key)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_account_sync_updated ON account_sync_state(account_hash,updated_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_account_sync_namespace ON account_sync_state(account_hash,namespace,deleted)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS account_activity_profiles (
            account_hash TEXT NOT NULL, activity TEXT NOT NULL, thresholds_json TEXT NOT NULL DEFAULT '{}', revision INTEGER NOT NULL DEFAULT 1,
            updated_by_device TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, PRIMARY KEY(account_hash,activity)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_account_activity_updated ON account_activity_profiles(account_hash,updated_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS weather_provider_health (
            provider_id TEXT PRIMARY KEY,status TEXT NOT NULL DEFAULT 'unknown',last_attempt_at TEXT NOT NULL DEFAULT '',last_success_at TEXT NOT NULL DEFAULT '',last_failure_at TEXT NOT NULL DEFAULT '',latency_ms INTEGER NOT NULL DEFAULT 0,consecutive_failures INTEGER NOT NULL DEFAULT 0,freshness_seconds INTEGER,details_json TEXT NOT NULL DEFAULT '{}',updated_at TEXT NOT NULL
        )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS weather_pipeline_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,worker_name TEXT NOT NULL,status TEXT NOT NULL,started_at TEXT NOT NULL,finished_at TEXT NOT NULL DEFAULT '',duration_ms INTEGER NOT NULL DEFAULT 0,locations_processed INTEGER NOT NULL DEFAULT 0,success_count INTEGER NOT NULL DEFAULT 0,failure_count INTEGER NOT NULL DEFAULT 0,details_json TEXT NOT NULL DEFAULT '{}'
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_weather_pipeline_runs_worker ON weather_pipeline_runs(worker_name,id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_weather_pipeline_runs_started ON weather_pipeline_runs(started_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS official_alert_state (
            location_key TEXT NOT NULL,alert_key TEXT NOT NULL,location_name TEXT NOT NULL DEFAULT '',provider_alert_id TEXT NOT NULL DEFAULT '',severity TEXT NOT NULL DEFAULT 'yellow',starts_at TEXT NOT NULL DEFAULT '',ends_at TEXT NOT NULL DEFAULT '',source_updated_at TEXT NOT NULL DEFAULT '',content_hash TEXT NOT NULL,first_seen_at TEXT NOT NULL,last_seen_at TEXT NOT NULL,last_change_type TEXT NOT NULL DEFAULT 'new',last_change_at TEXT NOT NULL,previous_severity TEXT NOT NULL DEFAULT '',previous_ends_at TEXT NOT NULL DEFAULT '',payload_json TEXT NOT NULL DEFAULT '{}',PRIMARY KEY(location_key,alert_key)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_alert_state_change ON official_alert_state(location_key,last_change_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS official_alert_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,location_key TEXT NOT NULL,alert_key TEXT NOT NULL,revision_type TEXT NOT NULL,previous_severity TEXT NOT NULL DEFAULT '',new_severity TEXT NOT NULL DEFAULT '',previous_ends_at TEXT NOT NULL DEFAULT '',new_ends_at TEXT NOT NULL DEFAULT '',provider_alert_id TEXT NOT NULL DEFAULT '',payload_json TEXT NOT NULL DEFAULT '{}',observed_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_alert_revisions_location ON official_alert_revisions(location_key,id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_official_alert_revisions_time ON official_alert_revisions(observed_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS lightning_observation_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,observed_at TEXT NOT NULL,source TEXT NOT NULL,count_30m INTEGER NOT NULL DEFAULT 0,nearest_km REAL,approaching INTEGER NOT NULL DEFAULT 0,payload_json TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lightning_snapshots_location ON lightning_observation_snapshots(device_id,location_key,id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lightning_snapshots_time ON lightning_observation_snapshots(created_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS radar_frame_quality (
            frame_id INTEGER PRIMARY KEY,quality_score INTEGER NOT NULL DEFAULT 0,signal_coverage REAL NOT NULL DEFAULT 0,fetch_latency_ms INTEGER NOT NULL DEFAULT 0,provider_frame_age_seconds INTEGER NOT NULL DEFAULT 0,checked_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radar_quality_checked ON radar_frame_quality(checked_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS saved_locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'custom', label TEXT NOT NULL,
            location_name TEXT NOT NULL, admin1 TEXT NOT NULL DEFAULT '', latitude REAL NOT NULL, longitude REAL NOT NULL,
            timezone TEXT NOT NULL DEFAULT 'auto', active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
            UNIQUE(device_id,label)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_saved_locations_active ON saved_locations(device_id,active,updated_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS radar_archive_locations (
            id TEXT PRIMARY KEY,
            device_id TEXT NOT NULL,
            location_name TEXT NOT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            last_capture_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS radar_archive_frames (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            location_id TEXT NOT NULL,
            frame_time INTEGER NOT NULL,
            zoom_level INTEGER NOT NULL,
            tile_x INTEGER NOT NULL,
            tile_y INTEGER NOT NULL,
            image_path TEXT NOT NULL,
            bytes_size INTEGER NOT NULL DEFAULT 0,
            source TEXT NOT NULL DEFAULT 'LibreWXR',
            created_at TEXT NOT NULL,
            UNIQUE(location_id, frame_time),
            FOREIGN KEY(location_id) REFERENCES radar_archive_locations(id) ON DELETE CASCADE
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_radar_archive_time ON radar_archive_frames(location_id, frame_time)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS netatmo_accounts (
            device_id TEXT PRIMARY KEY,
            access_token_enc TEXT NOT NULL,
            refresh_token_enc TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            scope TEXT NOT NULL DEFAULT 'read_station',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS oauth_states (
            state_hash TEXT PRIMARY KEY,
            device_id TEXT NOT NULL,
            return_url TEXT NOT NULL DEFAULT '',
            language TEXT NOT NULL DEFAULT 'it',
            expires_at INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS device_credentials (
            device_id TEXT PRIMARY KEY,
            key_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL
        )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_otp (
            email_hash TEXT PRIMARY KEY,
            language TEXT NOT NULL,
            code_hash TEXT NOT NULL,
            sent_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            ip_hash TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_challenges (
            challenge_hash TEXT PRIMARY KEY,
            email_hash TEXT NOT NULL,
            device_id TEXT NOT NULL,
            language TEXT NOT NULL DEFAULT 'it',
            code_hash TEXT NOT NULL,
            sent_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            ip_hash TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_login_challenges_email ON auth_login_challenges(email_hash,sent_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_login_challenges_device ON auth_login_challenges(device_id,sent_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_login_challenges_expires ON auth_login_challenges(expires_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            scope TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            request_count INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL,
            PRIMARY KEY(scope, key_hash)
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_updated ON rate_limits(updated_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_sessions (
            session_hash TEXT PRIMARY KEY,
            email_hash TEXT NOT NULL,
            email_encrypted TEXT NOT NULL DEFAULT '',
            device_id TEXT NOT NULL,
            display_name TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_sessions_expires ON auth_sessions(expires_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_sessions_email ON auth_sessions(email_hash, created_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS trusted_devices (
            trust_hash TEXT PRIMARY KEY,
            email_hash TEXT NOT NULL,
            email_encrypted TEXT NOT NULL,
            device_id TEXT NOT NULL,
            display_name TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trusted_devices_email ON trusted_devices(email_hash, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_trusted_devices_device ON trusted_devices(device_id, created_at)');
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_access_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, session_hash TEXT NOT NULL, email_hash TEXT NOT NULL, device_id TEXT NOT NULL,
            device_type TEXT NOT NULL DEFAULT 'Web', platform TEXT NOT NULL DEFAULT '', browser TEXT NOT NULL DEFAULT '',
            client_mode TEXT NOT NULL DEFAULT 'web', timezone TEXT NOT NULL DEFAULT '', ip_encrypted TEXT NOT NULL,
            location_label TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, last_seen_at INTEGER NOT NULL,
            ended_at INTEGER NOT NULL DEFAULT 0, end_reason TEXT NOT NULL DEFAULT ''
        )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_access_email ON auth_access_history(email_hash,last_seen_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_access_session ON auth_access_history(session_hash)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_access_device ON auth_access_history(device_id,last_seen_at)');
            // Older databases predated oauth_states.language.
            $oauthColumns = array_column($pdo->query('PRAGMA table_info(oauth_states)')->fetchAll(), 'name');
            if (!in_array('language', $oauthColumns, true)) {
                $pdo->exec("ALTER TABLE oauth_states ADD COLUMN language TEXT NOT NULL DEFAULT 'it'");
            }
            $authSessionColumns = array_column($pdo->query('PRAGMA table_info(auth_sessions)')->fetchAll(), 'name');
            if (!in_array('email_encrypted', $authSessionColumns, true)) {
                $pdo->exec("ALTER TABLE auth_sessions ADD COLUMN email_encrypted TEXT NOT NULL DEFAULT ''");
            }
            if ($schemaVersion < 9) {
                // The previous schema stored only an irreversible email HMAC. It is
                // impossible to reconstruct the full address safely, so existing
                // sessions are invalidated once and the next OTP login persists the
                // address encrypted with the active per-installation secret.
                $pdo->exec('DELETE FROM auth_sessions');
                // Alerts/notification delivery is now centralized in Settings. Keep
                // the old page DB-configurable, but disabled by default after upgrade.
                $visibility = $pdo->prepare('UPDATE ui_visibility SET guest_visible=0, authenticated_visible=0, updated_at=:updated WHERE feature_key=:feature');
                $visibility->execute([':updated'=>gmdate('c'), ':feature'=>'page.alerts']);
                // Old notification deep links continue to work client-side, but
                // newly persisted pending notifications use the canonical
                // Settings notification center.
                $pdo->exec("UPDATE push_notifications SET target_url='./#notifications' WHERE target_url='./#alerts'");
            }
            if ($schemaVersion < 10) {
                // The SMTP activation UI was a short-lived migration mechanism.
                // Remove its now-unused catalogue entries/markers from runtime DBs.
                $pdo->exec("DELETE FROM translations WHERE text_key LIKE 'smtp.setup.%'");
                $pdo->exec("DELETE FROM app_metadata WHERE meta_key LIKE 'smtp_activation_%' OR meta_key LIKE 'smtp_legacy_recovery_%'");
            }
            if ($schemaVersion < 11) {
                // Optical-flow and synoptic diagnostics were removed from the UI:
                // they depended on remote analysis paths that were not reliable
                // enough to present as product features. Visibility configuration
                // must not keep orphaned feature flags around after upgrade.
                $pdo->exec("DELETE FROM ui_visibility WHERE feature_key IN ('section.advanced.optical','section.advanced.synoptic')");
            }
            if ($schemaVersion < 12) {
                // Trusted-device credentials are separate from active sessions. A
                // normal logout revokes only the session; a full cache/device reset
                // explicitly revokes this remembered-device credential as well.
                $pdo->exec('DELETE FROM trusted_devices WHERE expires_at < ' . time());
            }
            if ($schemaVersion < 13) {
                // AI provider credentials are runtime-only secrets. The table is
                // intentionally created empty; provisioning stores the key encrypted
                // with this installation's deployment secret.
                $pdo->exec("DELETE FROM ai_settings WHERE TRIM(api_key_encrypted) = ''");
            }
            if ($schemaVersion < 14) {
                // Product capabilities are explicit in UI visibility so
                // guest/authenticated behavior is deterministic and DB-driven.
                $visibilitySeed14 = $pdo->prepare('INSERT INTO ui_visibility(feature_key,guest_visible,authenticated_visible,updated_at)
                VALUES(:feature,:guest,:authenticated,:updated)
                ON CONFLICT(feature_key) DO UPDATE SET guest_visible=excluded.guest_visible,
                authenticated_visible=excluded.authenticated_visible,updated_at=excluded.updated_at');
                foreach (['section.radar.archive'=>[0, 1], 'feature.impact'=>[1, 1], 'feature.nowcast.pro'=>[1, 1], 'feature.forecast.explain'=>[1, 1], 'feature.model.accuracy'=>[0, 1], 'feature.route.intelligence'=>[0, 1], 'feature.ai.briefing'=>[0, 1],] as $featureKey=>[$guestVisible, $authenticatedVisible]) {
                    $visibilitySeed14->execute([':feature'=>$featureKey, ':guest'=>$guestVisible, ':authenticated'=>$authenticatedVisible, ':updated'=>gmdate('c'),]);
                }
            }
            if ($schemaVersion < 3) {
                // Older builds keyed radar locations only by coordinates. Move
                // legacy rows to a device-scoped id while preserving frames.
                $legacyLocations = $pdo->query('SELECT * FROM radar_archive_locations')->fetchAll();
                foreach ($legacyLocations as $legacy) {
                    $legacyId = (string)($legacy['id']??'');
                    $deviceId = (string)($legacy['device_id']??'');
                    $lat = (float)($legacy['latitude']??0);
                    $lon = (float)($legacy['longitude']??0);
                    $oldExpected = hash('sha256', round($lat, 3) . ':' . round($lon, 3));
                    if ($legacyId===''||$deviceId===''||!hash_equals($oldExpected, $legacyId))continue;
                    $newId = hash('sha256', $deviceId . '|' . round($lat, 3) . ':' . round($lon, 3));
                    if (hash_equals($legacyId, $newId))continue;
                    $insert = $pdo->prepare('INSERT OR IGNORE INTO radar_archive_locations(id,device_id,location_name,latitude,longitude,active,last_capture_at,created_at,updated_at) VALUES(:id,:device,:name,:lat,:lon,:active,:last,:created,:updated)');
                    $insert->execute([':id'=>$newId, ':device'=>$deviceId, ':name'=>(string)$legacy['location_name'], ':lat'=>$lat, ':lon'=>$lon, ':active'=>(int)$legacy['active'], ':last'=>$legacy['last_capture_at'], ':created'=>(string)$legacy['created_at'], ':updated'=>(string)$legacy['updated_at'],]);
                    $duplicates = $pdo->prepare('DELETE FROM radar_archive_frames WHERE location_id=:old AND frame_time IN (SELECT frame_time FROM radar_archive_frames WHERE location_id=:new)');
                    $duplicates->execute([':old'=>$legacyId, ':new'=>$newId]);
                    $pdo->prepare('UPDATE radar_archive_frames SET location_id=:new WHERE location_id=:old')->execute([':new'=>$newId, ':old'=>$legacyId]);
                    $pdo->prepare('DELETE FROM radar_archive_locations WHERE id=:old')->execute([':old'=>$legacyId]);
                }
            }
            if ($schemaVersion < 5) {
                // Sessions created before encrypted display metadata are invalidated
                // once instead of retaining legacy plaintext identifiers.
                $pdo->exec('DELETE FROM auth_sessions');
            }
            if ($schemaVersion < 26) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS product_metrics_daily (metric_date TEXT NOT NULL,event_name TEXT NOT NULL,event_count INTEGER NOT NULL DEFAULT 0 CHECK(event_count>=0),updated_at TEXT NOT NULL,PRIMARY KEY(metric_date,event_name))");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_product_metrics_event ON product_metrics_daily(event_name,metric_date)");
            }
            // Translation revision triggers make direct DB edits observable. The
            // browser can compare a tiny revision hash and only reload the full
            // catalog when a translation actually changes.
            $pdo->exec("CREATE TRIGGER IF NOT EXISTS translations_revision_insert AFTER INSERT ON translations BEGIN
            INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',strftime('%Y-%m-%dT%H:%M:%fZ','now'))
            ON CONFLICT(meta_key) DO UPDATE SET meta_value=CAST(CAST(meta_value AS INTEGER)+1 AS TEXT),updated_at=excluded.updated_at;
        END");
            $pdo->exec("CREATE TRIGGER IF NOT EXISTS translations_revision_update AFTER UPDATE ON translations BEGIN
            INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',strftime('%Y-%m-%dT%H:%M:%fZ','now'))
            ON CONFLICT(meta_key) DO UPDATE SET meta_value=CAST(CAST(meta_value AS INTEGER)+1 AS TEXT),updated_at=excluded.updated_at;
        END");
            $pdo->exec("CREATE TRIGGER IF NOT EXISTS translations_revision_delete AFTER DELETE ON translations BEGIN
            INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',strftime('%Y-%m-%dT%H:%M:%fZ','now'))
            ON CONFLICT(meta_key) DO UPDATE SET meta_value=CAST(CAST(meta_value AS INTEGER)+1 AS TEXT),updated_at=excluded.updated_at;
        END");
            $pdo->prepare("INSERT OR IGNORE INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',:updated)")->execute([':updated'=>gmdate('c')]);
            if ($schemaVersion < 23) {
                if (!meteonexa_db_column_exists($pdo, 'push_notifications', 'read_at'))$pdo->exec("ALTER TABLE push_notifications ADD COLUMN read_at TEXT NOT NULL DEFAULT ''");
                if (!meteonexa_db_column_exists($pdo, 'push_notifications', 'dismissed_at'))$pdo->exec("ALTER TABLE push_notifications ADD COLUMN dismissed_at TEXT NOT NULL DEFAULT ''");
                if (!meteonexa_db_column_exists($pdo, 'push_notifications', 'expires_at'))$pdo->exec("ALTER TABLE push_notifications ADD COLUMN expires_at TEXT NOT NULL DEFAULT ''");
                $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
                template_key TEXT NOT NULL, locale TEXT NOT NULL DEFAULT 'it', subject_template TEXT NOT NULL,
                kicker TEXT NOT NULL DEFAULT '', heading TEXT NOT NULL DEFAULT '', intro TEXT NOT NULL DEFAULT '',
                footer TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, PRIMARY KEY(template_key,locale)
            )");
            }
            $schema = $pdo->prepare('INSERT INTO app_metadata(meta_key, meta_value, updated_at) VALUES(:key, :value, :updated_at) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value, updated_at=excluded.updated_at');
            $schema->execute([':key'=>'schema_version', ':value'=>(string)$currentSchema, ':updated_at'=>gmdate('c')]);
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            $pdo = null;
            throw $error;
        }
    }
    return max($schemaVersion, $currentSchema);
}
