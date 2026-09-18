<?php
declare(strict_types=1);

function meteonexa_hmac_identifier(string $value, string $secret): string
{
    return hash_hmac('sha256', $value, $secret);
}

/**
 * Atomic fixed-window rate limiter. Returns retryAfter=0 when allowed.
 */
function meteonexa_rate_limit(PDO $pdo, string $scope, string $identifier, string $secret, int $limit, int $windowSeconds): array
{
    $limit = max(1, $limit);
    $windowSeconds = max(30, $windowSeconds);
    $now = time();
    $keyHash = meteonexa_hmac_identifier($identifier, $secret);
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $statement = $pdo->prepare('SELECT window_start, request_count FROM rate_limits WHERE scope=:scope AND key_hash=:key');
        $statement->execute([':scope'=>$scope, ':key'=>$keyHash]);
        $row = $statement->fetch();
        $windowStart = is_array($row) ? (int)$row['window_start'] : $now;
        $count = is_array($row) ? (int)$row['request_count'] : 0;
        if ($windowStart <= $now - $windowSeconds) {
            $windowStart = $now;
            $count = 0;
        }
        if ($count >= $limit) {
            $pdo->exec('COMMIT');
            return ['allowed'=>false, 'retryAfter'=>max(1, $windowSeconds - ($now - $windowStart)), 'remaining'=>0];
        }
        $count++;
        $upsert = $pdo->prepare('INSERT INTO rate_limits(scope,key_hash,window_start,request_count,updated_at) VALUES(:scope,:key,:start,:count,:updated) ON CONFLICT(scope,key_hash) DO UPDATE SET window_start=excluded.window_start,request_count=excluded.request_count,updated_at=excluded.updated_at');
        $upsert->execute([':scope'=>$scope, ':key'=>$keyHash, ':start'=>$windowStart, ':count'=>$count, ':updated'=>gmdate('c')]);
        $pdo->exec('COMMIT');
        // Bound the limiter table even on installations where the OTP flow is
        // rarely used. Cleanup is intentionally sampled to avoid extra writes
        // on every request.
        if (random_int(1, 100) === 1) {
            try {
                $cleanup = $pdo->prepare('DELETE FROM rate_limits WHERE updated_at < :cutoff');
                $cleanup->execute([':cutoff'=>gmdate('c', $now-172800)]);
            } catch (Throwable $ignored) { }
        }
        return ['allowed'=>true, 'retryAfter'=>0, 'remaining'=>max(0, $limit-$count)];
    } catch (Throwable $error) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $rollbackError) { /* transaction may already be closed */ }
        throw $error;
    }
}

/**
 * Global row quota for append-heavy/runtime tables. Table/order identifiers
 * come exclusively from this internal whitelist; user input is never used in SQL.
 */
function meteonexa_prune_rows_to_limit(PDO $pdo, string $table, int $limit): void
{
    $orderColumns = [
        'forecast_snapshots' => 'id',
        'synoptic_snapshots' => 'id',
        'push_notifications' => 'id',
        'weather_alert_events' => 'id',
        'app_preferences' => 'updated_at',
        'oauth_states' => 'created_at',
    ];
    if (!isset($orderColumns[$table])) throw new InvalidArgumentException('STORAGE_TABLE_INVALID');
    $limit = max(100, min(500000, $limit));
    $order = $orderColumns[$table];
    if (meteonexa_pdo_driver($pdo) === 'mysql') {
        // Delete rows older than the newest N. Every whitelisted table has a
        // stable key suitable for a derived-table delete on MySQL/MariaDB.
        $keyColumns = ['forecast_snapshots'=>'id','synoptic_snapshots'=>'id','push_notifications'=>'id','weather_alert_events'=>'id','app_preferences'=>'client_id','oauth_states'=>'state_hash'];
        $key = $keyColumns[$table];
        $statement = $pdo->prepare("DELETE FROM {$table} WHERE {$key} IN (SELECT {$key} FROM (SELECT {$key} FROM {$table} ORDER BY {$order} DESC LIMIT 18446744073709551615 OFFSET :limit) AS prune_rows)");
    } else {
        $statement = $pdo->prepare("DELETE FROM {$table} WHERE rowid IN (SELECT rowid FROM {$table} ORDER BY {$order} DESC LIMIT -1 OFFSET :limit)");
    }
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();
}

function meteonexa_prune_security_state(PDO $pdo): void
{
    $pdo->prepare('DELETE FROM auth_otp WHERE expires_at < :cutoff')->execute([':cutoff'=>time()-3600]);
    if (meteonexa_db_table_exists($pdo, 'auth_login_challenges')) $pdo->prepare('DELETE FROM auth_login_challenges WHERE expires_at < :cutoff')->execute([':cutoff'=>time()-3600]);
    $pdo->prepare('DELETE FROM rate_limits WHERE updated_at < :cutoff')->execute([':cutoff'=>gmdate('c', time()-172800)]);
    $now = time();
    $pdo->prepare('DELETE FROM oauth_states WHERE expires_at < :now')->execute([':now'=>$now]);
    if (meteonexa_db_table_exists($pdo, 'auth_access_history')) {
        $pdo->prepare("UPDATE auth_access_history SET ended_at=:now,end_reason='expired' WHERE ended_at=0 AND session_hash IN (SELECT session_hash FROM auth_sessions WHERE expires_at < :now)")
            ->execute([':now'=>$now]);
        // Access history is security audit data, not permanent profiling data.
        $pdo->prepare('DELETE FROM auth_access_history WHERE last_seen_at < :history_cutoff')
            ->execute([':history_cutoff'=>$now-(30*86400)]);
    }
    $pdo->prepare('DELETE FROM auth_sessions WHERE expires_at < :now')->execute([':now'=>$now]);
    $pdo->prepare('DELETE FROM trusted_devices WHERE expires_at < :now')->execute([':now'=>$now]);
}
