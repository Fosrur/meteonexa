<?php
declare(strict_types=1);
function meteonexa_ai_text(mixed $value, int $max = 160): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    return meteonexa_text_substr($text, 0, max(1, $max));
}

function meteonexa_ai_number(mixed $value, float $min, float $max, int $precision = 2): ?float
{
    if (!is_numeric($value)) return null;
    $number = (float)$value;
    if (!is_finite($number) || $number < $min || $number > $max) return null;
    return round($number, max(0, min(4, $precision)));
}

function meteonexa_ai_iso_time(mixed $value): ?string
{
    $text = meteonexa_ai_text($value, 40);
    if ($text === '') return null;
    $timestamp = strtotime($text);
    if ($timestamp === false) return null;
    return gmdate('c', $timestamp);
}

function meteonexa_ai_weather_row(array $row): array
{
    return array_filter([
        'time' => meteonexa_ai_iso_time($row['time'] ?? null),
        'condition' => meteonexa_ai_text($row['condition'] ?? '', 80),
        'temperatureC' => meteonexa_ai_number($row['temperatureC'] ?? null, -100, 70, 1),
        'feelsLikeC' => meteonexa_ai_number($row['feelsLikeC'] ?? null, -100, 80, 1),
        'rainProbability' => meteonexa_ai_number($row['rainProbability'] ?? null, 0, 100, 0),
        'precipitationMm' => meteonexa_ai_number($row['precipitationMm'] ?? null, 0, 1000, 2),
        'windKmh' => meteonexa_ai_number($row['windKmh'] ?? null, 0, 500, 1),
        'gustKmh' => meteonexa_ai_number($row['gustKmh'] ?? null, 0, 600, 1),
        'humidity' => meteonexa_ai_number($row['humidity'] ?? null, 0, 100, 0),
        'pressureHpa' => meteonexa_ai_number($row['pressureHpa'] ?? null, 800, 1200, 1),
        'visibilityKm' => meteonexa_ai_number($row['visibilityKm'] ?? null, 0, 1000, 1),
        'uvIndex' => meteonexa_ai_number($row['uvIndex'] ?? null, 0, 30, 1),
    ], static fn($value): bool => $value !== null && $value !== '');
}

