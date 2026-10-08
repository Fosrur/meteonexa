<?php
declare(strict_types=1);
function meteonexa_intelq_cell_tracking(PDO $pdo, string $deviceId, float $lat, float $lon, array $lightning =[]) : array {
    if (!function_exists('imagecreatefromstring'))return['available'=>false, 'reason'=>'gd'];
    try {
        $stmt = $pdo->prepare('SELECT id,latitude,longitude FROM radar_archive_locations WHERE device_id=:device AND active=1 ORDER BY updated_at DESC');
        $stmt->execute([':device'=>$deviceId]);
        $best = null;
        $distance = INF;
        foreach ($stmt->fetchAll() as $row) {
            $d = haversine_km($lat, $lon, (float)$row['latitude'], (float)$row['longitude']);
            if ($d < $distance) {
                $distance = $d;
                $best = $row;
            }
        }
        if (!$best||$distance > 80)return['available'=>false, 'reason'=>'no_archive'];
        $st = $pdo->prepare('SELECT frame_time,image_path FROM radar_archive_frames WHERE location_id=:id ORDER BY frame_time DESC LIMIT 6');
        $st->execute([':id'=>$best['id']]);
        $frames =[];
        foreach (array_reverse($st->fetchAll()) as $row) {
            $grid = meteonexa_radar_signal_grid((string)$row['image_path']);
            if (!$grid)continue;
            $components = meteonexa_intelq_radar_components($grid);
            if (!$components)continue;
            $frames[] =['time'=>(int)$row['frame_time'], 'grid'=>$grid, 'components'=>$components];
        }
        $tracked = meteonexa_intelq_track_cell_sequence($frames);
        if (count($tracked) < 2)return['available'=>false, 'reason'=>'insufficient_cells'];
        $first = $tracked[0];
        $latest = $tracked[count($tracked) - 1];
        $dt = max(60, $latest['time'] - $first['time']);
        $dx = $latest['cell']['cx'] - $first['cell']['cx'];
        $dy = $latest['cell']['cy'] - $first['cell']['cy'];
        $vx = $dx /($dt / 60);
        $vy = $dy /($dt / 60);
        $growth = 100 *($latest['cell']['mass'] - $first['cell']['mass']) / max(.01, $first['cell']['mass']);
        $stage = $growth > 20 ? 'growing' :($growth < - 20 ? 'decaying' : 'stable');
        $center =((int)$latest['grid']['size'] - 1) / 2;
        $distCells = sqrt(($center - $latest['cell']['cx'])**2 +($center - $latest['cell']['cy'])**2);
        $speed2 = $vx * $vx + $vy * $vy;
        $eta = null;
        $toward = false;
        if ($speed2 > .0001) {
            $tx = $center - $latest['cell']['cx'];
            $ty = $center - $latest['cell']['cy'];
            $dot = $tx * $vx + $ty * $vy;
            $toward = $dot > 0;
            if ($toward) {
                $eta = (int)round($dot / $speed2);
                if ($eta < 0||$eta > 180)$eta = null;
            }
        }
        $meanJump = (float)($latest['associationMeanJump']??0);
        $associationConfidence = (int)round(meteonexa_intel_clamp(100 - $meanJump * 8, 20, 100));
        $trackConfidence = (int)round(meteonexa_intel_clamp(35 + count($tracked) * 12 + $associationConfidence * .25, 25, 95));
        $impact = 20 +($toward ? 27 : 0) + max(0, 27 - $distCells * 1.6) + min(15, max( - 10, $growth / 4));
        $impact*=.7 + .3 *($trackConfidence / 100);
        if (!empty($lightning['available'])&&(int)($lightning['recent30m']??0) > 0)$impact+=10;
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $dir = $dirs[((int)round($angle / 45)) % 8];
        return['available'=>true, 'method'=>'cell-segmentation-associated-multi-frame', 'direction'=>$dir, 'growthPct'=>round($growth, 1), 'stage'=>$stage, 'impactProbability'=>(int)round(meteonexa_intel_clamp($impact, 5, 95)), 'etaMinutes'=>$eta, 'towardLocation'=>$toward, 'cellDistanceGrid'=>round($distCells, 1), 'speedCellsMin'=>round(sqrt($speed2), 4), 'mass'=>round((float)$latest['cell']['mass'], 2), 'peak'=>round((float)$latest['cell']['peak'], 3), 'frameCount'=>count($tracked), 'archiveFrameCount'=>count($frames), 'associationMeanJump'=>round($meanJump, 2), 'trackConfidence'=>$trackConfidence, 'archiveDistanceKm'=>round($distance, 1), 'lightningFused'=>!empty($lightning['available'])&&(int)($lightning['recent30m']??0) > 0, 'latestFrameAt'=>gmdate('c', $latest['time']), 'ageMinutes'=>max(0, (int)round((time() - $latest['time']) / 60))];
    } catch (Throwable $ignored) {
        return['available'=>false, 'reason'=>'analysis_failed'];
    }
}
function meteonexa_intelq_previous_runs(float $lat, float $lon, bool $demo = false) : array {
    if ($demo) {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
    }
    try {
        return meteonexa_intel_provider_cache('previous-runs', $lat, $lon, 1800, static function()use($lat, $lon) : array {
            $params = http_build_query(['latitude'=>$lat, 'longitude'=>$lon, 'hourly'=>'temperature_2m,temperature_2m_previous_day1,precipitation,precipitation_previous_day1,weather_code,weather_code_previous_day1,wind_gusts_10m,wind_gusts_10m_previous_day1', 'forecast_days'=>3, 'past_days'=>1, 'timezone'=>'GMT'], '', '&', PHP_QUERY_RFC3986); $raw = meteonexa_http_json('https://previous-runs-api.open-meteo.com/v1/forecast?' . $params,['timeout'=>20, 'max_bytes'=>1800000]); $h = (array)($raw['hourly']??[]); $times = (array)($h['time']??[]); $rows =[]; foreach ($times as $i=>$t) {
                $ts = meteonexa_intel_time_utc($t); if ($ts===null||$ts < time() - 3600)continue; $rows[] =['time'=>gmdate('c', $ts), 'temperatureNow'=>is_numeric($h['temperature_2m'][$i]??null) ? (float)$h['temperature_2m'][$i] : null, 'temperaturePrevious'=>is_numeric($h['temperature_2m_previous_day1'][$i]??null) ? (float)$h['temperature_2m_previous_day1'][$i] : null, 'rainNow'=>is_numeric($h['precipitation'][$i]??null) ? (float)$h['precipitation'][$i] : null, 'rainPrevious'=>is_numeric($h['precipitation_previous_day1'][$i]??null) ? (float)$h['precipitation_previous_day1'][$i] : null, 'codeNow'=>is_numeric($h['weather_code'][$i]??null) ? (int)$h['weather_code'][$i] : null, 'codePrevious'=>is_numeric($h['weather_code_previous_day1'][$i]??null) ? (int)$h['weather_code_previous_day1'][$i] : null, 'windNow'=>is_numeric($h['wind_gusts_10m'][$i]??null) ? (float)$h['wind_gusts_10m'][$i] : null, 'windPrevious'=>is_numeric($h['wind_gusts_10m_previous_day1'][$i]??null) ? (float)$h['wind_gusts_10m_previous_day1'][$i] : null]; if (count($rows)>=72)break;
            }
            $future = array_slice($rows, 0, 24); $summary =['available'=>false]; if ($future) {
                $rainNow = array_map(static fn($r)=>(float)($r['rainNow']??0), $future); $rainPrev = array_map(static fn($r)=>(float)($r['rainPrevious']??0), $future); $tempNow = array_values(array_filter(array_column($future, 'temperatureNow'), 'is_numeric')); $tempPrev = array_values(array_filter(array_column($future, 'temperaturePrevious'), 'is_numeric')); $windNow = array_values(array_filter(array_column($future, 'windNow'), 'is_numeric')); $windPrev = array_values(array_filter(array_column($future, 'windPrevious'), 'is_numeric')); $firstNow = null; $firstPrev = null; $stormsNow = 0; $stormsPrev = 0; foreach ($future as $r) {
                    if ($firstNow===null&&((float)($r['rainNow']??0)>=.2||in_array((int)($r['codeNow']??0),[51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99], true)))$firstNow = $r['time']; if ($firstPrev===null&&((float)($r['rainPrevious']??0)>=.2||in_array((int)($r['codePrevious']??0),[51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81, 82, 95, 96, 99], true)))$firstPrev = $r['time']; if (in_array((int)($r['codeNow']??0),[95, 96, 99], true))$stormsNow++; if (in_array((int)($r['codePrevious']??0),[95, 96, 99], true))$stormsPrev++;
                }
                $nowTs = meteonexa_intel_time_utc($firstNow??''); $prevTs = meteonexa_intel_time_utc($firstPrev??''); $summary =['available'=>true, 'windowHours'=>24, 'firstRainNow'=>$firstNow, 'firstRainPrevious'=>$firstPrev, 'timeShiftMinutes'=>($nowTs!==null&&$prevTs!==null) ? (int)round(($nowTs - $prevTs) / 60) : null, 'rainTotalNow'=>round(array_sum($rainNow), 1), 'rainTotalPrevious'=>round(array_sum($rainPrev), 1), 'rainTotalDelta'=>round(array_sum($rainNow) - array_sum($rainPrev), 1), 'stormHoursNow'=>$stormsNow, 'stormHoursPrevious'=>$stormsPrev, 'stormHoursDelta'=>$stormsNow - $stormsPrev, 'temperatureMaxNow'=>$tempNow ? round(max($tempNow), 1) : null, 'temperatureMaxPrevious'=>$tempPrev ? round(max($tempPrev), 1) : null, 'temperatureMaxDelta'=>($tempNow&&$tempPrev) ? round(max($tempNow) - max($tempPrev), 1) : null, 'windGustMaxNow'=>$windNow ? round(max($windNow), 1) : null, 'windGustMaxPrevious'=>$windPrev ? round(max($windPrev), 1) : null, 'windGustMaxDelta'=>($windNow&&$windPrev) ? round(max($windNow) - max($windPrev), 1) : null,]; $summary['changed'] =($summary['timeShiftMinutes']!==null&&abs((int)$summary['timeShiftMinutes'])>=60)||abs((float)$summary['rainTotalDelta'])>=2||abs((int)$summary['stormHoursDelta'])>=1||abs((float)($summary['temperatureMaxDelta']??0))>=2||abs((float)($summary['windGustMaxDelta']??0))>=8;
            }
            return['available'=>$rows!==[], 'source'=>'Open-Meteo Previous Runs', 'rows'=>$rows, 'summary'=>$summary, 'retrievedAt'=>gmdate('c')];
        });
    } catch (Throwable $ignored) {
        return['available'=>false, 'source'=>'Open-Meteo Previous Runs', 'rows'=>[], 'summary'=>['available'=>false]];
    }
}
function meteonexa_intelq_decision_windows(array $models, array $customProfiles =[]) : array {
    $consensus = meteonexa_intelq_consensus($models);
    $hourly = (array)($consensus['hourly']??[]);
    
    
    
    $profiles =['car'=>['rain'=>.28, 'storm'=>.65, 'wind'=>.12, 'bonus'=>8, 'tempMin'=> - 10, 'tempMax'=>40, 'tempWeight'=>1.0], 'motorcycle'=>['rain'=>.70, 'storm'=>1.05, 'wind'=>.52, 'bonus'=>0, 'tempMin'=>5, 'tempMax'=>34, 'tempWeight'=>3.0], 'bike'=>['rain'=>.66, 'storm'=>1.00, 'wind'=>.50, 'bonus'=>0, 'tempMin'=>4, 'tempMax'=>32, 'tempWeight'=>3.2], 'run'=>['rain'=>.50, 'storm'=>1.00, 'wind'=>.28, 'bonus'=>0, 'tempMin'=>2, 'tempMax'=>30, 'tempWeight'=>4.0], 'trekking'=>['rain'=>.48, 'storm'=>1.05, 'wind'=>.34, 'bonus'=>0, 'tempMin'=>0, 'tempMax'=>30, 'tempWeight'=>3.5], 'sea'=>['rain'=>.30, 'storm'=>1.20, 'wind'=>.72, 'bonus'=>0, 'tempMin'=>18, 'tempMax'=>36, 'tempWeight'=>7.0], 'outdoor_work'=>['rain'=>.52, 'storm'=>1.05, 'wind'=>.45, 'bonus'=>0, 'tempMin'=> - 5, 'tempMax'=>33, 'tempWeight'=>4.5], 'event'=>['rain'=>.62, 'storm'=>1.05, 'wind'=>.38, 'bonus'=>0, 'tempMin'=>5, 'tempMax'=>32, 'tempWeight'=>3.5], 'laundry'=>['rain'=>.92, 'storm'=>.60, 'wind'=>.12, 'bonus'=>0, 'tempMin'=>2, 'tempMax'=>42, 'tempWeight'=>1.0], 'kids'=>['rain'=>.58, 'storm'=>1.10, 'wind'=>.30, 'bonus'=>0, 'tempMin'=>5, 'tempMax'=>30, 'tempWeight'=>6.0], 'pets'=>['rain'=>.48, 'storm'=>1.00, 'wind'=>.30, 'bonus'=>0, 'tempMin'=>0, 'tempMax'=>31, 'tempWeight'=>4.0], 'commute'=>['rain'=>.34, 'storm'=>.78, 'wind'=>.18, 'bonus'=>5, 'tempMin'=> - 10, 'tempMax'=>40, 'tempWeight'=>1.3], 'worksite'=>['rain'=>.58, 'storm'=>1.10, 'wind'=>.48, 'bonus'=>0, 'tempMin'=> - 5, 'tempMax'=>34, 'tempWeight'=>4.8], 'ski'=>['rain'=>.36, 'storm'=>.90, 'wind'=>.58, 'bonus'=>0, 'tempMin'=> - 20, 'tempMax'=>5, 'tempWeight'=>6.0, 'snowRequired'=>true],];
    $out =[];
    foreach ($profiles as $activity=>$profile) {
        $best = null;
        for ($i = 0; $i + 2 < count($hourly)&&$i < 24; $i++) {
            $slice = array_slice($hourly, $i, 3);
            $penalty = 0.0;
            $reasons =['rain'=>0, 'storm'=>0, 'wind'=>0, 'temperature'=>null, 'snow'=>0];
            $temps =[];
            foreach ($slice as $r) {
                $available = max(1, (int)$r['available']);
                $rainPct = 100 * (int)$r['rainVotes'] / $available;
                $stormPct = 100 * (int)$r['stormVotes'] / $available;
                $snowPct = 100 * (int)($r['snowVotes']??0) / $available;
                $gust = (float)($r['windGustMax']??0);
                if (is_numeric($r['temperatureMean']??null))$temps[] = (float)$r['temperatureMean'];
                $reasons['rain'] = max($reasons['rain'], (int)round($rainPct));
                $reasons['storm'] = max($reasons['storm'], (int)round($stormPct));
                $reasons['wind'] = max($reasons['wind'], (int)round($gust));
                $reasons['snow'] = max($reasons['snow'], (int)round($snowPct));
                $customKey = $activity==='outdoor_work' ? 'outdoor' : $activity;
                $custom = is_array($customProfiles[$customKey]??null) ? $customProfiles[$customKey] : null;
                if ($custom) {
                    $penalty+=max(0, $rainPct - (float)($custom['rainMax']??35)) * 1.45 + $stormPct * (float)$profile['storm'] + max(0, $gust - (float)($custom['gustMax']??45)) * 2.2;
                } else {
                    $penalty+=$rainPct * (float)$profile['rain'] + $stormPct * (float)$profile['storm'] + max(0, $gust - 20) * (float)$profile['wind'];
                }
                if (is_numeric($r['temperatureMean']??null)) {
                    $t = (float)$r['temperatureMean'];
                    $delta = 0.0;
                    $minT = $custom ? (float)($custom['tempMin']??$profile['tempMin']) : (float)$profile['tempMin'];
                    $maxT = $custom ? (float)($custom['tempMax']??$profile['tempMax']) : (float)$profile['tempMax'];
                    if ($t < $minT)$delta = $minT - $t;
                    elseif ($t > $maxT)$delta = $t - $maxT;
                    $penalty+=$delta * (float)$profile['tempWeight'];
                }
            }
            $tempMean = $temps ? array_sum($temps) / count($temps) : null;
            $reasons['temperature'] = $tempMean===null ? null : round($tempMean, 1);
            
            if (!empty($profile['snowRequired'])&&$reasons['snow'] < 20)$penalty+=240;
            $score = (int)round(meteonexa_intel_clamp(100 - $penalty / 3 + (float)$profile['bonus'], 0, 100));
            if ($best===null||$score > $best['score']) {
                $start = meteonexa_intel_time_utc($slice[0]['time']);
                $end = meteonexa_intel_time_utc($slice[2]['time']);
                $best =['activity'=>$activity, 'score'=>$score, 'startsAt'=>$start ? gmdate('c', $start) : $slice[0]['time'], 'endsAt'=>$end ? gmdate('c', $end + 3600) : $slice[2]['time'], 'reasons'=>$reasons, 'basis'=>'weather-only'];
            }
        }
        if ($best)$out[] = $best;
    }
    usort($out, static fn($a, $b)=>$b['score']<=>$a['score']);
    return $out;
}
function meteonexa_intelq_nearest_horizon( ? string $startsAt) : int {
    $ts = meteonexa_intel_time_utc($startsAt??'');
    $lead = $ts===null ? 24 : max(1, (int)round(($ts - time()) / 3600));
    $choices =[1, 3, 6, 24, 48, 72];
    $best = 24;
    $distance = INF;
    foreach ($choices as $h) {
        $d = abs($lead - $h);
        if ($d < $distance) {
            $distance = $d;
            $best = $h;
        }
    }
    return $best;
}
function meteonexa_intelq_calibrate_probability(PDO $pdo, string $deviceId, string $locationKey, string $metric, float $rawProbability, ? int $horizonHours = null) : array {
    $raw = meteonexa_intel_clamp($rawProbability, 0, 1);
    $horizon = $horizonHours===null ? 24 : (int)$horizonHours;
    if (!in_array($horizon,[1, 3, 6, 24, 48, 72], true))$horizon = 24;
    if (!in_array($metric,['rain', 'storm', 'snow'], true)||!meteonexa_db_table_exists($pdo, 'model_skill_samples'))return['available'=>false, 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($raw * 100), 'samples'=>0, 'brier'=>null, 'horizonHours'=>$horizon];
    try {
        $low = max(0, $raw - .10);
        $high = min(1, $raw + .10);
        $st = $pdo->prepare("SELECT COUNT(*) samples,AVG(observed_value) event_rate,AVG(brier_score) brier FROM model_skill_samples WHERE device_id=:device AND location_key=:location AND model_name='consensus' AND metric=:metric AND horizon_hours=:horizon AND verified_at<>'' AND predicted_value BETWEEN :low AND :high");
        $st->execute([':device'=>$deviceId, ':location'=>$locationKey, ':metric'=>$metric, ':horizon'=>$horizon, ':low'=>$low, ':high'=>$high]);
        $row = $st->fetch();
        $n = (int)($row['samples']??0);
        $level = function_exists('meteonexa_reliability_sample_level') ? meteonexa_reliability_sample_level($n) :['id'=>($n>=100 ? 'consolidated' :($n>=30 ? 'building' :($n>=10 ? 'preliminary' : 'initial'))), 'publishable'=>$n>=30];
        if ($n < 10)return['available'=>false, 'publishable'=>false, 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($raw * 100), 'samples'=>$n, 'brier'=>$n ? round((float)$row['brier'], 4) : null, 'horizonHours'=>$horizon, 'calibrationLevel'=>$level['id'], 'uncertainty95'=>null];
        $eventRate = (float)($row['event_rate']??0);
        $eventCount = $eventRate * $n;
        $cal =($eventCount + 1) /($n + 2);
        $ci = function_exists('meteonexa_wilson_interval') ? meteonexa_wilson_interval($eventRate, $n) : null;
        return['available'=>true, 'publishable'=>!empty($level['publishable']), 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($cal * 100), 'samples'=>$n, 'brier'=>round((float)$row['brier'], 4), 'bin'=>[round($low, 2), round($high, 2)], 'horizonHours'=>$horizon, 'calibrationLevel'=>$level['id'], 'uncertainty95'=>$ci];
    } catch (Throwable $ignored) {
        return['available'=>false, 'rawProbabilityPct'=>(int)round($raw * 100), 'calibratedProbabilityPct'=>(int)round($raw * 100), 'samples'=>0, 'brier'=>null, 'horizonHours'=>$horizon];
    }
}
