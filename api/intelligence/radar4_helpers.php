<?php
declare(strict_types=1);








function meteonexa_radar4_pair_optical_flow(array $old, array $new) : array {
    $oldGrid = (array)($old['grid'] ?? []);
    $newGrid = (array)($new['grid'] ?? []);
    $size = min((int)($old['size'] ?? count($oldGrid)), (int)($new['size'] ?? count($newGrid)));
    if ($size < 6)return['available'=>false, 'reason'=>'grid_too_small', 'vx'=>0.0, 'vy'=>0.0, 'confidence'=>0];

    $a = 0.0;
    $b = 0.0;
    $c = 0.0;
    $d = 0.0;
    $e = 0.0;
    $signal = 0.0;
    $samples = 0;
    $terms =[];
    for ($y = 1; $y < $size - 1; $y++) {
        for ($x = 1; $x < $size - 1; $x++) {
            $o = (float)($oldGrid[$y][$x] ?? 0);
            $n = (float)($newGrid[$y][$x] ?? 0);
            $intensity = max($o, $n);
            if ($intensity < .045)continue;
            $ix = .25 * (
                ((float)($oldGrid[$y][$x + 1] ?? 0) - (float)($oldGrid[$y][$x - 1] ?? 0)) +
                ((float)($newGrid[$y][$x + 1] ?? 0) - (float)($newGrid[$y][$x - 1] ?? 0))
            );
            $iy = .25 * (
                ((float)($oldGrid[$y + 1][$x] ?? 0) - (float)($oldGrid[$y - 1][$x] ?? 0)) +
                ((float)($newGrid[$y + 1][$x] ?? 0) - (float)($newGrid[$y - 1][$x] ?? 0))
            );
            $it = $n - $o;
            $weight = .25 + min(1.0, $intensity) * .75;
            $a += $weight * $ix * $ix;
            $b += $weight * $ix * $iy;
            $c += $weight * $iy * $iy;
            $d += -$weight * $ix * $it;
            $e += -$weight * $iy * $it;
            $signal += $intensity;
            $samples++;
            $terms[] =[$ix, $iy, $it, $weight];
        }
    }
    if ($samples < 12)return['available'=>false, 'reason'=>'insufficient_signal', 'vx'=>0.0, 'vy'=>0.0, 'confidence'=>0, 'samples'=>$samples];
    $det = $a * $c - $b * $b;
    if ($det < 1e-8)return['available'=>false, 'reason'=>'ill_conditioned', 'vx'=>0.0, 'vy'=>0.0, 'confidence'=>0, 'samples'=>$samples];

    $vx = ($d * $c - $b * $e) / $det;
    $vy = ($a * $e - $b * $d) / $det;
    $vx = max(-6.0, min(6.0, $vx));
    $vy = max(-6.0, min(6.0, $vy));
    $residual = 0.0;
    $weightSum = 0.0;
    foreach ($terms as[$ix, $iy, $it, $weight]) {
        $r = $ix * $vx + $iy * $vy + $it;
        $residual += $weight * $r * $r;
        $weightSum += $weight;
    }
    $rmse = sqrt($residual / max(.001, $weightSum));
    $texture = min(1.0, sqrt(max(0.0, $det)) * 35);
    $coverage = min(1.0, $samples / max(1, ($size - 2) * ($size - 2) * .16));
    $residualScore = max(0.0, 1.0 - min(1.0, $rmse / .18));
    $confidence = (int)round(meteonexa_intel_clamp(12 + 42 * $texture + 24 * $coverage + 22 * $residualScore, 8, 96));
    return[
        'available'=>true,
        'vx'=>round($vx, 4),
        'vy'=>round($vy, 4),
        'confidence'=>$confidence,
        'samples'=>$samples,
        'rmse'=>round($rmse, 5),
        'signal'=>round($signal, 3),
        'method'=>'lucas-kanade-global-grid',
    ];
}