function meteonexa_ai_sanitize_context(array $context): array
{
    $location = is_array($context['location'] ?? null) ? $context['location'] : [];
    $current = is_array($context['current'] ?? null) ? $context['current'] : [];
    $nowcast = is_array($context['nowcast'] ?? null) ? $context['nowcast'] : [];
    $changes = is_array($context['changes'] ?? null) ? $context['changes'] : [];
    $route = is_array($context['route'] ?? null) ? $context['route'] : [];
    $radarPrediction = is_array($context['radarPrediction'] ?? null) ? $context['radarPrediction'] : [];
    $ensemble = is_array($context['ensemble'] ?? null) ? $context['ensemble'] : [];
    $quality = is_array($context['intelligenceQuality'] ?? null) ? $context['intelligenceQuality'] : [];

    $severeEvents = [];
    foreach (array_slice(is_array($context['severeEvents'] ?? null) ? $context['severeEvents'] : [], 0, 4) as $row) {
        if (!is_array($row)) continue;
        $clean = array_filter([
            'type'=>meteonexa_ai_text($row['type'] ?? '',24),
            'severity'=>meteonexa_ai_text($row['severity'] ?? '',12),
            'confidence'=>meteonexa_ai_number($row['confidence'] ?? null,0,100,0),
            'title'=>meteonexa_ai_text($row['title'] ?? '',140),
            'body'=>meteonexa_ai_text($row['body'] ?? '',240),
            'startsAt'=>meteonexa_ai_iso_time($row['startsAt'] ?? null),
            'etaMinutes'=>meteonexa_ai_number($row['etaMinutes'] ?? null,0,1440,0),
        ], static fn($value): bool => $value !== null && $value !== '');
        if ($clean !== []) $severeEvents[] = $clean;
    }

    $impact = [];
    foreach (array_slice(is_array($context['impact'] ?? null) ? $context['impact'] : [], 0, 6) as $row) {
        if (!is_array($row)) continue;
        $clean = array_filter([
            'activity' => meteonexa_ai_text($row['activity'] ?? '', 80),
            'score' => meteonexa_ai_number($row['score'] ?? null, 0, 100, 0),
            'bestStart' => meteonexa_ai_iso_time($row['bestStart'] ?? null),
            'bestEnd' => meteonexa_ai_iso_time($row['bestEnd'] ?? null),
            'reason' => meteonexa_ai_text($row['reason'] ?? '', 160),
        ], static fn($value): bool => $value !== null && $value !== '');
        if ($clean !== []) $impact[] = $clean;
    }

    $forecast = [];
    foreach (array_slice(is_array($context['forecast'] ?? null) ? $context['forecast'] : [], 0, 7) as $row) {
        if (!is_array($row)) continue;
        $clean = array_filter([
            'date' => meteonexa_ai_text($row['date'] ?? '', 20),
            'condition' => meteonexa_ai_text($row['condition'] ?? '', 80),
            'minC' => meteonexa_ai_number($row['minC'] ?? null, -100, 70, 1),
            'maxC' => meteonexa_ai_number($row['maxC'] ?? null, -100, 70, 1),
            'rainProbability' => meteonexa_ai_number($row['rainProbability'] ?? null, 0, 100, 0),
            'windGustKmh' => meteonexa_ai_number($row['windGustKmh'] ?? null, 0, 600, 1),
        ], static fn($value): bool => $value !== null && $value !== '');
        if ($clean !== []) $forecast[] = $clean;
    }

    $hourly = [];
    foreach (array_slice(is_array($context['hourlyForecast'] ?? null) ? $context['hourlyForecast'] : [], 0, 24) as $row) {
        if (!is_array($row)) continue;
        $clean = meteonexa_ai_weather_row($row);
        if ($clean !== []) $hourly[] = $clean;
    }

    $qualityConsensus = is_array($quality['consensus'] ?? null) ? $quality['consensus'] : [];
    $qualitySkill = is_array($quality['historicalSkill'] ?? null) ? $quality['historicalSkill'] : [];
    $qualityChange = is_array($quality['forecastChange'] ?? null) ? $quality['forecastChange'] : [];
    $qualityPrevious = is_array($quality['previousRuns'] ?? null) ? $quality['previousRuns'] : [];
    $qualityCell = is_array($quality['cellTracking'] ?? null) ? $quality['cellTracking'] : [];
    $qualitySources = [];
    foreach (array_slice(is_array($quality['sourceFreshness'] ?? null) ? $quality['sourceFreshness'] : [], 0, 10) as $row) {
        if (!is_array($row)) continue;
        $qualitySources[] = array_filter([
            'source'=>meteonexa_ai_text($row['source'] ?? '',40),
            'available'=>array_key_exists('available',$row)?(bool)$row['available']:null,
            'ageMinutes'=>meteonexa_ai_number($row['ageMinutes'] ?? null,0,100000,0),
            'kind'=>meteonexa_ai_text($row['kind'] ?? '',40),
        ],static fn($value):bool=>$value!==null&&$value!=='');
    }
    $qualityExplain = [];
    foreach (array_slice(is_array($quality['explainability'] ?? null) ? $quality['explainability'] : [], 0, 10) as $row) {
        if (!is_array($row)) continue;
        $qualityExplain[] = array_filter([
            'source'=>meteonexa_ai_text($row['source'] ?? '',40),
            'contribution'=>meteonexa_ai_number($row['contribution'] ?? null,0,100,0),
            'status'=>meteonexa_ai_text($row['status'] ?? '',30),
        ],static fn($value):bool=>$value!==null&&$value!=='');
    }
    $qualityDecisions = [];
    foreach (array_slice(is_array($quality['decisionWindows'] ?? null) ? $quality['decisionWindows'] : [], 0, 11) as $row) {
        if (!is_array($row)) continue;
        $qualityDecisions[] = array_filter([
            'activity'=>meteonexa_ai_text($row['activity'] ?? '',40),
            'score'=>meteonexa_ai_number($row['score'] ?? null,0,100,0),
            'startsAt'=>meteonexa_ai_iso_time($row['startsAt'] ?? null),
            'endsAt'=>meteonexa_ai_iso_time($row['endsAt'] ?? null),
            'basis'=>meteonexa_ai_text($row['basis'] ?? '',40),
        ],static fn($value):bool=>$value!==null&&$value!=='');
    }

    return array_filter([
        'generatedAt' => meteonexa_ai_iso_time($context['generatedAt'] ?? null),
        'location' => array_filter([
            'name' => meteonexa_ai_text($location['name'] ?? '', 120),
            'timezone' => meteonexa_ai_text($location['timezone'] ?? '', 80),
        ], static fn($value): bool => $value !== ''),
        'current' => meteonexa_ai_weather_row($current),
        'hourlyForecast' => $hourly,
        'forecast' => $forecast,
        'nowcast' => array_filter([
            'rainingNow' => array_key_exists('rainingNow', $nowcast) ? (bool)$nowcast['rainingNow'] : null,
            'expectedStart' => meteonexa_ai_iso_time($nowcast['expectedStart'] ?? null),
            'expectedEnd' => meteonexa_ai_iso_time($nowcast['expectedEnd'] ?? null),
            'totalMm' => meteonexa_ai_number($nowcast['totalMm'] ?? null, 0, 1000, 2),
            'reliability' => meteonexa_ai_number($nowcast['reliability'] ?? null, 0, 100, 0),
        ], static fn($value): bool => $value !== null && $value !== ''),
        'changes' => array_filter([
            'comparedAt' => meteonexa_ai_iso_time($changes['comparedAt'] ?? null),
            'rainTimingShiftMinutes' => meteonexa_ai_number($changes['rainTimingShiftMinutes'] ?? null, -10080, 10080, 0),
            'rainAccumulationDeltaMm' => meteonexa_ai_number($changes['rainAccumulationDeltaMm'] ?? null, -1000, 1000, 2),
            'maxGustDeltaKmh' => meteonexa_ai_number($changes['maxGustDeltaKmh'] ?? null, -600, 600, 1),
            'maxTemperatureDeltaC' => meteonexa_ai_number($changes['maxTemperatureDeltaC'] ?? null, -100, 100, 1),
            'stabilityScore' => meteonexa_ai_number($changes['stabilityScore'] ?? null, 0, 100, 0),
        ], static fn($value): bool => $value !== null && $value !== ''),
        'route' => array_filter([
            'origin' => meteonexa_ai_text($route['origin'] ?? '', 120),
            'destination' => meteonexa_ai_text($route['destination'] ?? '', 120),
            'distanceKm' => meteonexa_ai_number($route['distanceKm'] ?? null, 0, 50000, 1),
            'durationMinutes' => meteonexa_ai_number($route['durationMinutes'] ?? null, 0, 100000, 0),
            'riskScore' => meteonexa_ai_number($route['riskScore'] ?? null, 0, 100, 0),
            'recommendedDeparture' => meteonexa_ai_iso_time($route['recommendedDeparture'] ?? null),
        ], static fn($value): bool => $value !== null && $value !== ''),
        'radarPrediction' => array_filter([
            'rainingNow' => array_key_exists('rainingNow', $radarPrediction) ? (bool)$radarPrediction['rainingNow'] : null,
            'etaMinutes' => meteonexa_ai_number($radarPrediction['etaMinutes'] ?? null, 0, 180, 0),
            'exitMinutes' => meteonexa_ai_number($radarPrediction['exitMinutes'] ?? null, 0, 180, 0),
            'direction' => meteonexa_ai_text($radarPrediction['direction'] ?? '', 20),
            'speedKmh' => meteonexa_ai_number($radarPrediction['speedKmh'] ?? null, 0, 300, 0),
            'confidence' => meteonexa_ai_number($radarPrediction['confidence'] ?? null, 0, 100, 0),
        ], static fn($value): bool => $value !== null && $value !== ''),
        'ensemble' => array_filter([
            'calibrated' => array_key_exists('calibrated', $ensemble) ? (bool)$ensemble['calibrated'] : null,
            'dominantModel' => meteonexa_ai_text($ensemble['dominantModel'] ?? '', 40),
            'sampleCount' => meteonexa_ai_number($ensemble['sampleCount'] ?? null, 0, 100000, 0),
            'temperatureMax' => meteonexa_ai_number($ensemble['temperatureMax'] ?? null, -100, 70, 1),
            'precipitationTotal' => meteonexa_ai_number($ensemble['precipitationTotal'] ?? null, 0, 1000, 2),
            'windMax' => meteonexa_ai_number($ensemble['windMax'] ?? null, 0, 600, 1),
        ], static fn($value): bool => $value !== null && $value !== ''),
        'severeEvents' => $severeEvents,
        'impact' => $impact,
        'intelligenceQuality' => array_filter([
            'consensus'=>array_filter([
                'type'=>meteonexa_ai_text($qualityConsensus['type'] ?? '',20),
                'votes'=>meteonexa_ai_number($qualityConsensus['votes'] ?? null,0,12,0),
                'available'=>meteonexa_ai_number($qualityConsensus['available'] ?? null,0,12,0),
                'agreementPct'=>meteonexa_ai_number($qualityConsensus['agreementPct'] ?? null,0,100,0),
                'windowAgreementPct'=>meteonexa_ai_number($qualityConsensus['windowAgreementPct'] ?? null,0,100,0),
                'startsAt'=>meteonexa_ai_iso_time($qualityConsensus['startsAt'] ?? null),
                'endsAt'=>meteonexa_ai_iso_time($qualityConsensus['endsAt'] ?? null),
                'agreeingModels'=>array_values(array_slice(array_filter(array_map(static fn($v)=>meteonexa_ai_text($v,30),(array)($qualityConsensus['agreeingModels'] ?? []))),0,12)),
                'outliers'=>array_values(array_slice(array_filter(array_map(static fn($v)=>meteonexa_ai_text($v,30),(array)($qualityConsensus['outliers'] ?? []))),0,12)),
            ],static fn($value):bool=>$value!==null&&$value!==''&&$value!==[]),
            'historicalSkill'=>array_filter([
                'available'=>array_key_exists('available',$qualitySkill)?(bool)$qualitySkill['available']:null,
                'verifiedSamples'=>meteonexa_ai_number($qualitySkill['verifiedSamples'] ?? null,0,1000000,0),
                'brier'=>meteonexa_ai_number($qualitySkill['brier'] ?? null,0,1,4),
                'leadHours'=>array_values(array_slice(array_map('intval',(array)($qualitySkill['leadHours'] ?? [])),0,6)),
            ],static fn($value):bool=>$value!==null&&$value!==''&&$value!==[]),
            'forecastChange'=>array_filter([
                'changed'=>array_key_exists('changed',$qualityChange)?(bool)$qualityChange['changed']:null,
                'timeShiftMinutes'=>meteonexa_ai_number($qualityChange['timeShiftMinutes'] ?? null,-10080,10080,0),
                'rainProbabilityDelta'=>meteonexa_ai_number($qualityChange['rainProbabilityDelta'] ?? null,-100,100,0),
                'agreementDelta'=>meteonexa_ai_number($qualityChange['agreementDelta'] ?? null,-100,100,0),
                'stabilityPct'=>meteonexa_ai_number($qualityChange['stabilityPct'] ?? null,0,100,0),
            ],static fn($value):bool=>$value!==null&&$value!==''),
            'previousRuns'=>array_filter([
                'changed'=>array_key_exists('changed',$qualityPrevious)?(bool)$qualityPrevious['changed']:null,
                'timeShiftMinutes'=>meteonexa_ai_number($qualityPrevious['timeShiftMinutes'] ?? null,-10080,10080,0),
                'rainTotalDelta'=>meteonexa_ai_number($qualityPrevious['rainTotalDelta'] ?? null,-1000,1000,2),
                'stormHoursDelta'=>meteonexa_ai_number($qualityPrevious['stormHoursDelta'] ?? null,-72,72,0),
                'temperatureMaxDelta'=>meteonexa_ai_number($qualityPrevious['temperatureMaxDelta'] ?? null,-100,100,1),
                'windGustMaxDelta'=>meteonexa_ai_number($qualityPrevious['windGustMaxDelta'] ?? null,-600,600,1),
            ],static fn($value):bool=>$value!==null&&$value!==''),
            'sourceFreshness'=>$qualitySources,
            'explainability'=>$qualityExplain,
            'decisionWindows'=>$qualityDecisions,
            'cellTracking'=>array_filter([
                'direction'=>meteonexa_ai_text($qualityCell['direction'] ?? '',10),
                'growthPct'=>meteonexa_ai_number($qualityCell['growthPct'] ?? null,-1000,1000,1),
                'stage'=>meteonexa_ai_text($qualityCell['stage'] ?? '',20),
                'impactProbability'=>meteonexa_ai_number($qualityCell['impactProbability'] ?? null,0,100,0),
                'etaMinutes'=>meteonexa_ai_number($qualityCell['etaMinutes'] ?? null,0,180,0),
                'trackConfidence'=>meteonexa_ai_number($qualityCell['trackConfidence'] ?? null,0,100,0),
                'ageMinutes'=>meteonexa_ai_number($qualityCell['ageMinutes'] ?? null,0,100000,0),
                'lightningFused'=>array_key_exists('lightningFused',$qualityCell)?(bool)$qualityCell['lightningFused']:null,
            ],static fn($value):bool=>$value!==null&&$value!==''),
        ],static fn($value):bool=>$value!==null&&$value!==[]&&$value!==''),
    ], static fn($value): bool => $value !== null && $value !== [] && $value !== '');
}

