-- MeteoNexa schema — MySQL/MariaDB baseline for Aruba Hosting
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS app_metadata (
  meta_key VARCHAR(191) PRIMARY KEY,
  meta_value TEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smtp_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  host VARCHAR(255) NOT NULL,
  port INT NOT NULL,
  encryption VARCHAR(16) NOT NULL,
  username VARCHAR(255) NOT NULL,
  password_encrypted TEXT NOT NULL,
  from_email VARCHAR(255) NOT NULL,
  from_name VARCHAR(255) NOT NULL,
  timeout_seconds INT NOT NULL DEFAULT 18,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Non-secret delivery profile. Runtime remains DB-authoritative; password_encrypted is intentionally empty.
INSERT IGNORE INTO smtp_settings(id,host,port,encryption,username,password_encrypted,from_email,from_name,timeout_seconds,updated_at)
VALUES(1,'',587,'tls','','','alerts@meteonexa.com','MeteoNexa',18,UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS ai_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  provider VARCHAR(32) NOT NULL DEFAULT 'openrouter',
  model VARCHAR(191) NOT NULL DEFAULT 'nvidia/nemotron-3-super-120b-a12b:free',
  api_key_encrypted TEXT NOT NULL,
  site_url VARCHAR(512) NOT NULL DEFAULT '',
  site_name VARCHAR(191) NOT NULL DEFAULT 'MeteoNexa',
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS translations (
  locale VARCHAR(8) NOT NULL,
  text_key VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  translation MEDIUMTEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY(locale,text_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
  template_key VARCHAR(80) NOT NULL,
  locale VARCHAR(8) NOT NULL DEFAULT 'it',
  subject_template VARCHAR(255) NOT NULL,
  kicker VARCHAR(191) NOT NULL DEFAULT '',
  heading VARCHAR(255) NOT NULL DEFAULT '',
  intro TEXT NOT NULL,
  footer TEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY(template_key,locale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS browser_preferences (
  preference_key VARCHAR(191) PRIMARY KEY,
  preference_value TEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_preferences (
  client_id VARCHAR(64) PRIMARY KEY,
  language VARCHAR(8) NOT NULL DEFAULT 'it',
  theme VARCHAR(16) NOT NULL DEFAULT 'system',
  browser_language VARCHAR(32) NOT NULL DEFAULT '',
  browser_theme VARCHAR(16) NOT NULL DEFAULT 'dark',
  created_at VARCHAR(40) NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ui_visibility (
  feature_key VARCHAR(191) PRIMARY KEY,
  guest_visible TINYINT(1) NOT NULL DEFAULT 0,
  authenticated_visible TINYINT(1) NOT NULL DEFAULT 1,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_profiles (
  device_id VARCHAR(191) PRIMARY KEY,
  profile_json MEDIUMTEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forecast_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  KEY idx_forecast_snapshots_device_location(device_id,location_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_weather_preferences (
  device_id VARCHAR(191) PRIMARY KEY,
  activities_json MEDIUMTEXT NOT NULL,
  briefing_enabled TINYINT(1) NOT NULL DEFAULT 0,
  briefing_hour TINYINT UNSIGNED NOT NULL DEFAULT 8,
  briefing_hour_set TINYINT(1) NOT NULL DEFAULT 0,
  proactive_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_forecast_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  model_name VARCHAR(80) NOT NULL,
  target_time VARCHAR(40) NOT NULL,
  horizon_hours INT NOT NULL,
  temperature DOUBLE NULL,
  precipitation DOUBLE NULL,
  wind_gust DOUBLE NULL,
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_model_forecast(device_id,location_key,model_name,target_time,horizon_hours),
  KEY idx_model_forecast_verify(device_id,location_key,target_time,model_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_observation_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  observed_time VARCHAR(40) NOT NULL,
  temperature DOUBLE NULL,
  precipitation DOUBLE NULL,
  wind_gust DOUBLE NULL,
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_model_observation(device_id,location_key,observed_time),
  KEY idx_model_observation_verify(device_id,location_key,observed_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS model_skill_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  model_name VARCHAR(80) NOT NULL,
  metric VARCHAR(24) NOT NULL,
  horizon_hours INT NOT NULL,
  target_time VARCHAR(40) NOT NULL,
  predicted_value DOUBLE NULL,
  observed_value DOUBLE NULL,
  error_value DOUBLE NULL,
  brier_score DOUBLE NULL,
  issued_at VARCHAR(40) NOT NULL,
  verified_at VARCHAR(40) NOT NULL DEFAULT '',
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_model_skill(device_id,location_key,model_name,metric,horizon_hours,target_time),
  KEY idx_model_skill_verify(device_id,location_key,target_time,verified_at),
  KEY idx_model_skill_metric(device_id,location_key,metric,horizon_hours,verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forecast_run_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  KEY idx_forecast_run_lookup(device_id,location_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS observation_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  source_id VARCHAR(191) NOT NULL DEFAULT '',
  observed_at VARCHAR(40) NOT NULL,
  temperature DOUBLE NULL,
  precipitation DOUBLE NULL,
  wind_gust DOUBLE NULL,
  rain_event DOUBLE NULL,
  storm_event DOUBLE NULL,
  snow_event DOUBLE NULL,
  distance_km DOUBLE NULL,
  quality_score INT NOT NULL DEFAULT 0,
  payload_json MEDIUMTEXT NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_observation_evidence(device_id,location_key,source_type,source_id,observed_at),
  KEY idx_observation_evidence_lookup(device_id,location_key,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nowcast_fusion_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  snapshot_json MEDIUMTEXT NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  KEY idx_nowcast_fusion_lookup(device_id,location_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radar_eta_predictions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  prediction_key VARCHAR(64) NOT NULL,
  issued_at VARCHAR(40) NOT NULL,
  predicted_at VARCHAR(40) NOT NULL,
  eta_minutes INT NOT NULL,
  tolerance_minutes INT NOT NULL DEFAULT 10,
  confidence INT NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  verified_at VARCHAR(40) NOT NULL DEFAULT '',
  observed_at VARCHAR(40) NOT NULL DEFAULT '',
  error_minutes DOUBLE NULL,
  absolute_error_minutes DOUBLE NULL,
  algorithm VARCHAR(32) NOT NULL DEFAULT 'radar-v2',
  ground_truth_source VARCHAR(64) NOT NULL DEFAULT '',
  ground_truth_quality INT NOT NULL DEFAULT 0,
  ground_truth_json MEDIUMTEXT NOT NULL,
  verification_method VARCHAR(48) NOT NULL DEFAULT '',
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_radar_eta_prediction(device_id,location_key,prediction_key),
  KEY idx_radar_eta_verify(device_id,location_key,status,predicted_at),
  KEY idx_radar_eta_algorithm(device_id,location_key,algorithm,status,predicted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS decision_verification_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  verification_key VARCHAR(64) NOT NULL,
  kind VARCHAR(24) NOT NULL,
  subject VARCHAR(80) NOT NULL,
  starts_at VARCHAR(40) NOT NULL,
  ends_at VARCHAR(40) NOT NULL,
  recommendation_score DOUBLE NULL,
  payload_json MEDIUMTEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  observed_json MEDIUMTEXT NOT NULL,
  verified_at VARCHAR(40) NOT NULL DEFAULT '',
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_decision_verification(device_id,location_key,verification_key),
  KEY idx_decision_verify(device_id,location_key,status,starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS predictive_alert_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  event_key VARCHAR(96) NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  predicted_at VARCHAR(40) NOT NULL,
  window_start VARCHAR(40) NOT NULL,
  window_end VARCHAR(40) NOT NULL,
  confidence INT NOT NULL DEFAULT 0,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  outcome VARCHAR(32) NOT NULL DEFAULT '',
  observed_at VARCHAR(40) NOT NULL DEFAULT '',
  verified_at VARCHAR(40) NOT NULL DEFAULT '',
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_predictive_alert_verification(device_id,location_key,event_key),
  KEY idx_predictive_alert_verify(device_id,location_key,status,window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS runtime_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(40) NOT NULL,
  metric_name VARCHAR(80) NOT NULL,
  status VARCHAR(24) NOT NULL,
  metric_value DOUBLE NULL,
  meta_json MEDIUMTEXT NOT NULL,
  trace_id VARCHAR(64) NOT NULL DEFAULT '',
  duration_ms DOUBLE NULL,
  created_at VARCHAR(40) NOT NULL,
  KEY idx_runtime_metrics_name(category,metric_name,id),
  KEY idx_runtime_metrics_time(created_at),
  KEY idx_runtime_metrics_trace(trace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_metrics_daily (
  metric_date VARCHAR(10) NOT NULL,
  event_name VARCHAR(64) NOT NULL,
  event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY(metric_date,event_name),
  KEY idx_product_metrics_event(event_name,metric_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS predictive_alert_opportunities (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  opportunity_key VARCHAR(96) NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  window_start VARCHAR(40) NOT NULL,
  window_end VARCHAR(40) NOT NULL,
  predicted TINYINT(1) NOT NULL DEFAULT 0,
  confidence INT NOT NULL DEFAULT 0,
  raw_score DOUBLE NOT NULL DEFAULT 0,
  observed TINYINT(1) NULL,
  outcome VARCHAR(8) NOT NULL DEFAULT '',
  evidence_json MEDIUMTEXT NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  observed_at VARCHAR(40) NOT NULL DEFAULT '',
  verified_at VARCHAR(40) NOT NULL DEFAULT '',
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_predictive_opportunity(device_id,location_key,opportunity_key),
  KEY idx_predictive_opportunity_verify(device_id,location_key,status,window_end),
  KEY idx_predictive_opportunity_type(event_type,status,verified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_sync_state (
  account_hash VARCHAR(128) NOT NULL,
  namespace VARCHAR(40) NOT NULL,
  item_key VARCHAR(191) NOT NULL,
  payload_json MEDIUMTEXT NOT NULL,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  revision BIGINT NOT NULL DEFAULT 1,
  updated_by_device VARCHAR(191) NOT NULL DEFAULT '',
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY(account_hash,namespace,item_key),
  KEY idx_account_sync_updated(account_hash,updated_at),
  KEY idx_account_sync_namespace(account_hash,namespace,deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_activity_profiles (
  account_hash VARCHAR(128) NOT NULL,
  activity VARCHAR(32) NOT NULL,
  thresholds_json MEDIUMTEXT NOT NULL,
  revision BIGINT NOT NULL DEFAULT 1,
  updated_by_device VARCHAR(191) NOT NULL DEFAULT '',
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY(account_hash,activity),
  KEY idx_account_activity_updated(account_hash,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  role VARCHAR(24) NOT NULL DEFAULT 'custom',
  label VARCHAR(80) NOT NULL,
  location_name VARCHAR(191) NOT NULL,
  admin1 VARCHAR(191) NOT NULL DEFAULT '',
  latitude DOUBLE NOT NULL,
  longitude DOUBLE NOT NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'auto',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at VARCHAR(40) NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_saved_location(device_id,label),
  KEY idx_saved_locations_active(device_id,active,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS synoptic_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(191) NOT NULL,
  analysis_json MEDIUMTEXT NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  KEY idx_synoptic_snapshots_lookup(device_id,location_key,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  scope VARCHAR(80) NOT NULL,
  key_hash VARCHAR(64) NOT NULL,
  window_start BIGINT NOT NULL,
  request_count INT NOT NULL DEFAULT 0,
  updated_at VARCHAR(40) NOT NULL,
  PRIMARY KEY(scope,key_hash),
  KEY idx_rate_limits_updated(updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_credentials (
  device_id VARCHAR(191) PRIMARY KEY,
  key_hash VARCHAR(128) NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  last_seen_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_otp (
  email_hash VARCHAR(128) PRIMARY KEY,
  language VARCHAR(8) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  sent_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  ip_hash VARCHAR(128) NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_login_challenges (
  challenge_hash VARCHAR(128) PRIMARY KEY,
  email_hash VARCHAR(128) NOT NULL,
  device_id VARCHAR(191) NOT NULL,
  language VARCHAR(8) NOT NULL DEFAULT 'it',
  code_hash VARCHAR(255) NOT NULL,
  sent_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  ip_hash VARCHAR(128) NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  KEY idx_auth_login_challenges_email(email_hash,sent_at),
  KEY idx_auth_login_challenges_device(device_id,sent_at),
  KEY idx_auth_login_challenges_expires(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
  session_hash VARCHAR(128) PRIMARY KEY,
  email_hash VARCHAR(128) NOT NULL,
  device_id VARCHAR(191) NOT NULL,
  display_name VARCHAR(191) NOT NULL DEFAULT '',
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  last_seen_at BIGINT NOT NULL,
  email_encrypted TEXT NOT NULL,
  KEY idx_auth_sessions_email(email_hash,created_at),
  KEY idx_auth_sessions_expires(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trusted_devices (
  trust_hash VARCHAR(128) PRIMARY KEY,
  email_hash VARCHAR(128) NOT NULL,
  email_encrypted TEXT NOT NULL,
  device_id VARCHAR(191) NOT NULL,
  display_name VARCHAR(191) NOT NULL,
  created_at BIGINT NOT NULL,
  expires_at BIGINT NOT NULL,
  last_seen_at BIGINT NOT NULL,
  KEY idx_trusted_devices_device(device_id,created_at),
  KEY idx_trusted_devices_email(email_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS auth_access_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_hash VARCHAR(128) NOT NULL,
  email_hash VARCHAR(128) NOT NULL,
  device_id VARCHAR(191) NOT NULL,
  device_type VARCHAR(40) NOT NULL DEFAULT 'Web',
  platform VARCHAR(80) NOT NULL DEFAULT '',
  browser VARCHAR(80) NOT NULL DEFAULT '',
  client_mode VARCHAR(16) NOT NULL DEFAULT 'web',
  timezone VARCHAR(80) NOT NULL DEFAULT '',
  ip_encrypted TEXT NOT NULL,
  location_label VARCHAR(191) NOT NULL DEFAULT '',
  created_at BIGINT NOT NULL,
  last_seen_at BIGINT NOT NULL,
  ended_at BIGINT NOT NULL DEFAULT 0,
  end_reason VARCHAR(32) NOT NULL DEFAULT '',
  KEY idx_auth_access_email(email_hash,last_seen_at),
  KEY idx_auth_access_session(session_hash),
  KEY idx_auth_access_device(device_id,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radar_archive_locations (
  id VARCHAR(128) PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_name VARCHAR(255) NOT NULL,
  latitude DOUBLE NOT NULL,
  longitude DOUBLE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_capture_at VARCHAR(40) NULL,
  created_at VARCHAR(40) NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radar_archive_frames (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  location_id VARCHAR(128) NOT NULL,
  frame_time BIGINT NOT NULL,
  zoom_level INT NOT NULL,
  tile_x INT NOT NULL,
  tile_y INT NOT NULL,
  image_path VARCHAR(768) NOT NULL,
  bytes_size BIGINT NOT NULL DEFAULT 0,
  source VARCHAR(80) NOT NULL DEFAULT 'LibreWXR',
  created_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_radar_frame(location_id,frame_time),
  KEY idx_radar_archive_time(location_id,frame_time),
  CONSTRAINT fk_radar_location FOREIGN KEY(location_id) REFERENCES radar_archive_locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  endpoint VARCHAR(768) NOT NULL,
  p256dh TEXT NOT NULL,
  auth TEXT NOT NULL,
  location_name VARCHAR(255) NOT NULL DEFAULT '',
  latitude DOUBLE NULL,
  longitude DOUBLE NULL,
  timezone VARCHAR(80) NOT NULL DEFAULT 'auto',
  profile_json MEDIUMTEXT NOT NULL,
  last_notice_key VARCHAR(191) NOT NULL DEFAULT '',
  last_notice_at BIGINT NOT NULL DEFAULT 0,
  last_check_at BIGINT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at VARCHAR(40) NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_push_endpoint(endpoint(191)),
  KEY idx_push_subscriptions_active(active,last_check_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  notice_key VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  target_url VARCHAR(512) NOT NULL DEFAULT './#notifications',
  tag VARCHAR(191) NOT NULL DEFAULT 'meteonexa-push',
  created_at VARCHAR(40) NOT NULL,
  delivered_at VARCHAR(40) NOT NULL DEFAULT '',
  read_at VARCHAR(40) NOT NULL DEFAULT '',
  dismissed_at VARCHAR(40) NOT NULL DEFAULT '',
  expires_at VARCHAR(40) NOT NULL DEFAULT '',
  UNIQUE KEY uq_push_notice(device_id,notice_key),
  KEY idx_push_notifications_pending(device_id,delivered_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_states (
  state_hash VARCHAR(128) PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  return_url VARCHAR(512) NOT NULL DEFAULT '',
  expires_at BIGINT NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  language VARCHAR(8) NOT NULL DEFAULT 'it'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS netatmo_accounts (
  device_id VARCHAR(191) PRIMARY KEY,
  access_token_enc TEXT NOT NULL,
  refresh_token_enc TEXT NOT NULL,
  expires_at BIGINT NOT NULL,
  scope VARCHAR(191) NOT NULL DEFAULT 'read_station',
  created_at VARCHAR(40) NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- MeteoNexa schema — Industrial Weather Pipeline.
CREATE TABLE IF NOT EXISTS weather_provider_health (
  provider_id VARCHAR(96) PRIMARY KEY,
  status VARCHAR(24) NOT NULL DEFAULT 'unknown',
  last_attempt_at VARCHAR(40) NOT NULL DEFAULT '',
  last_success_at VARCHAR(40) NOT NULL DEFAULT '',
  last_failure_at VARCHAR(40) NOT NULL DEFAULT '',
  latency_ms INT NOT NULL DEFAULT 0,
  consecutive_failures INT NOT NULL DEFAULT 0,
  freshness_seconds INT NULL,
  details_json MEDIUMTEXT NOT NULL,
  updated_at VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weather_pipeline_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  worker_name VARCHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL,
  started_at VARCHAR(40) NOT NULL,
  finished_at VARCHAR(40) NOT NULL DEFAULT '',
  duration_ms INT NOT NULL DEFAULT 0,
  locations_processed INT NOT NULL DEFAULT 0,
  success_count INT NOT NULL DEFAULT 0,
  failure_count INT NOT NULL DEFAULT 0,
  details_json MEDIUMTEXT NOT NULL,
  KEY idx_weather_pipeline_runs_worker(worker_name,id),
  KEY idx_weather_pipeline_runs_started(started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS official_alert_state (
  location_key VARCHAR(96) NOT NULL,
  alert_key VARCHAR(96) NOT NULL,
  location_name VARCHAR(191) NOT NULL DEFAULT '',
  provider_alert_id VARCHAR(255) NOT NULL DEFAULT '',
  severity VARCHAR(16) NOT NULL DEFAULT 'yellow',
  starts_at VARCHAR(40) NOT NULL DEFAULT '',
  ends_at VARCHAR(40) NOT NULL DEFAULT '',
  source_updated_at VARCHAR(40) NOT NULL DEFAULT '',
  content_hash VARCHAR(128) NOT NULL,
  first_seen_at VARCHAR(40) NOT NULL,
  last_seen_at VARCHAR(40) NOT NULL,
  last_change_type VARCHAR(24) NOT NULL DEFAULT 'new',
  last_change_at VARCHAR(40) NOT NULL,
  previous_severity VARCHAR(16) NOT NULL DEFAULT '',
  previous_ends_at VARCHAR(40) NOT NULL DEFAULT '',
  payload_json MEDIUMTEXT NOT NULL,
  PRIMARY KEY(location_key,alert_key),
  KEY idx_official_alert_state_change(location_key,last_change_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS official_alert_revisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  location_key VARCHAR(96) NOT NULL,
  alert_key VARCHAR(96) NOT NULL,
  revision_type VARCHAR(24) NOT NULL,
  previous_severity VARCHAR(16) NOT NULL DEFAULT '',
  new_severity VARCHAR(16) NOT NULL DEFAULT '',
  previous_ends_at VARCHAR(40) NOT NULL DEFAULT '',
  new_ends_at VARCHAR(40) NOT NULL DEFAULT '',
  provider_alert_id VARCHAR(255) NOT NULL DEFAULT '',
  payload_json MEDIUMTEXT NOT NULL,
  observed_at VARCHAR(40) NOT NULL,
  KEY idx_official_alert_revisions_location(location_key,id),
  KEY idx_official_alert_revisions_time(observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lightning_observation_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  location_key VARCHAR(96) NOT NULL,
  observed_at VARCHAR(40) NOT NULL,
  source VARCHAR(80) NOT NULL,
  count_30m INT NOT NULL DEFAULT 0,
  nearest_km DOUBLE NULL,
  approaching TINYINT(1) NOT NULL DEFAULT 0,
  payload_json MEDIUMTEXT NOT NULL,
  created_at VARCHAR(40) NOT NULL,
  KEY idx_lightning_snapshots_location(device_id,location_key,id),
  KEY idx_lightning_snapshots_time(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS radar_frame_quality (
  frame_id BIGINT UNSIGNED PRIMARY KEY,
  quality_score INT NOT NULL DEFAULT 0,
  signal_coverage DOUBLE NOT NULL DEFAULT 0,
  fetch_latency_ms INT NOT NULL DEFAULT 0,
  provider_frame_age_seconds INT NOT NULL DEFAULT 0,
  checked_at VARCHAR(40) NOT NULL,
  KEY idx_radar_quality_checked(checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('schema_version','28',UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at);
INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('app_version','20.1',UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at);
INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_seed_version','20.1-semantic-i18n-v2',UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at);
INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('translation_revision','1',UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE meta_value=meta_value;

-- MeteoNexa schema — server-side Smart Alert event ledger retained by the current server architecture.
CREATE TABLE IF NOT EXISTS weather_alert_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(191) NOT NULL,
  event_key VARCHAR(191) NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  severity VARCHAR(16) NOT NULL,
  confidence INT NOT NULL DEFAULT 0,
  location_name VARCHAR(255) NOT NULL DEFAULT '',
  starts_at VARCHAR(40) NOT NULL DEFAULT '',
  ends_at VARCHAR(40) NOT NULL DEFAULT '',
  payload_json MEDIUMTEXT NOT NULL,
  delivered_at VARCHAR(40) NOT NULL DEFAULT '',
  created_at VARCHAR(40) NOT NULL,
  updated_at VARCHAR(40) NOT NULL,
  UNIQUE KEY uq_weather_alert_event(device_id,event_key),
  KEY idx_weather_alert_events_device(device_id,created_at),
  KEY idx_weather_alert_events_cleanup(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ui_visibility(feature_key,guest_visible,authenticated_visible,updated_at) VALUES
('feature.smart.demo',1,1,UTC_TIMESTAMP()),
('feature.smart.alerts',0,1,UTC_TIMESTAMP()),
('feature.official.alerts',1,1,UTC_TIMESTAMP()),
('feature.hyperlocal',0,1,UTC_TIMESTAMP()),
('feature.intelligence.consensus',1,1,UTC_TIMESTAMP()),
('feature.intelligence.skill',0,1,UTC_TIMESTAMP()),
('feature.intelligence.change',0,1,UTC_TIMESTAMP()),
('feature.intelligence.explainability',1,1,UTC_TIMESTAMP()),
('feature.intelligence.celltracking',0,1,UTC_TIMESTAMP()),
('feature.intelligence.decision',1,1,UTC_TIMESTAMP()),
('feature.intelligence.locations',0,1,UTC_TIMESTAMP()),
('page.feedback',1,1,UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE guest_visible=VALUES(guest_visible),authenticated_visible=VALUES(authenticated_visible),updated_at=VALUES(updated_at);