function meteonexa_radar4_multiframe_flow(array $prepared) : array {
    if (count($prepared) < 2)return['available'=>false, 'reason'=>'insufficient_frames', 'method'=>'multi-frame-lucas-kanade'];
    $pairs =[];
    for ($i = 0; $i < count($prepared) - 1; $i++) {
        $new = $prepared[$i];
        $old = $prepared[$i + 1];
        $dtMinutes = max(1.0, ((int)($new['time'] ?? 0) - (int)($old['time'] ?? 0)) / 60);
        $flow = meteonexa_radar4_pair_optical_flow((array)($old['grid'] ?? []), (array)($new['grid'] ?? []));
        if (empty($flow['available']) || (int)($flow['confidence'] ?? 0) < 15)continue;
        $pairs[] =[
            'vx'=>(float)$flow['vx'] / $dtMinutes,
            'vy'=>(float)$flow['vy'] / $dtMinutes,
            'confidence'=>(int)$flow['confidence'],
            'dtMinutes'=>round($dtMinutes, 2),
            'rmse'=>$flow['rmse'] ?? null,
            'samples'=>(int)($flow['samples'] ?? 0),
        ];
    }
    if (!$pairs)return['available'=>false, 'reason'=>'flow_uncertain', 'method'=>'multi-frame-lucas-kanade', 'pairCount'=>0];

    $sumW = 0.0;
    $vx = 0.0;
    $vy = 0.0;
    foreach ($pairs as $pair) {
        $w = max(.08, (float)$pair['confidence'] / 100);
        $sumW += $w;
        $vx += (float)$pair['vx'] * $w;
        $vy += (float)$pair['vy'] * $w;
    }
    $vx /= max(.001, $sumW);
    $vy /= max(.001, $sumW);
    $spread = 0.0;
    $confidence = 0.0;
    foreach ($pairs as $pair) {
        $w = max(.08, (float)$pair['confidence'] / 100);
        $spread += hypot((float)$pair['vx'] - $vx, (float)$pair['vy'] - $vy) * $w;
        $confidence += (float)$pair['confidence'] * $w;
    }
    $spread /= max(.001, $sumW);
    $confidence /= max(.001, $sumW);
    $confidence = (int)round(meteonexa_intel_clamp($confidence + min(9, (count($pairs) - 1) * 4) - min(28, $spread * 65), 10, 96));
    return[
        'available'=>true,
        'vxCellsMin'=>round($vx, 4),
        'vyCellsMin'=>round($vy, 4),
        'speedCellsMin'=>round(hypot($vx, $vy), 4),
        'confidence'=>$confidence,
        'vectorSpread'=>round($spread, 4),
        'pairCount'=>count($pairs),
        'pairs'=>$pairs,
        'method'=>'multi-frame-lucas-kanade',
    ];
}