function meteonexa_ai_provider(array $ai): array
{
    $provider = strtolower(trim((string)($ai['provider'] ?? 'openrouter')));
    if ($provider === 'groq') {
        return [
            'name' => 'groq',
            'url' => 'https://api.groq.com/openai/v1/chat/completions',
            'key' => trim((string)($ai['groq_api_key'] ?? '')),
            'model' => trim((string)($ai['groq_model'] ?? 'openai/gpt-oss-20b')) ?: 'openai/gpt-oss-20b',
            'site_url' => trim((string)($ai['site_url'] ?? '')),
            'site_name' => trim((string)($ai['site_name'] ?? 'MeteoNexa')) ?: 'MeteoNexa',
        ];
    }

    
    
    $configuredModel = trim((string)($ai['openrouter_model'] ?? $ai['model'] ?? 'nvidia/nemotron-3-super-120b-a12b:free')) ?: 'nvidia/nemotron-3-super-120b-a12b:free';
    if ((bool)($ai['openrouter_free_only'] ?? true)) $configuredModel = 'nvidia/nemotron-3-super-120b-a12b:free';
    return [
        'name' => 'openrouter',
        'url' => 'https://openrouter.ai/api/v1/chat/completions',
        'key' => trim((string)($ai['openrouter_api_key'] ?? $ai['api_key'] ?? '')),
        'model' => $configuredModel,
        'site_url' => trim((string)($ai['site_url'] ?? '')),
        'site_name' => trim((string)($ai['site_name'] ?? 'MeteoNexa')) ?: 'MeteoNexa',
    ];
}

