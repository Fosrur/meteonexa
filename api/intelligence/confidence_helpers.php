<?php
declare(strict_types=1);
function meteonexa_weather_confidence(array $analysis, array $consensus, array $reliability, array $nowcast) : array {
    $parts =[];
    $sum = 0;
    $total = 0;
    $add = function($id, $score, $weight, $status)use(&$parts, &$sum, &$total) {
        if (!is_numeric($score)) {
            $parts[] =['id'=>$id, 'available'=>false, 'score'=>null, 'status'=>$status];
            return;
        }
        $s = max(0, min(100, (float)$score));
        $sum+=$s * $weight;
        $total+=$weight;
        $parts[] =['id'=>$id, 'available'=>true, 'score'=>(int)round($s), 'status'=>$status];
    };
    $p = (array)($consensus['primary']??[]);
    $agreement = $p['weightedAgreementPct']??$p['agreementPct']??null;
    $add('models', $agreement, .30, is_numeric($agreement)&&$agreement>=80 ? 'strong' : 'mixed');
    $add('engine', $analysis['confidence']??null, .22, 'good');
    $samples = (int)($reliability['metricVerifiedSamples']??0);
    $add('observations', $reliability['observationQualityScore']??null, .18, $samples>=100 ? 'consolidated' :($samples>=30 ? 'building' :($samples>=10 ? 'preliminary' : 'learning')));
    $run = (array)($reliability['runStability']??[]);
    $trend = (string)($run['trend']??'unknown');
    $add('stability', $trend==='stable' ? 92 :($trend==='changing' ? 66 :($trend==='volatile' ? 38 : null)), .15, $trend);
    $add('nowcast', !empty($nowcast['available']) ?($nowcast['confidence']??null) : null, .15, !empty($nowcast['available']) ? 'live' : 'unavailable');
    $score = $total ? (int)round($sum / $total) : 0;
    return['available'=>$total > 0, 'score'=>$score, 'level'=>$score>=85 ? 'very_high' :($score>=72 ? 'high' :($score>=55 ? 'medium' : 'low')), 'maturity'=>$samples>=100 ? 'consolidated' :($samples>=30 ? 'building' :($samples>=10 ? 'preliminary' : 'learning')), 'verified'=>$samples>=30, 'parts'=>$parts, 'samples'=>$samples, 'method'=>'deterministic-evidence-confidence-v1', 'generatedAt'=>gmdate('c')];
}