function meteonexa_radar4_shadow_tracks(array $prepared, array $radar3) : array {
    $flow = meteonexa_radar4_multiframe_flow($prepared);
    if (empty($radar3['available']) || empty($radar3['cells'])) {
        return[
            'available'=>false,
            'cells'=>[],
            'flow'=>$flow,
            'mode'=>'shadow',
            'authoritative'=>false,
            'method'=>'optical-flow-object-tracking-v4',
            'reason'=>'radar3_tracks_unavailable',
        ];
    }
    $latest = (array)($prepared[0]['grid'] ?? []);
    $size = (int)($latest['size'] ?? 32);
    $center = ($size - 1) / 2;
    $cells =[];
    foreach ((array)$radar3['cells'] as $source) {
        if (!is_array($source))continue;
        $objectVx = (float)($source['vxCellsMin'] ?? 0);
        $objectVy = (float)($source['vyCellsMin'] ?? 0);
        $trackConfidence = (int)($source['trackConfidence'] ?? 0);
        $flowAvailable = !empty($flow['available']);
        $flowConfidence = $flowAvailable ? (int)($flow['confidence'] ?? 0) : 0;
        $flowWeight = $flowAvailable ? meteonexa_intel_clamp(.18 + .32 * ($flowConfidence / 100), .18, .50) : 0.0;
        $objectWeight = 1.0 - $flowWeight;
        $vx = $objectVx * $objectWeight + (float)($flow['vxCellsMin'] ?? 0) * $flowWeight;
        $vy = $objectVy * $objectWeight + (float)($flow['vyCellsMin'] ?? 0) * $flowWeight;
        $speed = hypot($vx, $vy);
        $disagreement = $flowAvailable ? hypot($objectVx - (float)$flow['vxCellsMin'], $objectVy - (float)$flow['vyCellsMin']) : 0.0;
        $confidence = (int)round(meteonexa_intel_clamp(
            $trackConfidence * ($flowAvailable ? .68 : .9) + $flowConfidence * ($flowAvailable ? .32 : 0) - min(24, $disagreement * 70),
            10,
            97
        ));
        $cx = (float)($source['centroid']['x'] ?? $center);
        $cy = (float)($source['centroid']['y'] ?? $center);
        $toX = $center - $cx;
        $toY = $center - $cy;
        $dot = $toX * $vx + $toY * $vy;
        $eta = null;
        if ($speed > .012 && $dot > 0) {
            $eta = (int)round($dot / max(.0001, $speed * $speed));
            if ($eta < 0 || $eta > 120)$eta = null;
        }
        $growth = is_numeric($source['growthPct'] ?? null) ? (float)$source['growthPct'] : null;
        $growthDecayScore = $growth===null ? 0 : (int)round(meteonexa_intel_clamp($growth * 1.35, -100, 100));
        $stage = $growthDecayScore >= 28 ? 'growing' : ($growthDecayScore <= -28 ? 'decaying' : ($growth===null ? 'new' : 'stable'));
        $angle = atan2($vx, -$vy) * 180 / M_PI;
        if ($angle < 0)$angle += 360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $direction = $speed > .005 ? $dirs[((int)round($angle / 45)) % 8] : null;
        $cone =[];
        foreach ([15, 30, 45, 60, 90, 120] as $minute) {
            $radius = 1.1 + $minute * (1 - $confidence / 100) * .055 + $disagreement * $minute * .35;
            $cone[] =[
                'minute'=>$minute,
                'x'=>round($cx + $vx * $minute, 2),
                'y'=>round($cy + $vy * $minute, 2),
                'radiusCells'=>round($radius, 2),
            ];
        }
        $cells[] =[
            'id'=>'r4-' . substr((string)($source['id'] ?? hash('sha256', $cx . '|' . $cy)), 0, 18),
            'sourceRadar3CellId'=>$source['id'] ?? null,
            'centroid'=>['x'=>round($cx, 2), 'y'=>round($cy, 2)],
            'energy'=>round((float)($source['energy'] ?? 0), 3),
            'peak'=>round((float)($source['peak'] ?? 0), 3),
            'areaPixels'=>(int)($source['areaPixels'] ?? 0),
            'direction'=>$direction,
            'vxCellsMin'=>round($vx, 4),
            'vyCellsMin'=>round($vy, 4),
            'speedCellsMin'=>round($speed, 4),
            'etaMinutes'=>$eta,
            'towardLocation'=>$dot > 0,
            'trackConfidence'=>$confidence,
            'frameCount'=>(int)($source['frameCount'] ?? count($prepared)),
            'ageMinutes'=>$source['ageMinutes'] ?? null,
            'growthPct'=>$growth===null ? null : round($growth, 1),
            'growthDecayScore'=>$growthDecayScore,
            'stage'=>$stage,
            'vectorDisagreement'=>round($disagreement, 4),
            'trajectoryCone'=>$cone,
        ];
    }
    usort($cells, static fn($a, $b)=>(($b['trackConfidence'] ?? 0) <=> ($a['trackConfidence'] ?? 0)) ?: (($b['energy'] ?? 0) <=> ($a['energy'] ?? 0)));
    $dominant = null;
    foreach ($cells as $cell) {
        if (!is_numeric($cell['etaMinutes'] ?? null))continue;
        if ($dominant===null || (float)($cell['energy'] ?? 0) > (float)($dominant['energy'] ?? 0))$dominant = $cell;
    }
    return[
        'available'=>$cells!==[],
        'cells'=>$cells,
        'cellCount'=>count($cells),
        'dominantCellId'=>$dominant['id'] ?? null,
        'dominantEtaMinutes'=>$dominant['etaMinutes'] ?? null,
        'flow'=>$flow,
        'horizonMinutes'=>120,
        'mode'=>'shadow',
        'authoritative'=>false,
        'authority'=>'radar3',
        'method'=>'optical-flow-object-tracking-v4',
        'policy'=>[
            'shadowOnly'=>true,
            'productionDecisionsUnaffected'=>true,
            'requiresBacktestPromotion'=>true,
        ],
    ];
}

