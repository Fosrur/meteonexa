<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/ai/orchestrator.php';

$fail = [];
$check = static function(bool $value, string $message) use (&$fail): void {
    echo ($value ? 'PASS' : 'FAIL') . ': ' . $message . PHP_EOL;
    if (!$value) $fail[] = $message;
};

$activity = [
    'bestWindow' => ['start' => '2026-09-17T12:00:00Z', 'end' => '2026-09-17T14:00:00Z', 'score' => 82],
    'alternativeWindow' => ['start' => '2026-09-17T16:00:00Z', 'end' => '2026-09-17T17:00:00Z', 'score' => 72],
    'timeline' => [
        ['score' => 82, 'risk' => 'none'],
        ['score' => 58, 'risk' => 'rain'],
    ],
];
$confidence = ['score' => 78];

$base = meteonexa_copilot_decision_summary($activity, $confidence, ['available' => false], []);
$check(($base['status'] ?? '') === 'good', 'good activity window produces good deterministic status');
$check((int)($base['score'] ?? 0) === 82, 'activity score is preserved');
$check((int)($base['confidence'] ?? 0) === 78, 'confidence is propagated');
$check(($base['dominantRisk'] ?? '') === 'rain', 'timeline risk is surfaced deterministically');

$route = [
    'available' => true,
    'status' => 'caution',
    'selectedRisk' => 48,
    'selectedSafetyScore' => 52,
    'criticalSegment' => ['ratio' => .6, 'at' => '2026-09-17T13:10:00Z', 'risk' => 48, 'reasons' => ['crosswind']],
    'bestDeparture' => ['at' => '2026-09-17T11:30:00Z', 'risk' => 25, 'safetyScore' => 75],
    'improvementPoints' => 23,
    'sampleCount' => 12,
];
$routeDecision = meteonexa_copilot_decision_summary($activity, $confidence, ['available' => false], $route);
$check(($routeDecision['status'] ?? '') === 'caution', 'route risk can downgrade the decision');
$check((int)($routeDecision['score'] ?? 0) === 52, 'route safety score constrains overall score');
$check(($routeDecision['dominantRisk'] ?? '') === 'crosswind', 'critical route reason becomes dominant risk');
$check(($routeDecision['route']['bestDeparture'] ?? '') === '2026-09-17T11:30:00Z', 'best departure is surfaced');
$check(!array_key_exists('latitude', (array)($routeDecision['route'] ?? [])) && !array_key_exists('longitude', (array)($routeDecision['route'] ?? [])), 'decision route summary contains no precise coordinates');

$nowcastDecision = meteonexa_copilot_decision_summary($activity, $confidence, ['available' => true, 'impactProbability' => 78], []);
$check(($nowcastDecision['status'] ?? '') === 'avoid', 'high nowcast impact can deterministically override a good window');

$lowConfidence = meteonexa_copilot_decision_summary($activity, ['score' => 40], ['available' => false], []);
$check(($lowConfidence['status'] ?? '') === 'caution', 'low confidence prevents an unqualified good recommendation');

echo PHP_EOL . 'Copilot deterministic decision: ' . ($fail ? 'FAIL' : 'PASS') . PHP_EOL;
exit($fail ? 1 : 0);
