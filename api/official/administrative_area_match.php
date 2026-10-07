<?php
declare(strict_types=1);

function meteonexa_official_administrative_token(string $value): string {
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function meteonexa_official_area_tokens(string $area): array {
    $parts = preg_split('/[;,|]+/u', $area) ?: [];
    $tokens = [];
    foreach ($parts as $part) {
        $token = meteonexa_official_administrative_token((string)$part);
        if ($token !== '') $tokens[] = $token;
    }
    if ($tokens === []) {
        $token = meteonexa_official_administrative_token($area);
        if ($token !== '') $tokens[] = $token;
    }
    return array_values(array_unique($tokens));
}

function meteonexa_official_apply_position_area_match(array $alerts, string $locationName, string $admin1): array {
    if (!empty($alerts['relevant'])) return $alerts;

    $location = meteonexa_official_administrative_token(trim(explode(',', $locationName, 2)[0]));
    $admin = meteonexa_official_administrative_token($admin1);
    if ($location === '' && $admin === '') return $alerts;

    $relevant = [];
    $regional = [];
    foreach ((array)($alerts['regionalAdvisories'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $areaTokens = meteonexa_official_area_tokens((string)($row['area'] ?? ''));
        $matched = '';
        if ($admin !== '' && in_array($admin, $areaTokens, true)) {
            $matched = trim($admin1);
        } elseif ($location !== '' && in_array($location, $areaTokens, true)) {
            $matched = trim(explode(',', $locationName, 2)[0]);
        }

        if ($matched === '') {
            $regional[] = $row;
            continue;
        }

        $row['matchScope'] = 'administrative-area';
        $row['administrativeMatch'] = true;
        $row['matchedAdministrativeArea'] = $matched;
        $row['positionMatched'] = true;
        $row['positionMatchSource'] = 'current-location';
        $relevant[] = $row;
    }

    if ($relevant === []) return $alerts;

    $alerts['relevant'] = $relevant;
    $alerts['regionalAdvisories'] = $regional;
    $alerts['matchedByAreaText'] = true;
    $alerts['administrativeAreaMatch'] = true;
    $alerts['mode'] = 'atom-administrative-area';
    return $alerts;
}
