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

function meteonexa_official_municipality_risks(array $data): array {
    $today = is_array($data['oggi'] ?? null) ? (array)$data['oggi'] : [];
    $details = is_array($today['dettagli'] ?? null) ? (array)$today['dettagli'] : [];
    $out = [];
    foreach ($details as $risk=>$description) {
        $text = meteonexa_official_place_token((string)$description);
        if ($text === '' || str_contains($text, 'nessuna allerta') || str_contains($text, 'assenza di fenomeni')) continue;
        $out[] = meteonexa_official_place_token((string)$risk);
    }
    return array_values(array_unique(array_filter($out)));
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
            'timeout'=>10,
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

function meteonexa_official_municipality_row_score(array $row, array $risks): int {
    $event = meteonexa_official_place_token((string)($row['event'] ?? '') . ' ' . (string)($row['title'] ?? ''));
    $score = 0;
    $state = strtolower((string)($row['windowState'] ?? ''));
    if ($state === 'active') $score += 30;
    elseif ($state === 'upcoming') $score += 20;
    $rank = ['green'=>0,'yellow'=>1,'orange'=>2,'red'=>3];
    $score += 4 * ($rank[strtolower((string)($row['severity'] ?? 'green'))] ?? 0);
    foreach ($risks as $risk) {
        if ($risk === 'temporali' && (str_contains($event, 'thunder') || str_contains($event, 'storm') || str_contains($event, 'tempor'))) $score += 40;
        if ($risk === 'idrogeologico' && (str_contains($event, 'rain') || str_contains($event, 'flood') || str_contains($event, 'piogg'))) $score += 32;
        if ($risk === 'idraulico' && (str_contains($event, 'rain') || str_contains($event, 'flood') || str_contains($event, 'piogg'))) $score += 28;
    }
    return $score;
}

function meteonexa_official_apply_position_area_match(array $alerts, string $locationName, string $admin1): array {
    if (!empty($alerts['relevant'])) return $alerts;

    $municipality = trim(explode(',', $locationName, 2)[0]);
    if ($municipality === '') return $alerts;

    $verification = meteonexa_official_municipality_fetch($municipality);
    if (!is_array($verification) || !is_array($verification['data'] ?? null)) return $alerts;

    $data = (array)$verification['data'];
    $providerMunicipality = trim((string)($data['comune'] ?? ''));
    $providerRegion = trim((string)($data['regione'] ?? ''));
    if ($providerMunicipality === '' || meteonexa_official_place_token($providerMunicipality) !== meteonexa_official_place_token($municipality)) return $alerts;
    if ($admin1 !== '' && $providerRegion !== '' && meteonexa_official_place_token($providerRegion) !== meteonexa_official_place_token($admin1)) return $alerts;

    $severity = meteonexa_official_municipality_severity($data);
    $cacheMeta = is_array($verification['_meteonexaMunicipalityCache'] ?? null) ? (array)$verification['_meteonexaMunicipalityCache'] : [];
    $alerts['municipalityVerification'] = [
        'verified'=>true,
        'municipality'=>$providerMunicipality,
        'region'=>$providerRegion,
        'zone'=>(string)($data['zona'] ?? ''),
        'severity'=>$severity,
        'source'=>'Allerta Meteo Italia / Protezione Civile data',
        'stale'=>!empty($cacheMeta['stale']),
        'cacheAgeSeconds'=>(int)($cacheMeta['ageSeconds'] ?? 0)
    ];

    if ($severity === '' || $severity === 'green') return $alerts;

    $risks = meteonexa_official_municipality_risks($data);
    $candidates = [];
    foreach ((array)($alerts['regionalAdvisories'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $row = meteonexa_official_enrich_window($row);
        $candidates[] = ['score'=>meteonexa_official_municipality_row_score($row, $risks),'row'=>$row];
    }
    usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

    $selected = $candidates[0]['row'] ?? [
        'id'=>'municipality-' . substr(hash('sha256', meteonexa_official_place_token($providerMunicipality) . '|' . date('Y-m-d')), 0, 32),
        'identifier'=>'municipality-' . meteonexa_official_place_token($providerMunicipality),
        'title'=>'Allerta Protezione Civile - ' . $providerMunicipality,
        'summary'=>(string)($data['oggi']['allerta']['descrizione'] ?? ''),
        'event'=>$risks ? implode(', ', $risks) : 'official warning',
        'area'=>(string)($data['zona'] ?? $providerMunicipality),
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

    $selected['severity'] = $severity;
    $selected['matchScope'] = 'municipality-external';
    $selected['municipalityMatch'] = true;
    $selected['matchedMunicipality'] = $providerMunicipality;
    $selected['matchedAdministrativeArea'] = (string)($data['zona'] ?? '');
    $selected['positionMatched'] = true;
    $selected['positionMatchSource'] = 'current-location-municipality';
    $selected['territorialVerificationSource'] = 'Allerta Meteo Italia / Protezione Civile data';
    $selected['territorialRisks'] = $risks;
    $selected['municipalityProviderFresh'] = empty($cacheMeta['stale']);

    $alerts['relevant'] = [$selected];
    $alerts['administrativeAreaMatch'] = false;
    $alerts['municipalityMatch'] = true;
    $alerts['matchedByAreaText'] = false;
    $alerts['mode'] = 'municipality-external-verification';
    return $alerts;
}
