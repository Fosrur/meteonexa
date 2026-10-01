<?php
declare(strict_types=1);
return [
 'version'=>34,'name'=>'personal-weather-twin-v2','drivers'=>['mysql','sqlite'],
 'up'=>static function(PDO $pdo,int $schemaVersion,string $driver):int{
   if($schemaVersion>=34)return $schemaVersion;
   if($driver==='mysql'){
     $pdo->exec("CREATE TABLE IF NOT EXISTS personal_station_samples (
       id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
       device_id VARCHAR(191) NOT NULL,location_key VARCHAR(96) NOT NULL,station_ref VARCHAR(64) NOT NULL,
       observed_at VARCHAR(40) NOT NULL,quality_score INT NOT NULL DEFAULT 0,outlier TINYINT(1) NOT NULL DEFAULT 0,
       temperature DOUBLE NULL,humidity DOUBLE NULL,pressure DOUBLE NULL,rain DOUBLE NULL,wind DOUBLE NULL,gust DOUBLE NULL,
       correction_json MEDIUMTEXT NOT NULL,expires_at VARCHAR(40) NOT NULL,created_at VARCHAR(40) NOT NULL,
       UNIQUE KEY uq_personal_station_sample(device_id,location_key,station_ref,observed_at),
       KEY idx_personal_station_retention(expires_at),KEY idx_personal_station_location(device_id,location_key,id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
     $pdo->exec("CREATE TABLE IF NOT EXISTS material_decision_state (
       device_id VARCHAR(191) NOT NULL,location_key VARCHAR(96) NOT NULL,activity VARCHAR(48) NOT NULL,
       decision_hash VARCHAR(64) NOT NULL,status VARCHAR(24) NOT NULL,score INT NOT NULL DEFAULT 0,confidence INT NOT NULL DEFAULT 0,
       starts_at VARCHAR(40) NOT NULL DEFAULT '',ends_at VARCHAR(40) NOT NULL DEFAULT '',changed_at VARCHAR(40) NOT NULL,
       notified_at VARCHAR(40) NOT NULL DEFAULT '',updated_at VARCHAR(40) NOT NULL,
       PRIMARY KEY(device_id,location_key,activity),KEY idx_material_decision_change(changed_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
   }else{
     $pdo->exec("CREATE TABLE IF NOT EXISTS personal_station_samples (id INTEGER PRIMARY KEY AUTOINCREMENT,device_id TEXT NOT NULL,location_key TEXT NOT NULL,station_ref TEXT NOT NULL,observed_at TEXT NOT NULL,quality_score INTEGER NOT NULL DEFAULT 0,outlier INTEGER NOT NULL DEFAULT 0,temperature REAL NULL,humidity REAL NULL,pressure REAL NULL,rain REAL NULL,wind REAL NULL,gust REAL NULL,correction_json TEXT NOT NULL,expires_at TEXT NOT NULL,created_at TEXT NOT NULL,UNIQUE(device_id,location_key,station_ref,observed_at))");
     $pdo->exec('CREATE INDEX IF NOT EXISTS idx_personal_station_retention ON personal_station_samples(expires_at)');
     $pdo->exec('CREATE INDEX IF NOT EXISTS idx_personal_station_location ON personal_station_samples(device_id,location_key,id)');
     $pdo->exec("CREATE TABLE IF NOT EXISTS material_decision_state (device_id TEXT NOT NULL,location_key TEXT NOT NULL,activity TEXT NOT NULL,decision_hash TEXT NOT NULL,status TEXT NOT NULL,score INTEGER NOT NULL DEFAULT 0,confidence INTEGER NOT NULL DEFAULT 0,starts_at TEXT NOT NULL DEFAULT '',ends_at TEXT NOT NULL DEFAULT '',changed_at TEXT NOT NULL,notified_at TEXT NOT NULL DEFAULT '',updated_at TEXT NOT NULL,PRIMARY KEY(device_id,location_key,activity))");
     $pdo->exec('CREATE INDEX IF NOT EXISTS idx_material_decision_change ON material_decision_state(changed_at)');
   }
   meteonexa_write_schema_version($pdo,34);return 34;
 }
];
