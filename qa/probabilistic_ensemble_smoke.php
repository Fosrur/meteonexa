<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/database/driver.php';
require_once $root . '/api/database/schema.php';
require_once $root . '/api/database/migrations.php';
require_once $root . '/api/intelligence/ensemble_helpers.php';

$assert = static function(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$assert(abs((float)meteonexa_ensemble_quantile([0,1,2,3,4], .5) - 2.0) < 1e-9, 'median');
$assert(abs((float)meteonexa_ensemble_crps([5,5,5], 5.0)) < 1e-9, 'perfect CRPS');
$crps = meteonexa_ensemble_crps([0,1,2,3], 1.5);
$assert(is_float($crps) && $crps > 0 && $crps < 1, 'empirical CRPS');

$now = time();
$base = intdiv($now, 21600) * 21600;
$times = [];
for ($step=0; $step<=60; $step++) $times[] = gmdate('Y-m-d\TH:i', $base + $step * 21600);
$hourly = ['time'=>$times];
for ($member=1; $member<=12; $member++) {
    $suffix = sprintf('_member%02d', $member);
    $hourly['temperature_2m'.$suffix] = [];
    $hourly['precipitation'.$suffix] = [];
    $hourly['wind_gusts_10m'.$suffix] = [];
    foreach ($times as $i=>$unused) {
        $hourly['temperature_2m'.$suffix][] = 14 + $i * .08 + $member * .12;
        $hourly['precipitation'.$suffix][] = max(0, ($i % 5 === 0 ? 2.5 : .15) + $member * .02);
        $hourly['wind_gusts_10m'.$suffix][] = 25 + ($i % 7) * 2 + $member * .7;
    }
}
$raw = [
    'hourly'=>$hourly,
    '_meteonexaEnsemble'=>[
        'id'=>'aifs_ens','label'=>'ECMWF AIFS ENS','modelId'=>'ecmwf_aifs025_ensemble',
        'membersExpected'=>51,'sourceType'=>'ai-ensemble','cadenceMinutes'=>360,
    ],
    '_meteonexaCache'=>['stale'=>false,'cacheHit'=>false,'ageSeconds'=>0,'fetchedAt'=>gmdate('c')],
];
$rows = meteonexa_ensemble_rows($raw);
$assert(count($rows) === count($times), 'ensemble rows');
$assert(($rows[0]['temperature']['members'] ?? 0) === 12, 'member count');
$assert(($rows[0]['precipitation']['gte0_2Pct'] ?? null) !== null, 'precip threshold probability');
$assert(($rows[0]['windGust']['gte50Pct'] ?? null) !== null, 'wind threshold probability');
$scenarios = meteonexa_ensemble_member_daily_scenarios($raw, $base);
$assert(count($scenarios) >= 6, '8-15 day scenarios');
$assert(!empty($scenarios[0]['precipitation']['available']), 'daily member aggregation');
$uncertainty = meteonexa_ensemble_uncertainty($rows, 72);
$assert(!empty($uncertainty['available']) && is_numeric($uncertainty['score'] ?? null), 'uncertainty score');

$physics = ['available'=>true,'rows'=>[]];
foreach ($rows as $row) {
    $physics['rows'][] = [
        'timestamp'=>$row['timestamp'],
        'temperature'=>(float)($row['temperature']['p50'] ?? 0) + .2,
        'precipitation'=>(float)($row['precipitation']['p50'] ?? 0),
        'windGust'=>(float)($row['windGust']['p50'] ?? 0) + 1,
    ];
}
$comparison = meteonexa_ensemble_physics_comparison($rows, $physics, 72);
$assert(!empty($comparison['available']) && ($comparison['reference'] ?? '') === 'ECMWF IFS deterministic', 'independent physics comparison');

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE app_metadata(meta_key TEXT PRIMARY KEY, meta_value TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $migration = require $root . '/api/database/migrations/0029_probabilistic_ensemble_verification.php';
    $version = ($migration['up'])($pdo, 28, 'sqlite');
    $assert($version === 29 && meteonexa_db_table_exists($pdo, 'ensemble_verification_samples'), 'schema 29 ensemble table');
    $queued = meteonexa_ensemble_queue_verification($pdo, 'device-ensemble', '45.0000:9.0000', $raw);
    $assert($queued >= 6, 'verification rows queued');
    $target = (string)$pdo->query("SELECT target_time FROM ensemble_verification_samples WHERE horizon_hours=6 LIMIT 1")->fetchColumn();
    $verified = meteonexa_ensemble_verify_pending($pdo, 'device-ensemble', '45.0000:9.0000', [
        'available'=>true,'independentFromNwp'=>true,'observedAt'=>$target,
        'temperature'=>15.2,'wind'=>34.0,'qualityScore'=>90,
    ]);
    $assert($verified === 2, 'temperature/wind CRPS verification');
    $skill = meteonexa_ensemble_verification_summary($pdo, 'device-ensemble', '45.0000:9.0000');
    $assert(!empty($skill['available']) && (int)$skill['samples'] === 2, 'CRPS skill summary');
}

$summary = file_get_contents($root . '/api/intelligence/summary.php');
$ai = file_get_contents($root . '/api/ai/orchestrator.php');
$confidence = file_get_contents($root . '/api/intelligence/confidence_helpers.php');
$assert(str_contains($summary, "'probabilisticEnsemble'=>" . '$probabilisticEnsemble'), 'summary exposes probabilistic ensemble');
$assert(str_contains($ai, "'probabilistic_ensemble'"), 'Copilot probabilistic ensemble tool');
$assert(str_contains($confidence, "'ensemble'"), 'ensemble spread feeds confidence');

echo "Probabilistic Ensemble P2 smoke PASS\n";