function meteonexa_radar4_shadow_gate(PDO $pdo, string $deviceId, string $locationKey, int $minimumSamples = 30) : array {
    $result =[
        'available'=>false,
        'promotionCandidate'=>false,
        'authorityLockedToRadar3'=>true,
        'reason'=>'learning',
        'minimumSamples'=>$minimumSamples,
        'baseline'=>'radar-v3',
        'thresholds'=>[
            'minimumAbsoluteMaeImprovementMinutes'=>1.0,
            'minimumRelativeMaeImprovementPct'=>8.0,
            'maximumWithinToleranceRegressionPct'=>2.0,
        ],
        'radar3'=>null,
        'radar4'=>null,
    ];
    if (!meteonexa_db_table_exists($pdo, 'radar_eta_predictions') || !meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm'))return $result;
    try {
        $st = $pdo->prepare("SELECT algorithm,COUNT(*) samples,AVG(absolute_error_minutes) mae,AVG(CASE WHEN absolute_error_minutes<=tolerance_minutes THEN 1.0 ELSE 0.0 END) within_ratio FROM radar_eta_predictions WHERE device_id=:d AND location_key=:l AND status='verified' AND absolute_error_minutes IS NOT NULL AND algorithm IN ('radar-v3','radar-v4') GROUP BY algorithm");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        $rows =[];
        foreach ($st->fetchAll() as $row) {
            $rows[(string)$row['algorithm']] =[
                'samples'=>(int)($row['samples'] ?? 0),
                'maeMinutes'=>is_numeric($row['mae'] ?? null) ? round((float)$row['mae'], 2) : null,
                'withinTolerancePct'=>is_numeric($row['within_ratio'] ?? null) ? round(100 * (float)$row['within_ratio'], 1) : null,
            ];
        }
        $result['radar3'] = $rows['radar-v3'] ?? null;
        $result['radar4'] = $rows['radar-v4'] ?? null;
        if (!$result['radar3'] || !$result['radar4'])return $result;
        if ($result['radar3']['samples'] < $minimumSamples || $result['radar4']['samples'] < $minimumSamples)return $result;
        $result['available'] = true;
        $mae3 = (float)$result['radar3']['maeMinutes'];
        $mae4 = (float)$result['radar4']['maeMinutes'];
        $absoluteImprovement = $mae3 - $mae4;
        $relativeImprovement = $mae3 > 0 ? 100 * $absoluteImprovement / $mae3 : 0.0;
        $withinDelta = (float)$result['radar4']['withinTolerancePct'] - (float)$result['radar3']['withinTolerancePct'];
        $result['comparison'] =[
            'maeImprovementMinutes'=>round($absoluteImprovement, 2),
            'maeImprovementPct'=>round($relativeImprovement, 1),
            'withinToleranceDeltaPct'=>round($withinDelta, 1),
        ];
        $result['promotionCandidate'] = $absoluteImprovement >= 1.0 && $relativeImprovement >= 8.0 && $withinDelta >= -2.0;
        $result['reason'] = $result['promotionCandidate'] ? 'guardrails_pass_shadow_only' : 'guardrails_not_met';
        return $result;
    } catch (Throwable $ignored) {
        $result['reason'] = 'evaluation_failed';
        return $result;
    }
}