function meteonexa_ai_extract_answer(mixed $content): string
{
    if (is_string($content)) return trim($content);
    if (!is_array($content)) return '';
    $parts = [];
    foreach ($content as $part) {
        if (is_string($part)) {
            $parts[] = $part;
            continue;
        }
        if (!is_array($part)) continue;
        $text = $part['text'] ?? $part['content'] ?? '';
        if (is_string($text) && trim($text) !== '') $parts[] = $text;
    }
    return trim(implode("\n", $parts));
}

function meteonexa_ai_plain_output(string $answer): string
{
    $text = trim(strip_tags($answer));
    if ($text === '') return '';
    
    
    
    $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text) ?? $text;
    $text = preg_replace('/(^|\R)\s{0,3}#{1,6}\s+/u', '$1', $text) ?? $text;
    $text = preg_replace('/(^|\R)\s*>\s?/u', '$1', $text) ?? $text;
    $text = preg_replace('/(^|\R)\s*[-+*]\s+/u', '$1', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $text) ?? $text;
    $text = preg_replace('/__([^_]+)__/u', '$1', $text) ?? $text;
    $text = preg_replace('/(?<!\w)\*([^*\r\n]+)\*(?!\w)/u', '$1', $text) ?? $text;
    $text = preg_replace('/(?<!\w)_([^_\r\n]+)_(?!\w)/u', '$1', $text) ?? $text;
    $text = str_replace(['```','`','**','__'], '', $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function meteonexa_ai_answer_is_internal_marker(string $answer): bool
{
    $clean = trim(preg_replace('/\s+/u', ' ', $answer) ?? $answer);
    if ($clean === '') return true;
    $lower = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
    if (preg_match('/^(user|assistant|system)?\s*safety\s*:\s*(safe|unsafe|allowed|blocked)\.?$/i', $clean) === 1) return true;
    if (preg_match('/^(safe|unsafe|allowed|blocked)\.?$/i', $clean) === 1) return true;
    if (str_starts_with($lower, 'safety classification:')) return true;
    return false;
}

function meteonexa_ai_briefing_similarity(string $previous, string $current): float
{
    $normalize = static function (string $text): array {
        $text = preg_replace('/[*_`#>\-–—•]+/u', ' ', $text) ?? $text;
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        preg_match_all('/[\p{L}\p{N}°%]+/u', $text, $matches);
        $tokens = array_values(array_filter($matches[0] ?? [], static fn(string $token): bool => meteonexa_text_length($token) >= 3));
        $pairs = [];
        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $pairs[$tokens[$i] . ' ' . $tokens[$i + 1]] = true;
        }
        return array_keys($pairs);
    };

    if (meteonexa_text_length($previous) < 80 || meteonexa_text_length($current) < 80) return 0.0;
    $a = $normalize($previous);
    $b = $normalize($current);
    if (count($a) < 8 || count($b) < 8) return 0.0;
    $intersection = count(array_intersect($a, $b));
    $denominator = max(1, min(count($a), count($b)));
    return $intersection / $denominator;
}
