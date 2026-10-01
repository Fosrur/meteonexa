<?php
declare(strict_types=1);

return [
    'version' => 33,
    'name' => 'ai-meteorologist-v2',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 33) return $schemaVersion;
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_semantic_cache (
                cache_key VARCHAR(64) PRIMARY KEY,
                language VARCHAR(8) NOT NULL,
                mode VARCHAR(24) NOT NULL,
                decision_id VARCHAR(64) NOT NULL,
                context_hash VARCHAR(64) NOT NULL,
                answer MEDIUMTEXT NOT NULL,
                sources_json MEDIUMTEXT NOT NULL,
                confidence INT NOT NULL DEFAULT 0,
                limitations_json MEDIUMTEXT NOT NULL,
                expires_at VARCHAR(40) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                last_hit_at VARCHAR(40) NOT NULL DEFAULT '',
                hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                KEY idx_ai_semantic_cache_expiry(expires_at),
                KEY idx_ai_semantic_cache_decision(decision_id,language,mode)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_semantic_cache (
                cache_key TEXT PRIMARY KEY,
                language TEXT NOT NULL,
                mode TEXT NOT NULL,
                decision_id TEXT NOT NULL,
                context_hash TEXT NOT NULL,
                answer TEXT NOT NULL,
                sources_json TEXT NOT NULL,
                confidence INTEGER NOT NULL DEFAULT 0,
                limitations_json TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_hit_at TEXT NOT NULL DEFAULT '',
                hit_count INTEGER NOT NULL DEFAULT 0
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_semantic_cache_expiry ON ai_semantic_cache(expires_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ai_semantic_cache_decision ON ai_semantic_cache(decision_id,language,mode)');
        }
        meteonexa_write_schema_version($pdo, 33);
        return 33;
    },
];
