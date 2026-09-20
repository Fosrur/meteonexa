<?php
declare(strict_types=1);

/**
 * Build deterministic daylight windows from the same forecast evidence already
 * used by Intelligence. This is intentionally non-AI: cloud cover, rain risk,
 * solar radiation, sunshine duration and current satellite attenuation are
 * combined into a bounded score that can be explained to the user.
 */
function meteonexa_sun_cloud_window(array $weather, array $confidenceV2 = [], array $satellite = []) : array {
    $h = (array)($weather['hourly'] ?? []);
    $times = array_values((array)($h['time'] ?? []));
    if (!$times) return ['available'=>false, 'reason'=>'no_hourly_data', 'windows'=>[]];

    $cloud = (array)($h['cloud_cover'] ?? []);
    $rain = (array)($h['precipitation_probability'] ?? []);
    $radiation = (array)($h['shortwave_radiation'] ?? []);
    $sunshine = (array)($h['sunshine_duration'] ?? []);
    $daylight = (array)($h['is_day'] ?? []);
    $confidenceRows = array_values((array)($confidenceV2['timeline'] ?? []));
    $baseConfidence = max(0, min(100, (int)round((float)($confidenceV2['score'] ?? 60))));
    $now = time() - 1800;
    $rows = [];

    $confidenceFor = static function(string $time) use ($confidenceRows, $baseConfidence) : int {
        $target = strtotime($time);
        if ($target === false || !$confidenceRows) return $baseConfidence;
        $best = null; $distance = PHP_INT_MAX;
        foreach ($confidenceRows as $row) {
            $ts = strtotime((string)($row['time'] ?? ''));
            if ($ts === false) continue;
            $d = abs($ts - $target);
            if ($d < $distance) { $distance = $d; $best = $row; }
        }
        return max(0, min(100, (int)round((float)($best['score'] ?? $baseConfidence))));
    };

    foreach ($times as $i => $time) {
        $ts = strtotime((string)$time);
        if ($ts === false || $ts < $now) continue;
        if (count($rows) >= 48) break;
        $isDay = array_key_exists($i, $daylight) ? ((int)$daylight[$i] === 1) : ((float)($radiation[$i] ?? 0) > 10);
        $c = max(0, min(100, (float)($cloud[$i] ?? 100)));
        $r = max(0, min(100, (float)($rain[$i] ?? 0)));
        $rad = max(0, (float)($radiation[$i] ?? 0));
        $sunSec = max(0, min(3600, (float)($sunshine[$i] ?? 0)));
        $open = 100 - $c;
        $solar = min(100, ($rad / 700) * 100);
        $sunPct = ($sunSec / 3600) * 100;
        $score = $isDay ? round(($open * .45) + ($solar * .30) + ($sunPct * .15) + ((100 - $r) * .10)) : 0;
        $confidence = $confidenceFor((string)$time);
        // Current independent satellite evidence is used only as a conservative
        // confidence modifier; it never overwrites the forecast window itself.
        if (!empty($satellite['available']) && isset($satellite['cloudAttenuationPct'])) {
            $attenuation = max(0, min(100, (float)$satellite['cloudAttenuationPct']));
            $satelliteConfidence = 100 - $attenuation;
            $confidence = (int)round(($confidence * .85) + ($satelliteConfidence * .15));
        }
        $candidate = $isDay && $c <= 60 && $r <= 35 && $rad >= 70 && $score >= 52;
        $rows[] = [
            'time'=>(string)$time,
            'cloudPct'=>(int)round($c),
            'rainProbabilityPct'=>(int)round($r),
            'shortwaveWm2'=>(int)round($rad),
            'sunshineMinutes'=>(int)round($sunSec / 60),
            'score'=>(int)$score,
            'confidence'=>(int)max(0, min(100, $confidence)),
            'isDay'=>$isDay,
            'candidate'=>$candidate,
        ];
    }

    $groups = []; $current = [];
    foreach ($rows as $row) {
        if (!$row['candidate']) {
            if ($current) { $groups[] = $current; $current = []; }
            continue;
        }
        if ($current) {
            $prev = strtotime((string)$current[count($current)-1]['time']);
            $next = strtotime((string)$row['time']);
            if ($prev === false || $next === false || $next - $prev > 5400) { $groups[] = $current; $current = []; }
        }
        $current[] = $row;
    }
    if ($current) $groups[] = $current;

    $windows = [];
    foreach ($groups as $group) {
        $start = $group[0]; $last = $group[count($group)-1];
        $endTs = strtotime((string)$last['time']);
        $windows[] = [
            'startsAt'=>$start['time'],
            'endsAt'=>$endTs === false ? $last['time'] : gmdate('c', $endTs + 3600),
            'hours'=>count($group),
            'score'=>(int)round(array_sum(array_column($group, 'score')) / max(1, count($group))),
            'confidence'=>(int)round(array_sum(array_column($group, 'confidence')) / max(1, count($group))),
            'cloudPct'=>(int)round(array_sum(array_column($group, 'cloudPct')) / max(1, count($group))),
            'rainProbabilityPct'=>(int)round(array_sum(array_column($group, 'rainProbabilityPct')) / max(1, count($group))),
            'shortwaveWm2'=>(int)round(array_sum(array_column($group, 'shortwaveWm2')) / max(1, count($group))),
            'sunshineMinutes'=>(int)round(array_sum(array_column($group, 'sunshineMinutes')) / max(1, count($group))),
        ];
    }
    usort($windows, static fn(array $a, array $b) : int => ($b['score'] <=> $a['score']) ?: ($b['confidence'] <=> $a['confidence']));
    $windows = array_slice($windows, 0, 5);

    return [
        'available'=>!empty($rows),
        'bestWindow'=>$windows[0] ?? null,
        'windows'=>$windows,
        'timeline'=>$rows,
        'satelliteAttenuationPct'=>isset($satellite['cloudAttenuationPct']) ? (int)round((float)$satellite['cloudAttenuationPct']) : null,
        'method'=>'deterministic-cloud-rain-radiation-v1',
        'generatedAt'=>gmdate('c'),
    ];
}
