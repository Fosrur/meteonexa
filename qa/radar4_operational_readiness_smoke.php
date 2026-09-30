<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/intelligence/radar4_calibration_helpers.php';
require_once $root . '/api/intelligence/radar4_promotion_study_helpers.php';
require_once $root . '/api/intelligence/radar4_operational_helpers.php';

$ok = static function(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};

$requirements = [
    'minimumAreas'=>3,
    'minimumSeasons'=>2,
    'minimumWeatherRegimes'=>3,
    'minimumBinarySamplesPerRainEvent'=>50,
    'minimumPositiveTimingSamplesPerRainEvent'=>30,
    'minimumGrowthSamples'=>30,
    'minimumEtaSamplesPerAlgorithm'=>60,
];
$immature = [
    'available'=>true,
    'datasetMature'=>false,
    'areas'=>2,
    'seasons'=>1,
    'weatherRegimes'=>2,
    'growthSamples'=>12,
    'maturityRequirements'=>$requirements,
    'events'=>[
        'rain_start'=>['binary'=>['samples'=>41], 'timing'=>['samples'=>20]],
        'rain_peak'=>['binary'=>['samples'=>35], 'timing'=>['samples'=>18]],
        'rain_end'=>['binary'=>['samples'=>29], 'timing'=>['samples'=>14]],
    ],
    'eta'=>[
        'radar-v3'=>['samples'=>51],
        'radar-v4'=>['samples'=>47],
    ],
];
$blockedStudy = [
    'available'=>false,
    'manualCanaryReviewEligible'=>false,
    'activationAllowed'=>false,
    'authorityLockedToRadar3'=>true,
    'reason'=>'p33_live_dataset_not_mature',
];
$progress = meteonexa_radar4_p3_evidence_progress($immature);
$ok($progress['checksTotal'] === 12, 'P3 operational progress exposes all 12 maturity checks');
$ok($progress['completionPct'] > 0 && $progress['completionPct'] < 100, 'P3 operational progress reports partial live-evidence completion');
$ok(count($progress['remaining']) > 0, 'P3 operational progress exposes exact remaining evidence deficits');

$readiness = meteonexa_radar4_p3_operational_readiness($immature, $blockedStudy);
$ok(($readiness['stage'] ?? '') === 'collecting-live-evidence', 'P3 operational readiness stays in live collection while P3.3 is immature');
$ok(($readiness['nextAction'] ?? '') === 'continue-server-side-shadow-collection', 'P3 operational readiness points to autonomous shadow collection');
$ok(empty($readiness['productionActivationAllowed']) && !empty($readiness['authorityLockedToRadar3']), 'P3 operational layer cannot activate Radar4');

$mature = $immature;
$mature['datasetMature'] = true;
$mature['areas'] = 3;
$mature['seasons'] = 2;
$mature['weatherRegimes'] = 3;
$mature['growthSamples'] = 30;
foreach (['rain_start','rain_peak','rain_end'] as $kind) {
    $mature['events'][$kind]['binary']['samples'] = 50;
    $mature['events'][$kind]['timing']['samples'] = 30;
}
$mature['eta']['radar-v3']['samples'] = 60;
$mature['eta']['radar-v4']['samples'] = 60;
$failedStudy = [
    'available'=>true,
    'datasetFingerprint'=>str_repeat('a', 64),
    'holdoutValidated'=>false,
    'stabilityValidated'=>true,
    'manualCanaryReviewEligible'=>false,
    'activationAllowed'=>false,
    'reason'=>'promotion-study-guardrails-not-met',
];
$failed = meteonexa_radar4_p3_operational_readiness($mature, $failedStudy);
$ok(($failed['stage'] ?? '') === 'promotion-study-guardrails-not-met', 'mature evidence does not bypass a failed P3.4 holdout');
$ok(($failed['liveEvidence']['completionPct'] ?? 0) === 100.0, 'mature synthetic status reaches 100 percent evidence completion');

$passedStudy = $failedStudy;
$passedStudy['holdoutValidated'] = true;
$passedStudy['stabilityValidated'] = true;
$passedStudy['manualCanaryReviewEligible'] = true;
$passedStudy['reason'] = 'promotion-study-passed-manual-canary-review-only';
$ready = meteonexa_radar4_p3_operational_readiness($mature, $passedStudy);
$ok(($ready['stage'] ?? '') === 'manual-canary-release-review-ready', 'P3 operational readiness opens only a separate manual canary review');
$ok(!empty($ready['operationalEvidenceComplete']), 'P3 operational evidence completes only after live maturity and P3.4 pass');
$ok(array_key_exists('trafficPercent', $ready['canaryRelease']) && $ready['canaryRelease']['trafficPercent'] === null && empty($ready['canaryRelease']['activationImplementedHere']), 'canary scope remains intentionally unconfigured in P3');
$ok(empty($ready['productionActivationAllowed']) && empty($ready['automaticPromotion']) && !empty($ready['authorityLockedToRadar3']), 'P3 completion never changes Radar3 authority');

$pipeline = file_get_contents($root . '/api/pipeline/worker.php');
$summary = file_get_contents($root . '/api/intelligence/summary.php');
$ok(str_contains($pipeline, 'meteonexa_radar4_live_shadow_cycle'), 'scheduled pipeline runs autonomous Radar4 live shadow evidence collection');
$ok(str_contains($pipeline, "'radar4-shadow-evidence'"), 'scheduled pipeline publishes Radar4 evidence health separately');
$ok(str_contains($summary, "'radar4OperationalReadiness'=>" . '$radar4OperationalReadiness'), 'authenticated intelligence exposes exact P3 operational readiness');
$ok(str_contains($summary, 'meteonexa_radar4_p34_persist_review_snapshot'), 'promotion study snapshot is frozen when a real dataset fingerprint exists');

$helper = file_get_contents($root . '/api/intelligence/radar4_operational_helpers.php');
$ok(str_contains($helper, "'productionActivationAllowed'=>false") && str_contains($helper, "'automaticPromotion'=>false") && str_contains($helper, "'authorityLockedToRadar3'=>true"), 'P3 operational safety invariants are hard-coded');
$ok(str_contains($helper, 'radar4-promotion-reviews/') && str_contains($helper, "'immutable'=>true"), 'promotion-study snapshots are immutable and fingerprint-addressed');

echo "Radar4 P3 operational readiness smoke PASS\n";
