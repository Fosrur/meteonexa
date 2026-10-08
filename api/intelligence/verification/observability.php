<?php
declare(strict_types=1);
function meteonexa_trust_scoreboard(PDO $pdo, ? string $deviceId = null, ? string $locationKey = null) : array {
    $where =[];
    $params =[];
    if ($deviceId) {
        $where[] = 'device_id=:d';
        $params[':d'] = $deviceId;
    }
    if ($locationKey) {
        $where[] = 'location_key=:l';
        $params[':l'] = $locationKey;
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $out =['version'=>'2.0', 'generatedAt'=>gmdate('c'), 'temperatureMae'=>null, 'precipitationBrier'=>null, 'stormBrier'=>null, 'radarEtaMaeMinutes'=>null, 'radarEtaWithinTolerancePct'=>null, 'predictiveAlertFalsePositivePct'=>null, 'radarAlgorithms'=>[], 'alertVerification'=>[], 'samples'=>[], 'period'=>['first'=>null, 'last'=>null]];
    try {
        if (meteonexa_db_table_exists($pdo, 'model_skill_samples')) {
            $st = $pdo->prepare("SELECT metric,COUNT(*) n,AVG(error_value) mae,AVG(brier_score) brier,MIN(verified_at) first_at,MAX(verified_at) last_at FROM model_skill_samples{$w}" .($w ? ' AND ' : ' WHERE ') . "verified_at<>'' GROUP BY metric");
            $st->execute($params);
            foreach ($st->fetchAll() as $r) {
                $m = (string)$r['metric'];
                $out['samples'][$m] = (int)$r['n'];
                if ($m==='temperature')$out['temperatureMae'] = is_numeric($r['mae']) ? round((float)$r['mae'], 2) : null;
                if ($m==='rain')$out['precipitationBrier'] = is_numeric($r['brier']) ? round((float)$r['brier'], 3) : null;
                if ($m==='storm')$out['stormBrier'] = is_numeric($r['brier']) ? round((float)$r['brier'], 3) : null;
                $out['period']['first'] = $out['period']['first']===null ? $r['first_at'] : min((string)$out['period']['first'], (string)$r['first_at']);
                $out['period']['last'] = $out['period']['last']===null ? $r['last_at'] : max((string)$out['period']['last'], (string)$r['last_at']);
            }
        }
        if (meteonexa_db_table_exists($pdo, 'radar_eta_predictions')) {
            $algorithmColumn = meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm');
            $st = $pdo->prepare("SELECT " .($algorithmColumn ? 'algorithm,' : '') . "absolute_error_minutes,tolerance_minutes,verified_at FROM radar_eta_predictions{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified' AND absolute_error_minutes IS NOT NULL ORDER BY id ASC");
            $st->execute($params);
            $groups =[];
            foreach ($st->fetchAll() as $r) {
                $alg = $algorithmColumn ? (string)($r['algorithm'] ? : 'radar-v2') : 'radar-v2';
                $groups[$alg][] = $r;
            }
            foreach ($groups as $alg=>$rows) {
                $errors = array_map(static fn($r)=>(float)$r['absolute_error_minutes'], $rows);
                sort($errors);
                $n = count($errors);
                $ok = count(array_filter($rows, static fn($r)=>(float)$r['absolute_error_minutes']<=(float)$r['tolerance_minutes']));
                $out['radarAlgorithms'][$alg] =['samples'=>$n, 'maeMinutes'=>round(array_sum($errors) / $n, 1), 'medianMinutes'=>round($errors[(int)floor(($n - 1) / 2)], 1), 'p90Minutes'=>round($errors[(int)floor(($n - 1) * .9)], 1), 'withinTolerancePct'=>round(100 * $ok / $n, 1)];
            }
            $primary = $out['radarAlgorithms']['radar-v2']??reset($out['radarAlgorithms']) ? : null;
            if ($primary) {
                $out['samples']['radarEta'] = $primary['samples'];
                $out['radarEtaMaeMinutes'] = $primary['maeMinutes'];
                $out['radarEtaWithinTolerancePct'] = $primary['withinTolerancePct'];
            }
        }
        if (meteonexa_db_table_exists($pdo, 'predictive_alert_opportunities')) {
            $st = $pdo->prepare("SELECT event_type,outcome,confidence,observed,verified_at FROM predictive_alert_opportunities{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified'");
            $st->execute($params);
            $by =[];
            foreach ($st->fetchAll() as $r)$by[(string)$r['event_type']][] = $r;
            foreach ($by as $type=>$rows) {
                $out['alertVerification'][$type] = meteonexa_binary_metrics($rows);
                $out['samples']['alert_' . $type] = count($rows);
            }
            if (isset($out['alertVerification']['storm'])) {
                $out['predictiveAlertFalsePositivePct'] = $out['alertVerification']['storm']['falseAlarmRatioPct'];
            }
        } elseif (meteonexa_db_table_exists($pdo, 'predictive_alert_verifications')) {
            $st = $pdo->prepare("SELECT COUNT(*) n,SUM(CASE WHEN outcome='false_positive' THEN 1 ELSE 0 END) fp FROM predictive_alert_verifications{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified'");
            $st->execute($params);
            $r = $st->fetch() ? :[];
            $n = (int)($r['n']??0);
            $out['samples']['predictiveAlerts'] = $n;
            if ($n)$out['predictiveAlertFalsePositivePct'] = round(100 * (int)$r['fp'] / $n, 1);
        }
        if (meteonexa_db_table_exists($pdo, 'decision_verification_samples')) {
            $st = $pdo->prepare("SELECT kind,COUNT(*) n FROM decision_verification_samples{$w}" .($w ? ' AND ' : ' WHERE ') . "status='verified' GROUP BY kind");
            $st->execute($params);
            foreach ($st->fetchAll() as $r)$out['samples']['decision_' . (string)$r['kind']] = (int)$r['n'];
        }
    } catch (Throwable $ignored) {
    }
    $out['observability'] = meteonexa_observability_summary($pdo);
    $out['learning'] = array_sum(array_map('intval', $out['samples'])) < 50;
    return $out;
}
function meteonexa_prune_verified_precision(PDO $pdo, array $config) : array {
    $metaKey = 'verified_precision_last_prune';
    $now = time();
    try {
        $last = (string)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='" . $metaKey . "'),'')")->fetchColumn() ? : '');
        $lastTs = meteonexa_verification_ts($last);
        if ($lastTs!==null&&$now - $lastTs < 21600)return['skipped'=>true];
    } catch (Throwable $ignored) {
    }
    $retention = (int)($config['verification']['retention_days']??180);
    $runtimeDays = (int)($config['verification']['runtime_metrics_retention_days']??30);
    $limits = (array)($config['storage_limits']??[]);
    $deleted =[];
    $tableDays =['radar_eta_predictions'=>$retention, 'radar4_event_predictions'=>$retention, 'decision_verification_samples'=>$retention, 'predictive_alert_verifications'=>$retention, 'predictive_alert_opportunities'=>$retention, 'runtime_metrics'=>$runtimeDays];
    foreach ($tableDays as $table=>$days) {
        if (!meteonexa_db_table_exists($pdo, $table))continue;
        try {
            $st = $pdo->prepare("DELETE FROM {$table} WHERE created_at<:cutoff");
            $st->execute([':cutoff'=>gmdate('c', $now - max(1, $days) * 86400)]);
            $deleted[$table] = $st->rowCount();
        } catch (Throwable $ignored) {
        }
        $limit = (int)($limits[$table]??0);
        if ($limit > 0&&function_exists('meteonexa_prune_rows_to_limit')) try {
            meteonexa_prune_rows_to_limit($pdo, $table, $limit);
        } catch (Throwable $ignored) {
        }
    }
    try {
        $sql = meteonexa_pdo_driver($pdo)==='mysql' ? "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)" : "INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:k,:v,:u) ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value,updated_at=excluded.updated_at";
        $pdo->prepare($sql)->execute([':k'=>$metaKey, ':v'=>gmdate('c', $now), ':u'=>gmdate('c', $now)]);
    } catch (Throwable $ignored) {
    }
    return['skipped'=>false, 'deleted'=>$deleted];
}
function meteonexa_observability_event(string $category, string $name, string $status, array $meta =[]) : void {
    try {
        $dir = meteonexa_storage_path();
        if (!is_dir($dir))@mkdir($dir, 0770, true);
        $path = $dir . '/runtime-observability.ndjson';
        $trace = function_exists('meteonexa_request_id') ? meteonexa_request_id() : '';
        $safe =[];
        foreach ($meta as $k=>$v) {
            if (preg_match('/email|token|secret|password|lat|lon|coordinate|message/i', (string)$k))continue;
            if (is_scalar($v)||$v===null)$safe[substr((string)$k, 0, 48)] = $v;
        }
        $row =['at'=>gmdate('c'), 'traceId'=>$trace, 'category'=>substr($category, 0, 40), 'name'=>substr($name, 0, 80), 'status'=>substr($status, 0, 24), 'meta'=>$safe];
        @file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        if (is_file($path)&&filesize($path) > 2_000_000) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines))@file_put_contents($path, implode("\n", array_slice($lines, - 2500)) . "\n", LOCK_EX);
        }
    } catch (Throwable $ignored) {
    }
}
function meteonexa_observability_summary( ? PDO $pdo = null) : array {
    $path = meteonexa_storage_path() . '/runtime-observability.ndjson';
    $status =[];
    $names =[];
    $events = 0;
    if (is_file($path)) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach (array_slice($lines, - 500) as $line) {
                $r = json_decode($line, true);
                if (!is_array($r))continue;
                $events++;
                $s = (string)($r['status']??'unknown');
                $n = (string)($r['category']??'') . '/' . (string)($r['name']??'');
                $status[$s] =($status[$s]??0) + 1;
                $names[$n] =($names[$n]??0) + 1;
            }
        }
    }
    arsort($names);
    $out =['events'=>$events, 'byStatus'=>$status, 'byName'=>array_slice($names, 0, 12, true)];
    if ($pdo&&meteonexa_db_table_exists($pdo, 'runtime_metrics')) try {
        $st = $pdo->query("SELECT category,metric_name,status,COUNT(*) n,AVG(duration_ms) avg_ms FROM runtime_metrics WHERE created_at>='" . gmdate('c', time() - 86400) . "' GROUP BY category,metric_name,status ORDER BY n DESC LIMIT 20");
        $out['last24h'] = $st->fetchAll();
    } catch (Throwable $ignored) {
    }
    return $out;
}
