<?php
declare(strict_types=1);
function meteonexa_radar_object_tracks_v3(array $prepared) : array {
    if (count($prepared) < 2)return['available'=>false, 'cells'=>[], 'method'=>'object-tracking-v3', 'reason'=>'insufficient_frames'];
    $frames = array_reverse($prepared);
    $tracks =[];
    $active =[];
    $previousComponents =[];
    $splitDetected = false;
    $mergeDetected = false;
    foreach ($frames as $frameIndex=>$frame) {
        $time = (int)($frame['time']??0);
        $signal = (array)($frame['grid']??[]);
        $components = meteonexa_radar_components($signal, .13, 3);
        if (!$components)continue;
        if ($frameIndex===0||!$active) {
            foreach ($components as $ci=>$c) {
                $id = 'obj-' . substr(hash('sha256', $time . '|' . round((float)$c['cx'], 1) . '|' . round((float)$c['cy'], 1) . '|' . $ci), 0, 10);
                $tracks[$id] =['id'=>$id, 'history'=>[['time'=>$time, 'cell'=>$c]], 'parents'=>[], 'mergedFrom'=>[]];
                $active[$id] = $c;
            }
            $previousComponents = $active;
            continue;
        }
        $dt = max(1,($time - (int)($frames[$frameIndex - 1]['time']??($time - 300))) / 60);
        $candidates =[];
        $nearbyByComponent =[];
        foreach ($components as $ci=>$c) {
            foreach ($active as $id=>$prev) {
                $history = $tracks[$id]['history']??[];
                $last = end($history);
                $vx = 0.0;
                $vy = 0.0;
                if (count($history)>=2) {
                    $h2 = $history[count($history) - 2];
                    $dth = max(1,((int)$last['time'] - (int)$h2['time']) / 60);
                    $vx =((float)$last['cell']['cx'] - (float)$h2['cell']['cx']) / $dth;
                    $vy =((float)$last['cell']['cy'] - (float)$h2['cell']['cy']) / $dth;
                }
                $px = (float)$prev['cx'] + $vx * $dt;
                $py = (float)$prev['cy'] + $vy * $dt;
                $dist = hypot((float)$c['cx'] - $px, (float)$c['cy'] - $py);
                $areaPenalty = abs(log(max(.2, (float)$c['pixels'] / max(1, (float)$prev['pixels'])))) * 2.2;
                $energyPenalty = abs(log(max(.2, (float)$c['energy'] / max(.05, (float)$prev['energy'])))) * 1.6;
                $peakPenalty = abs((float)$c['peak'] - (float)$prev['peak']) * 2.0;
                $cost = $dist + $areaPenalty + $energyPenalty + $peakPenalty;
                if ($dist<=10.5) {
                    $candidates[] =['cost'=>$cost, 'id'=>$id, 'ci'=>$ci];
                    $nearbyByComponent[$ci][] =['id'=>$id, 'cost'=>$cost];
                }
            }
        }
        usort($candidates, static fn($a, $b)=>$a['cost']<=>$b['cost']);
        $usedTracks =[];
        $usedComponents =[];
        $newActive =[];
        foreach ($candidates as $cand) {
            $id = $cand['id'];
            $ci = $cand['ci'];
            if (isset($usedTracks[$id])||isset($usedComponents[$ci])||$cand['cost'] > 13.5)continue;
            $usedTracks[$id] = true;
            $usedComponents[$ci] = true;
            $tracks[$id]['history'][] =['time'=>$time, 'cell'=>$components[$ci]];
            $newActive[$id] = $components[$ci];
        }
        foreach ($components as $ci=>$c) {
            if (isset($usedComponents[$ci]))continue;
            $near = $nearbyByComponent[$ci]??[];
            usort($near, static fn($a, $b)=>$a['cost']<=>$b['cost']);
            $parents = array_values(array_unique(array_map(static fn($r)=>(string)$r['id'], array_filter($near, static fn($r)=>(float)$r['cost']<=11.5))));
            $id = 'obj-' . substr(hash('sha256', $time . '|' . round((float)$c['cx'], 1) . '|' . round((float)$c['cy'], 1) . '|' . $ci), 0, 10);
            $tracks[$id] =['id'=>$id, 'history'=>[['time'=>$time, 'cell'=>$c]], 'parents'=>$parents, 'mergedFrom'=>[]];
            if (count($parents)===1)$splitDetected = true;
            if (count($parents) > 1) {
                $mergeDetected = true;
                $tracks[$id]['mergedFrom'] = $parents;
            }
            $newActive[$id] = $c;
        }
        
        foreach ($active as $oldId=>$oldCell) {
            $nearCount = 0;
            foreach ($components as $c) {
                if (hypot((float)$c['cx'] - (float)$oldCell['cx'], (float)$c['cy'] - (float)$oldCell['cy'])<=8.5)$nearCount++;
            }
            if ($nearCount>=2)$splitDetected = true;
        }
        $active = $newActive;
        $previousComponents = $active;
    }
    if (!$active)return['available'=>false, 'cells'=>[], 'method'=>'object-tracking-v3', 'reason'=>'no_objects'];
    $cells =[];
    $center =((int)($prepared[0]['grid']['size']??32) - 1) / 2;
    foreach ($active as $id=>$cell) {
        $track = $tracks[$id];
        $history = $track['history'];
        $n = count($history);
        $vx = 0.0;
        $vy = 0.0;
        $prevVx = null;
        $prevVy = null;
        $accel = null;
        if ($n>=2) {
            $a = $history[$n - 2];
            $b = $history[$n - 1];
            $dt = max(1,((int)$b['time'] - (int)$a['time']) / 60);
            $vx =((float)$b['cell']['cx'] - (float)$a['cell']['cx']) / $dt;
            $vy =((float)$b['cell']['cy'] - (float)$a['cell']['cy']) / $dt;
            if ($n>=3) {
                $p = $history[$n - 3];
                $dt2 = max(1,((int)$a['time'] - (int)$p['time']) / 60);
                $prevVx =((float)$a['cell']['cx'] - (float)$p['cell']['cx']) / $dt2;
                $prevVy =((float)$a['cell']['cy'] - (float)$p['cell']['cy']) / $dt2;
                $accel = hypot($vx - $prevVx, $vy - $prevVy) / max(1, $dt);
            }
        }
        $growth = null;
        if ($n>=2) {
            $old = (float)$history[$n - 2]['cell']['energy'];
            if ($old > 0)$growth = 100 *((float)$cell['energy'] - $old) / $old;
        }
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $direction = $n>=2 ? $dirs[((int)round($angle / 45)) % 8] : null;
        $speed = hypot($vx, $vy);
        $eta = null;
        if ($speed > .015) {
            $toX = $center - (float)$cell['cx'];
            $toY = $center - (float)$cell['cy'];
            $dot = $toX * $vx + $toY * $vy;
            if ($dot > 0) {
                $eta = (int)round($dot /($speed * $speed));
                if ($eta < 0||$eta > 180)$eta = null;
            }
        }
        $stage = $growth===null ? 'new' :($growth > 18 ? 'growing' :($growth < - 18 ? 'decaying' : 'stable'));
        $ageMinutes = max(0,((int)$history[$n - 1]['time'] - (int)$history[0]['time']) / 60);
        $confidence = (int)round(meteonexa_intel_clamp(38 + min(30, $n * 8) + min(18, (float)$cell['peak'] * 20) - min(20,($accel??0) * 35), 15, 96));
        $cone =[];
        foreach ([15, 30, 45, 60, 90] as $minute) {
            $uncertainty = 1.2 + $minute *(1 - $confidence / 100) * .05 +($accel??0) * $minute * .5;
            $cone[] =['minute'=>$minute, 'x'=>round((float)$cell['cx'] + $vx * $minute, 2), 'y'=>round((float)$cell['cy'] + $vy * $minute, 2), 'radiusCells'=>round($uncertainty, 2)];
        }
        $cells[] =['id'=>$id, 'centroid'=>['x'=>$cell['cx'], 'y'=>$cell['cy']], 'areaPixels'=>(int)$cell['pixels'], 'bbox'=>$cell['bbox']??null, 'energy'=>$cell['energy'], 'peak'=>$cell['peak'], 'growthPct'=>$growth===null ? null : round($growth, 1), 'stage'=>$stage, 'direction'=>$direction, 'vxCellsMin'=>round($vx, 3), 'vyCellsMin'=>round($vy, 3), 'speedCellsMin'=>round($speed, 3), 'accelerationCellsMin2'=>$accel===null ? null : round($accel, 4), 'etaMinutes'=>$eta, 'trackConfidence'=>$confidence, 'ageMinutes'=>round($ageMinutes, 1), 'frameCount'=>$n, 'parentIds'=>$track['parents']??[], 'mergedFrom'=>$track['mergedFrom']??[], 'trajectoryCone'=>$cone];
    }
    usort($cells, static fn($a, $b)=>($b['energy']<=>$a['energy']));
    $dominant = $cells[0]??null;
    return['available'=>$cells!==[], 'cells'=>$cells, 'cellCount'=>count($cells), 'splitDetected'=>$splitDetected, 'mergeDetected'=>$mergeDetected, 'dominantEtaMinutes'=>$dominant['etaMinutes']??null, 'dominantCellId'=>$dominant['id']??null, 'method'=>'object-tracking-v3', 'shadowSafe'=>true];
}
function meteonexa_radar3_historical_guard(PDO $pdo, string $deviceId, string $locationKey, int $minimumSamples = 20) : array {
    $out =['available'=>false, 'allow'=>true, 'reason'=>'learning', 'minimumSamples'=>$minimumSamples, 'radar2'=>null, 'radar3'=>null];
    if (!meteonexa_db_table_exists($pdo, 'radar_eta_predictions')||!meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm'))return $out;
    try {
        $st = $pdo->prepare("SELECT algorithm,COUNT(*) samples,AVG(absolute_error_minutes) mae,AVG(CASE WHEN absolute_error_minutes<=tolerance_minutes THEN 1.0 ELSE 0.0 END) within_ratio FROM radar_eta_predictions WHERE device_id=:d AND location_key=:l AND status='verified' AND absolute_error_minutes IS NOT NULL AND algorithm IN ('radar-v2','radar-v3') GROUP BY algorithm");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        $rows =[];
        foreach ($st->fetchAll() as $r) {
            $rows[(string)$r['algorithm']] =['samples'=>(int)$r['samples'], 'maeMinutes'=>is_numeric($r['mae']??null) ? round((float)$r['mae'], 1) : null, 'withinTolerancePct'=>is_numeric($r['within_ratio']??null) ? round(100 * (float)$r['within_ratio'], 1) : null];
        }
        $out['radar2'] = $rows['radar-v2']??null;
        $out['radar3'] = $rows['radar-v3']??null;
        if (!$out['radar2']||!$out['radar3']||$out['radar2']['samples'] < $minimumSamples||$out['radar3']['samples'] < $minimumSamples)return $out;
        $out['available'] = true;
        $v2 = $out['radar2'];
        $v3 = $out['radar3'];
        $maeRegression = $v2['maeMinutes']!==null&&$v3['maeMinutes']!==null&&$v3['maeMinutes'] > max($v2['maeMinutes'] + 2.0, $v2['maeMinutes'] * 1.15);
        $toleranceRegression = $v2['withinTolerancePct']!==null&&$v3['withinTolerancePct']!==null&&$v3['withinTolerancePct'] < $v2['withinTolerancePct'] - 8.0;
        if ($maeRegression||$toleranceRegression) {
            $out['allow'] = false;
            $out['reason'] = $maeRegression ? 'mae_regression' : 'tolerance_regression';
            return $out;
        }
        $out['reason'] = 'verified_not_worse';
        return $out;
    } catch (Throwable $ignored) {
        return $out;
    }
}
function meteonexa_radar3_production_gate(PDO $pdo, string $deviceId, string $locationKey, array $radar3, int $radarAgeMinutes) : array {
    $result =['eligible'=>false, 'reason'=>'unavailable', 'quality'=>null, 'history'=>null];
    if (empty($radar3['available'])||empty($radar3['cells'])||!is_array($radar3['cells']))return $result;
    if ($radarAgeMinutes > 15) {
        $result['reason'] = 'stale_radar';
        return $result;
    }
    $best = null;
    foreach ($radar3['cells'] as $cell) {
        if (!is_array($cell))continue;
        $confidence = (int)($cell['trackConfidence']??0);
        $frames = (int)($cell['frameCount']??0);
        $cone = count((array)($cell['trajectoryCone']??[]));
        $energy = (float)($cell['energy']??0);
        if ($frames < 2||$confidence < 55||$cone < 5||$energy<=0)continue;
        if ($best===null||$confidence > (int)($best['trackConfidence']??0)||($confidence===(int)($best['trackConfidence']??0)&&$energy > (float)($best['energy']??0)))$best = $cell;
    }
    if (!$best) {
        $result['reason'] = 'track_quality';
        return $result;
    }
    $history = meteonexa_radar3_historical_guard($pdo, $deviceId, $locationKey, 20);
    $result['history'] = $history;
    $result['quality'] =['cellId'=>$best['id']??null, 'trackConfidence'=>(int)($best['trackConfidence']??0), 'frameCount'=>(int)($best['frameCount']??0), 'energy'=>round((float)($best['energy']??0), 3), 'radarAgeMinutes'=>$radarAgeMinutes];
    if (empty($history['available'])) {
        $result['reason'] = 'probation_insufficient_history';
        return $result;
    }
    if (empty($history['allow'])) {
        $result['reason'] = 'historical_' . $history['reason'];
        return $result;
    }
    $result['eligible'] = true;
    $result['reason'] = 'verified_active';
    return $result;
}
function meteonexa_radar_motion(PDO $pdo, string $deviceId, float $lat, float $lon) : array {
    if (!function_exists('imagecreatefromstring'))return['available'=>false, 'reason'=>'gd'];
    try {
        $stmt = $pdo->prepare('SELECT id,latitude,longitude FROM radar_archive_locations WHERE device_id=:device AND active=1 ORDER BY updated_at DESC');
        $stmt->execute([':device'=>$deviceId]);
        $best = null;
        $bestDistance = INF;
        foreach ($stmt->fetchAll() as $row) {
            $d = haversine_km($lat, $lon, (float)$row['latitude'], (float)$row['longitude']);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $row;
            }
        }
        if (!$best||$bestDistance > 80)return['available'=>false, 'reason'=>'no_archive'];
        $frames = $pdo->prepare('SELECT frame_time,image_path FROM radar_archive_frames WHERE location_id=:id ORDER BY frame_time DESC LIMIT 4');
        $frames->execute([':id'=>$best['id']]);
        $rows = $frames->fetchAll();
        if (count($rows) < 2) {
            meteonexa_observability_event('radar', 'motion', 'insufficient_frames',['frames'=>count($rows)]);
            return['available'=>false, 'reason'=>'insufficient_frames'];
        }
        $prepared =[];
        foreach ($rows as $row) {
            $grid = meteonexa_radar_signal_grid((string)$row['image_path']);
            if ($grid&&$grid['sum']>=1.5)$prepared[] =['time'=>(int)$row['frame_time'], 'grid'=>$grid];
        }
        if (count($prepared) < 2) {
            meteonexa_observability_event('radar', 'motion', 'weak_signal',['usableFrames'=>count($prepared)]);
            return['available'=>false, 'reason'=>'weak_signal'];
        }
        $vectors =[];
        for ($i = 0; $i < count($prepared) - 1; $i++) {
            $new = $prepared[$i];
            $old = $prepared[$i + 1];
            $dt = max(60, $new['time'] - $old['time']);
            $pair = meteonexa_radar_pair_motion($old['grid'], $new['grid']);
            if ($pair['confidence'] < 12)continue;
            $minutes = $dt / 60;
            $weight = max(.05, (float)$pair['correlation']) * max(.15, $pair['confidence'] / 100);
            $vectors[] =['vx'=>$pair['dx'] / $minutes, 'vy'=>$pair['dy'] / $minutes, 'dx'=>$pair['dx'], 'dy'=>$pair['dy'], 'dt'=>$dt, 'confidence'=>$pair['confidence'], 'weight'=>$weight];
        }
        if (!$vectors)return['available'=>false, 'reason'=>'motion_uncertain'];
        $sumW = array_sum(array_column($vectors, 'weight'));
        $vx = 0.0;
        $vy = 0.0;
        $confidence = 0.0;
        foreach ($vectors as $v) {
            $w = $v['weight'];
            $vx+=$v['vx'] * $w;
            $vy+=$v['vy'] * $w;
            $confidence+=$v['confidence'] * $w;
        }
        $vx/=$sumW;
        $vy/=$sumW;
        $confidence/=$sumW;
        
        $spread = 0.0;
        foreach ($vectors as $v)$spread+=sqrt(($v['vx'] - $vx)**2 +($v['vy'] - $vy)**2) * $v['weight'];
        $spread/=$sumW;
        $confidence = (int)round(meteonexa_intel_clamp($confidence - max(0, $spread * 9) + min(8,(count($vectors) - 1) * 3), 10, 96));
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $dir = $dirs[((int)round($angle / 45)) % 8];
        $latest = $prepared[0]['grid'];
        $center =((int)$latest['size'] - 1) / 2;
        $eta = null;
        $speed2 = $vx * $vx + $vy * $vy;
        if ($speed2 > .0001&&$latest['cx']!==null&&$latest['cy']!==null) {
            $toX = $center - $latest['cx'];
            $toY = $center - $latest['cy'];
            $dot = $toX * $vx + $toY * $vy;
            if ($dot > 0) {
                $eta = (int)round($dot / $speed2);
                if ($eta < 0||$eta > 180)$eta = null;
            }
        }
        $avgFrameMinutes = array_sum(array_map(static fn($v)=>$v['dt'] / 60, $vectors)) / count($vectors);
        $multiCells = meteonexa_radar_multicell_tracks($prepared[1]['grid'], $prepared[0]['grid'], max(1,($prepared[0]['time'] - $prepared[1]['time']) / 60));
        $radar3 = meteonexa_radar_object_tracks_v3($prepared);
        $radar4RequestedMode = 'shadow';
        $radar4 = ['available'=>false, 'cells'=>[], 'mode'=>'off', 'authoritative'=>false, 'reason'=>'disabled'];
        $radar3RequestedMode = 'active';
        if (function_exists('load_config')) {
            $cfg = load_config();
            $radar3RequestedMode = (string)($cfg['radar3']['mode']??'active');
            $radar4RequestedMode = (string)($cfg['radar4']['mode']??'shadow');
        }
        $radarAgeMinutes = max(0, (int)round((time() - (int)$prepared[0]['time']) / 60));
        $locationKey = meteonexa_intelligence_location_key($lat, $lon);
        if ($radar4RequestedMode==='shadow')$radar4 = meteonexa_radar4_shadow_tracks($prepared, $radar3);
        $radar4['requestedMode'] = $radar4RequestedMode;
        $radar4Gate = meteonexa_radar4_shadow_gate($pdo, $deviceId, $locationKey, 30);
        $radar3Gate = meteonexa_radar3_production_gate($pdo, $deviceId, $locationKey, $radar3, $radarAgeMinutes);
        $radar3Mode = $radar3RequestedMode;
        if ($radar3RequestedMode==='active') {
            if (!empty($radar3Gate['eligible'])) {
                $multiCells = $radar3;
                $radar3Mode = 'active';
            } elseif (($radar3Gate['reason']??'')==='probation_insufficient_history') {
                $radar3Mode = 'probation-v2';
            } else {
                $radar3Mode = 'active-fallback-v2';
            }
        }
        if (function_exists('meteonexa_observability_event')) {
            meteonexa_observability_event('radar', 'radar3', $radar3Mode,['reason'=>(string)($radar3Gate['reason']??'unknown'), 'radarAgeMinutes'=>$radarAgeMinutes, 'cellCount'=>(int)($radar3['cellCount']??0)]);
            meteonexa_observability_event('radar', 'radar4', $radar4RequestedMode==='shadow' ? 'shadow' : 'off',['available'=>!empty($radar4['available']), 'promotionCandidate'=>!empty($radar4Gate['promotionCandidate']), 'radarAgeMinutes'=>$radarAgeMinutes, 'cellCount'=>(int)($radar4['cellCount']??0)]);
        }
        $centerIndex = (int)round($center);
        $centerSamples =[];
        for ($yy = max(0, $centerIndex - 1); $yy<=min((int)$latest['size'] - 1, $centerIndex + 1); $yy++) for ($xx = max(0, $centerIndex - 1); $xx<=min((int)$latest['size'] - 1, $centerIndex + 1); $xx++)$centerSamples[] = (float)($latest['grid'][$yy][$xx]??0);
        $centerSignal = $centerSamples ? array_sum($centerSamples) / count($centerSamples) : 0.0;
        return['available'=>true, 'multiCellTracking'=>$multiCells, 'radar3Tracking'=>$radar3, 'radar3Mode'=>$radar3Mode, 'radar3RequestedMode'=>$radar3RequestedMode, 'radar3ProductionGate'=>$radar3Gate, 'radar4Tracking'=>$radar4, 'radar4Mode'=>$radar4RequestedMode==='shadow' ? 'shadow' : 'off', 'radar4RequestedMode'=>$radar4RequestedMode, 'radar4ShadowGate'=>$radar4Gate, 'radarAuthority'=>$radar3Mode==='active' ? 'radar3' : 'radar2', 'radarObservation'=>['observedAt'=>gmdate('c', (int)$prepared[0]['time']), 'centerSignal'=>round($centerSignal, 3), 'archiveDistanceKm'=>round($bestDistance, 1), 'independentFromTrajectory'=>true], 'direction'=>$dir, 'angle'=>round($angle, 1), 'vxCellsMin'=>round($vx, 3), 'vyCellsMin'=>round($vy, 3), 'confidence'=>$confidence, 'etaMinutes'=>$eta, 'frameMinutes'=>round($avgFrameMinutes, 1), 'vectorSamples'=>count($vectors), 'archiveDistanceKm'=>round($bestDistance, 1), 'method'=>'multi-frame-block-correlation', 'latestFrameAt'=>gmdate('c', (int)$prepared[0]['time']), 'ageMinutes'=>$radarAgeMinutes];
    } catch (Throwable $ignored) {
        meteonexa_observability_event('radar', 'motion', 'analysis_failed',['class'=>get_class($ignored)]);
        return['available'=>false, 'reason'=>'analysis_failed'];
    }
}
