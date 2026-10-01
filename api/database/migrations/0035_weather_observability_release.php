<?php
declare(strict_types=1);
return [
 'version'=>35,'name'=>'weather-observability-release','drivers'=>['mysql','sqlite'],
 'up'=>static function(PDO $pdo,int $schemaVersion,string $driver):int{
  if($schemaVersion>=35)return $schemaVersion;
  if($driver==='mysql'){
   $pdo->exec("CREATE TABLE IF NOT EXISTS release_canary_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,release_sha VARCHAR(64) NOT NULL,phase VARCHAR(24) NOT NULL,
    window_start VARCHAR(40) NOT NULL,window_end VARCHAR(40) NOT NULL,sample_count INT NOT NULL DEFAULT 0,
    mae DOUBLE NULL,brier DOUBLE NULL,metrics_json MEDIUMTEXT NOT NULL,created_at VARCHAR(40) NOT NULL,
    KEY idx_release_canary_sha(release_sha,phase,id),KEY idx_release_canary_window(window_end)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }else{
   $pdo->exec("CREATE TABLE IF NOT EXISTS release_canary_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,release_sha TEXT NOT NULL,phase TEXT NOT NULL,window_start TEXT NOT NULL,window_end TEXT NOT NULL,sample_count INTEGER NOT NULL DEFAULT 0,mae REAL NULL,brier REAL NULL,metrics_json TEXT NOT NULL,created_at TEXT NOT NULL)");
   $pdo->exec('CREATE INDEX IF NOT EXISTS idx_release_canary_sha ON release_canary_snapshots(release_sha,phase,id)');
   $pdo->exec('CREATE INDEX IF NOT EXISTS idx_release_canary_window ON release_canary_snapshots(window_end)');
  }
  meteonexa_write_schema_version($pdo,35);return 35;
 }
];
