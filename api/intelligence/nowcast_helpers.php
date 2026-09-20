<?php
declare(strict_types=1);
require_once __DIR__ . '/reliability_helpers.php';
function meteonexa_enrich_cell_tracking(array $cell, float $lat) : array {
    if (empty($cell['available']))return $cell;
    $kmCell = 40075.016686 * max(.08, cos(deg2rad($lat))) /(2**7) / 32;
    if (is_numeric($cell['speedCellsMin']??null))$cell['speedKmh'] = round((float)$cell['speedCellsMin'] * $kmCell * 60, 1);
    if (is_numeric($cell['etaMinutes']??null)) {
        $eta = (int)$cell['etaMinutes'];
        $conf = (int)($cell['trackConfidence']??50);
        $u = max(5, (int)round(4 + $eta *(1 - $conf / 100) * .65));
        $cell['etaRangeMinutes'] =[max(0, $eta - $u), min(240, $eta + $u)];
    }
    return $cell;
}
function meteonexa_nowcast_fusion(array $consensus, array $motion, array $cell, array $lightning, array $satellite, array $official, array $obs, float $lat) : array {
    $cell = meteonexa_enrich_cell_tracking($cell, $lat);
    $parts =[];
    $sum = 0;
    $total = 0;
    $add = function($id, $p, $w, $c, $detail =[])use(&$parts, &$sum, &$total) {
        $ww = $w * max(.2, min(1, $c / 100));
        $sum+=max(0, min(100, $p)) * $ww;
        $total+=$ww;
        $parts[] =['id'=>$id, 'probabilityPct'=>(int)round($p), 'confidence'=>$c, 'detail'=>$detail];
    };
    if (!empty($cell['available']))$add('radarCell', (float)($cell['impactProbability']??0), .46, (int)($cell['trackConfidence']??55));
    elseif (!empty($motion['available']))$add('radarMotion', $motion['etaMinutes']!==null ? 58 : 35, .34, (int)($motion['confidence']??45));
    if (!empty($lightning['available'])&&empty($lightning['degraded'])) {
        $recent = (int)($lightning['recent30m']??0);
        $near = (float)($lightning['nearestKm']??100);
        $add('lightning', $recent ? min(98, 38 + $recent * 7 + max(0, 32 - $near)) : 5, .2, 90);
    }
    if (!empty($consensus['primary']))$add('nwp', (float)($consensus['primary']['weightedAgreementPct']??$consensus['primary']['agreementPct']??0), .19, 80);
    if (!empty($satellite['available']))$add('satellite', (float)($satellite['cloudAttenuationPct']??0), .06, 60);
    if (!empty($official['relevant']))$add('official', 72, .09, !empty($official['geospatial']) ? 98 : 78);
    $impact = $total ? (int)round($sum / $total) : 0;
    $confidence = $parts ? (int)round(meteonexa_intel_clamp(45 + count($parts) * 8, 25, 97)) : 0;
    $eta = $cell['etaMinutes']??$motion['etaMinutes']??null;
    $range = $cell['etaRangeMinutes']??($eta===null ? null :[max(0, (int)$eta - 10), (int)$eta + 10]);
    return['available'=>$parts!==[], 'method'=>'radar-lightning-satellite-nwp-fusion-v1', 'impactProbability'=>$impact, 'confidence'=>$confidence, 'severity'=>$impact>=80 ? 'red' :($impact>=60 ? 'orange' :($impact>=35 ? 'yellow' : 'green')), 'etaMinutes'=>$eta, 'etaRangeMinutes'=>$range, 'direction'=>$cell['direction']??$motion['direction']??null, 'speedKmh'=>$cell['speedKmh']??null, 'growthPct'=>$cell['growthPct']??null, 'stage'=>$cell['stage']??null, 'towardLocation'=>$cell['towardLocation']??null, 'sourceIds'=>array_column($parts, 'id'), 'sources'=>$parts, 'capGeoJson'=>!empty($official['geospatial'])&&($official['mode']??'')==='edr-geospatial', 'officialMode'=>(string)($official['mode']??'none'), 'observationSourceCount'=>(int)($obs['sourceCount']??0), 'generatedAt'=>gmdate('c')];
}
function meteonexa_persist_nowcast(PDO $pdo, string $device, string $location, array $fusion) : array {
    if (!meteonexa_db_table_exists($pdo, 'nowcast_fusion_snapshots'))return['persisted'=>false, 'trend'=>'unknown'];
    $st = $pdo->prepare('SELECT snapshot_json,created_at FROM nowcast_fusion_snapshots WHERE device_id=:d AND location_key=:l ORDER BY id DESC LIMIT 1');
    $st->execute([':d'=>$device, ':l'=>$location]);
    $row = $st->fetch();
    $prev = is_array($row) ? json_decode((string)$row['snapshot_json'], true) : null;
    $delta = is_array($prev) ? (int)($fusion['impactProbability']??0) - (int)($prev['impactProbability']??0) : null;
    $trend = $delta===null ? 'unknown' :($delta>=15 ? 'increasing' :($delta<= - 15 ? 'decreasing' : 'stable'));
    if (!is_array($row)||(strtotime((string)$row['created_at']) ? : 0) < time() - 300) {
        $ins = $pdo->prepare('INSERT INTO nowcast_fusion_snapshots(device_id,location_key,snapshot_json,created_at) VALUES(:d,:l,:s,:c)');
        $ins->execute([':d'=>$device, ':l'=>$location, ':s'=>json_encode($fusion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':c'=>gmdate('c')]);
    }
    return['persisted'=>true, 'trend'=>$trend, 'impactDelta'=>$delta];
}
