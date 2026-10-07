<?php
declare(strict_types=1);

function meteonexa_official_place_token(string $value): string {
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = strtr($value, [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','’'=>' ','\''=>' '
    ]);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function meteonexa_official_municipality_severity(array $data): string {
    $today = is_array($data['oggi'] ?? null) ? (array)$data['oggi'] : [];
    $alert = is_array($today['allerta'] ?? null) ? (array)$today['allerta'] : [];
    $color = meteonexa_official_place_token((string)($alert['colore'] ?? ''));
    return match ($color) {
        'rosso', 'rossa', 'red' => 'red',
        'arancione', 'orange' => 'orange',
        'giallo', 'gialla', 'yellow' => 'yellow',
        'verde', 'green' => 'green',
        default => ''
    };
}

function meteonexa_official_municipality_detail_severity(string $description, string $fallback = ''): string {
    $text = meteonexa_official_place_token($description);
    if ($text === '' || str_contains($text, 'nessuna allerta') || str_contains($text, 'assenza di fenomeni')) return 'green';
    if (str_contains($text, 'allerta rossa') || preg_match('/\brosso\b|\brossa\b|\bred\b/', $text)) return 'red';
    if (str_contains($text, 'allerta arancione') || preg_match('/\barancione\b|\borange\b/', $text)) return 'orange';
    if (str_contains($text, 'allerta gialla') || preg_match('/\bgiallo\b|\bgialla\b|\byellow\b/', $text)) return 'yellow';
    return in_array($fallback, ['yellow','orange','red'], true) ? $fallback : '';
}

function meteonexa_official_municipality_risk_severities(array $data): array {
    $today = is_array($data['oggi'] ?? null) ? (array)$data['oggi'] : [];
    $details = is_array($today['dettagli'] ?? null) ? (array)$today['dettagli'] : [];
    $fallback = meteonexa_official_municipality_severity($data);
    $out = [];
    foreach ($details as $risk=>$description) {
        $key = meteonexa_official_place_token((string)$risk);
        if ($key === '') continue;
        $severity = meteonexa_official_municipality_detail_severity((string)$description, $fallback);
        if ($severity !== '' && $severity !== 'green') $out[$key] = $severity;
    }
    return $out;
}

function meteonexa_official_municipality_risks(array $data): array {
    return array_keys(meteonexa_official_municipality_risk_severities($data));
}

function meteonexa_official_municipality_fetch(string $locationName): ?array {
    $municipality = trim(explode(',', $locationName, 2)[0]);
    if ($municipality === '') return null;
    $cacheDir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
    $cacheFile = $cacheDir . '/municipality-warning-' . hash('sha256', meteonexa_official_place_token($municipality)) . '.json';
    $cached = null;
    $age = null;
    if (is_file($cacheFile)) {
        $age = max(0, time() - (int)filemtime($cacheFile));
        $decoded = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($decoded)) $cached = $decoded;
        if ($cached !== null && $age < 180) {
            $cached['_meteonexaMunicipalityCache'] = ['stale'=>false,'ageSeconds'=>$age];
            return $cached;
        }
    }
    try {
        $url = 'https://allertameteo.app/api/alert/' . rawurlencode($municipality);
        $fresh = meteonexa_http_json($url, [
            'timeout'=>6,
            'headers'=>['Accept: application/json'],
            'max_bytes'=>350000
        ]);
        if (is_array($fresh) && ($fresh['success'] ?? false) === true && is_array($fresh['data'] ?? null)) {
            @file_put_contents($cacheFile, json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            $fresh['_meteonexaMunicipalityCache'] = ['stale'=>false,'ageSeconds'=>0];
            return $fresh;
        }
    } catch (Throwable $ignored) {
    }
    if ($cached !== null && $age !== null && $age < 21600) {
        $cached['_meteonexaMunicipalityCache'] = ['stale'=>true,'ageSeconds'=>$age];
        return $cached;
    }
    return null;
}

function meteonexa_official_municipality_event_matches_risk(array $row, string $risk): bool {
    $event = meteonexa_official_place_token(
        (string)($row['event'] ?? '') . ' ' .
        (string)($row['title'] ?? '') . ' ' .
        (string)($row['summary'] ?? '')
    );
    $risk = meteonexa_official_place_token($risk);
    $patterns = [
        'temporali'=>['thunder','storm','tempor','orage','gewitter','tormenta'],
        'idrogeologico'=>['rain','flood','piogg','precipit','pluie','regen','lluvia','hydro'],
        'idraulico'=>['rain','flood','piogg','precipit','pluie','regen','lluvia','hydro'],
        'vento'=>['wind','vento','vent','windstorm'],
        'neve'=>['snow','neve','schnee','nieve'],
        'ghiaccio'=>['ice','ghiacc','eis','hielo'],
        'mareggiate'=>['coastal','sea','wave','mare','onda','costa'],
        'temperature estreme'=>['heat','cold','caldo','freddo','temperature'],
    ];
    foreach ($patterns[$risk] ?? [$risk] as $needle) {
        if ($needle !== '' && str_contains($event, $needle)) return true;
    }
    return false;
}

function meteonexa_official_municipality_row_score(array $row, array $risks): int {
    $score = 0;
    $state = strtolower((string)($row['windowState'] ?? ''));
    if ($state === 'active') $score += 30;
    elseif ($state === 'upcoming') $score += 20;
    elseif ($state === 'expired' || $state === 'inactive') $score -= 100;
    $rank = ['green'=>0,'yellow'=>1,'orange'=>2,'red'=>3];
    $score += 4 * ($rank[strtolower((string)($row['severity'] ?? 'green'))] ?? 0);
    foreach ($risks as $risk) if (meteonexa_official_municipality_event_matches_risk($row, (string)$risk)) $score += 40;
    if (($row['geospatialMatch'] ?? false) === true) $score += 8;
    return $score;
}

function meteonexa_official_municipality_candidate_rows(array $alerts): array {
    $rows = [];
    $seen = [];
    foreach (['relevant','regionalAdvisories'] as $bucket) {
        foreach ((array)($alerts[$bucket] ?? []) as $row) {
            if (!is_array($row)) continue;
            if (function_exists('meteonexa_official_window_state')) $row = meteonexa_official_window_state($row);
            elseif (function_exists('meteonexa_official_enrich_window')) $row = meteonexa_official_enrich_window($row);
            $key = (string)($row['hubKey'] ?? $row['id'] ?? $row['identifier'] ?? hash('sha256', json_encode($row)));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $rows[] = $row;
        }
    }
    return $rows;
}

function meteonexa_official_apply_municipality_data(array $alerts, array $data, string $municipality, string $admin1 = '', array $cacheMeta = []): array {
    $providerMunicipality = trim((string)($data['comune'] ?? ''));
    $providerRegion = trim((string)($data['regione'] ?? ''));
    if ($providerMunicipality === '' || meteonexa_official_place_token($providerMunicipality) !== meteonexa_official_place_token($municipality)) return $alerts;
    if ($admin1 !== '' && $providerRegion !== '' && meteonexa_official_place_token($providerRegion) !== meteonexa_official_place_token($admin1)) return $alerts;

    $severity = meteonexa_official_municipality_severity($data);
    $stale = !empty($cacheMeta['stale']);
    $alerts['municipalityVerification'] = [
        'verified'=>true,
        'municipality'=>$providerMunicipality,
        'region'=>$providerRegion,
        'zone'=>(string)($data['zona'] ?? ''),
        'severity'=>$severity,
        'source'=>'Allerta Meteo Italia / Protezione Civile data',
        'stale'=>$stale,
        'cacheAgeSeconds'=>(int)($cacheMeta['ageSeconds'] ?? 0)
    ];
    if ($severity === '') return $alerts;
    if ($stale && !empty($alerts['relevant'])) return $alerts;

    if ($severity === 'green') {
        if (!$stale) {
            $alerts['regionalAdvisories'] = meteonexa_official_municipality_candidate_rows($alerts);
            $alerts['relevant'] = [];
            $alerts['municipalityMatch'] = true;
            $alerts['territorialSeverityVerified'] = true;
            $alerts['mode'] = 'municipality-external-verification';
        }
        return $alerts;
    }

    $riskSeverities = meteonexa_official_municipality_risk_severities($data);
    $risks = array_keys($riskSeverities);
    $candidates = meteonexa_official_municipality_candidate_rows($alerts);
    $selected = [];
    $selectedKeys = [];

    foreach ($risks as $risk) {
        $matching = array_values(array_filter($candidates, static fn(array $row): bool => meteonexa_official_municipality_event_matches_risk($row, $risk)));
        usort($matching, static fn(array $a, array $b): int => meteonexa_official_municipality_row_score($b, [$risk]) <=> meteonexa_official_municipality_row_score($a, [$risk]));
        if (!$matching) continue;
        $row = $matching[0];
        $key = (string)($row['hubKey'] ?? $row['id'] ?? $row['identifier'] ?? hash('sha256', json_encode($row)));
        if (isset($selectedKeys[$key])) continue;
        $selectedKeys[$key] = true;
        $row['providerSeverity'] = (string)($row['severity'] ?? '');
        if (!$stale) $row['severity'] = $riskSeverities[$risk] ?? $severity;
        $row['territorialRisk'] = $risk;
        $selected[] = $row;
    }

    if (!$selected && $candidates) {
        usort($candidates, static fn(array $a, array $b): int => meteonexa_official_municipality_row_score($b, $risks) <=> meteonexa_official_municipality_row_score($a, $risks));
        $row = $candidates[0];
        $row['providerSeverity'] = (string)($row['severity'] ?? '');
        if (!$stale) $row['severity'] = $severity;
        $selected[] = $row;
    }

    if (!$selected) {
        $selected[] = [
            'id'=>'municipality-' . substr(hash('sha256', meteonexa_official_place_token($providerMunicipality) . '|' . date('Y-m-d')), 0, 32),
            'identifier'=>'municipality-' . meteonexa_official_place_token($providerMunicipality),
            'title'=>'Allerta Protezione Civile - ' . $providerMunicipality,
            'summary'=>(string)($data['oggi']['allerta']['descrizione'] ?? ''),
            'event'=>$risks ? implode(', ', $risks) : 'official warning',
            'area'=>(string)($data['zona'] ?? $providerMunicipality),
            'severity'=>$severity,
            'certainty'=>'Likely',
            'urgency'=>'Expected',
            'sender'=>'',
            'sentAt'=>null,
            'updatedAt'=>null,
            'startsAt'=>null,
            'endsAt'=>null,
            'messageType'=>'Alert',
            'status'=>'Actual',
            'references'=>[],
            'instruction'=>'',
            'geometry'=>null,
            'geocodes'=>[],
            'source'=>'Allerta Meteo Italia',
            'authority'=>'Protezione Civile data',
            'official'=>true,
            'origin'=>'official-warning-authority',
            'forecastAuthoritySeparated'=>true,
            'geospatialMatch'=>false
        ];
    }

    foreach ($selected as &$row) {
        $row['matchScope'] = 'municipality-external';
        $row['municipalityMatch'] = true;
        $row['matchedMunicipality'] = $providerMunicipality;
        $row['matchedAdministrativeArea'] = (string)($data['zona'] ?? '');
        $row['positionMatched'] = true;
        $row['positionMatchSource'] = 'current-location-municipality';
        $row['territorialVerificationSource'] = 'Allerta Meteo Italia / Protezione Civile data';
        $row['territorialRisks'] = $risks;
        $row['territorialSeverityVerified'] = !$stale;
        $row['municipalityProviderFresh'] = !$stale;
    }
    unset($row);

    $alerts['relevant'] = $selected;
    $alerts['administrativeAreaMatch'] = false;
    $alerts['municipalityMatch'] = true;
    $alerts['matchedByAreaText'] = false;
    $alerts['territorialSeverityVerified'] = !$stale;
    $alerts['mode'] = 'municipality-external-verification';
    return $alerts;
}

function meteonexa_official_apply_position_area_match(array $alerts, string $locationName, string $admin1): array {
    $municipality = trim(explode(',', $locationName, 2)[0]);
    if ($municipality === '') return $alerts;
    $verification = meteonexa_official_municipality_fetch($municipality);
    if (!is_array($verification) || !is_array($verification['data'] ?? null)) return $alerts;
    $cacheMeta = is_array($verification['_meteonexaMunicipalityCache'] ?? null) ? (array)$verification['_meteonexaMunicipalityCache'] : [];
    return meteonexa_official_apply_municipality_data($alerts, (array)$verification['data'], $municipality, $admin1, $cacheMeta);
}
