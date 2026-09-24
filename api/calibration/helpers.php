<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/intelligence/quality_helpers.php';
require_once dirname(__DIR__) . '/intelligence/reliability_helpers.php';
require_once dirname(__DIR__) . '/intelligence/intelligence_extensions.php';
require_once dirname(__DIR__) . '/observations/providers.php';
function meteonexa_calibration_locations(PDO $pdo, int $limit) : array {
    $items =[];
    $put = function($r)use(&$items) {
        $device = trim((string)($r['device_id']??''));
        if ($device===''||!is_numeric($r['latitude']??null)||!is_numeric($r['longitude']??null))return;
        $lat = (float)$r['latitude'];
        $lon = (float)$r['longitude'];
        $key = $device . '|' . meteonexa_intelligence_location_key($lat, $lon);
        $items[$key] =['deviceId'=>$device, 'latitude'=>$lat, 'longitude'=>$lon, 'locationKey'=>meteonexa_intelligence_location_key($lat, $lon)];
    };
    foreach (['saved_locations'=>'SELECT device_id,latitude,longitude FROM saved_locations WHERE active=1 ORDER BY updated_at DESC', 'push_subscriptions'=>'SELECT device_id,latitude,longitude FROM push_subscriptions WHERE active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY updated_at DESC', 'radar_archive_locations'=>'SELECT device_id,latitude,longitude FROM radar_archive_locations WHERE active=1 ORDER BY updated_at DESC'] as $table=>$sql) {
        if (count($items)>=$limit)break;
        if (meteonexa_db_table_exists($pdo, $table)) foreach ($pdo->query($sql . ' LIMIT ' . max(1, $limit * 2))->fetchAll() as $r)$put($r);
    }
    return array_slice(array_values($items), 0, $limit);
}
function meteonexa_calibration_verify(PDO $pdo, array $loc, array $obs) : int {
    $obsTs = meteonexa_intel_time_utc($obs['observedAt']??'')??time();
    $target = gmdate('Y-m-d\TH:00:00\Z', (int)(round($obsTs / 3600) * 3600));
    $st = $pdo->prepare("SELECT id,metric,predicted_value FROM model_skill_samples WHERE device_id=:d AND location_key=:l AND target_time=:t AND verified_at='' LIMIT 250");
    $st->execute([':d'=>$loc['deviceId'], ':l'=>$loc['locationKey'], ':t'=>$target]);
    $up = $pdo->prepare("UPDATE model_skill_samples SET observed_value=:o,error_value=:e,brier_score=:b,verified_at=:v WHERE id=:id AND verified_at=''");
    $done = 0;
    foreach ($st->fetchAll() as $r) {
        $m = (string)$r['metric'];
        $o = $obs[$m]??null;
        if (!is_numeric($o)||!is_numeric($r['predicted_value']??null))continue;
        $pred = (float)$r['predicted_value'];
        $observed = (float)$o;
        $binary = in_array($m,['rain', 'storm', 'snow'], true);
        $up->execute([':o'=>$observed, ':e'=>$binary ? null : abs($pred - $observed), ':b'=>$binary ? meteonexa_intelq_brier($pred, $observed) : null, ':v'=>gmdate('c'), ':id'=>(int)$r['id']]);
        $done+=$up->rowCount() ? 1 : 0;
    }
    return $done;
}
function meteonexa_calibration_queue(PDO $pdo, array $loc, array $models, array $consensus) : int {
    $horizons =[1, 3, 6, 24, 48, 72];
    $now = (int)(round(time() / 3600) * 3600);
    $ins = $pdo->prepare("INSERT OR IGNORE INTO model_skill_samples(device_id,location_key,model_name,metric,horizon_hours,target_time,predicted_value,observed_value,error_value,brier_score,issued_at,verified_at,created_at) VALUES(:d,:l,:model,:metric,:h,:target,:pred,NULL,NULL,NULL,:issued,'',:created)");
    $count = 0;
    foreach ($horizons as $h) {
        $target = $now + $h * 3600;
        $iso = gmdate('Y-m-d\TH:00:00\Z', $target);
        foreach ($models as $id=>$model) {
            if (empty($model['available']))continue;
            $row = meteonexa_intelq_model_at_target($model, $target);
            if (!$row)continue;
            $vals =['temperature'=>$row['temperature']??null, 'wind'=>$row['windGust']??null, 'rain'=>meteonexa_intelq_is_rain_row($row) ? 1 : 0, 'storm'=>meteonexa_intelq_is_storm_row($row) ? 1 : 0, 'snow'=>meteonexa_intelq_is_snow_row($row) ? 1 : 0];
            foreach ($vals as $metric=>$value) {
                if (!is_numeric($value))continue;
                $ins->execute([':d'=>$loc['deviceId'], ':l'=>$loc['locationKey'], ':model'=>$id, ':metric'=>$metric, ':h'=>$h, ':target'=>$iso, ':pred'=>$value, ':issued'=>gmdate('c'), ':created'=>gmdate('c')]);
                $count+=$ins->rowCount() ? 1 : 0;
            }
        }
        $bucket = null;
        foreach ((array)($consensus['hourly']??[]) as $r) {
            $ts = meteonexa_intel_time_utc($r['time']??'');
            if ($ts!==null&&abs($ts - $target)<=2700) {
                $bucket = $r;
                break;
            }
        }
        if ($bucket) foreach (['rain', 'storm', 'snow'] as $metric) {
            $available = max(1, (int)$bucket['available']);
            $prob = is_numeric($bucket[$metric . 'WeightedPct']??null) ?((float)$bucket[$metric . 'WeightedPct'] / 100) :((int)$bucket[$metric . 'Votes'] / $available);
            $ins->execute([':d'=>$loc['deviceId'], ':l'=>$loc['locationKey'], ':model'=>'consensus', ':metric'=>$metric, ':h'=>$h, ':target'=>$iso, ':pred'=>$prob, ':issued'=>gmdate('c'), ':created'=>gmdate('c')]);
            $count+=$ins->rowCount() ? 1 : 0;
        }
    }
    return $count;
}
function meteonexa_calibration_process_location(PDO $pdo, array $config, array $loc) : array {
    $obs = meteonexa_observations_collect($pdo, $config, $loc['deviceId'], $loc['latitude'], $loc['longitude'], true);
    $models = meteonexa_intelq_fetch_models($loc['latitude'], $loc['longitude'], false, 72);
    $skillV2 = meteonexa_recency_skill($pdo, $loc['deviceId'], $loc['locationKey']);
    $championWeights = (array)($skillV2['weights']??[]);
    $weightTournament = meteonexa_reliability_weight_tournament($pdo, $loc['deviceId'], $loc['locationKey'], $championWeights);
    $weights = (array)($weightTournament['activeWeights']??$championWeights);
    $consensus = meteonexa_weighted_consensus($models, $weights, meteonexa_reliability_tournament_weighting_meta($weightTournament));
    $verified = 0;
    $queued = 0;
    if (!$pdo->inTransaction())$pdo->beginTransaction();
    try {
        if (!empty($obs['available'])&&!empty($obs['independentFromNwp']))$verified = meteonexa_calibration_verify($pdo, $loc, $obs);
        $queued = meteonexa_calibration_queue($pdo, $loc, $models, $consensus);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return['verified'=>$verified, 'queued'=>$queued, 'observations'=>$obs, 'models'=>$models, 'consensus'=>$consensus];
}
/**
 * opportunistic calibration touch.
 * Reuses models/consensus/observations already loaded by the authenticated
 * Intelligence request: no provider call is performed here.
 */
function meteonexa_calibration_touch_current(PDO $pdo, array $loc, array $observations, array $models, array $consensus) : array {
    if (!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false, 'verified'=>0, 'queued'=>0, 'independentObservationAvailable'=>false, 'reason'=>'skill_table_unavailable'];
    $independent = !empty($observations['available'])&&!empty($observations['independentFromNwp']);
    $verified = 0;
    $queued = 0;
    $started = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        if ($independent)$verified = meteonexa_calibration_verify($pdo, $loc, $observations);
        $queued = meteonexa_calibration_queue($pdo, $loc, $models, $consensus);
        if ($started&&$pdo->inTransaction())$pdo->commit();
        return['available'=>true, 'verified'=>$verified, 'queued'=>$queued, 'independentObservationAvailable'=>$independent, 'observationSourceCount'=>(int)($observations['sourceCount']??0), 'method'=>'in-request-existing-evidence'];
    } catch (Throwable $error) {
        if ($started&&$pdo->inTransaction())$pdo->rollBack();
        return['available'=>false, 'verified'=>0, 'queued'=>0, 'independentObservationAvailable'=>$independent, 'reason'=>'touch_failed'];
    }
}
