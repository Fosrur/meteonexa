<?php
declare(strict_types=1);

function meteonexa_liguria_normalize_place(string $value): string {
    $value = strtr(trim($value), [
        'À'=>'A','Á'=>'A','Â'=>'A','Ä'=>'A','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ò'=>'O','Ó'=>'O','Ô'=>'O','Ö'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','’'=>' ','\''=>' ','-'=>' '
    ]);
    $value = strtoupper($value);
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function meteonexa_liguria_municipality_zones(string $locationName): array {
    $raw = trim($locationName);
    if ($raw === '') return [];
    $first = trim(explode(',', $raw, 2)[0]);
    $name = meteonexa_liguria_normalize_place($first);
    $groups = [
        'A'=>'AIROLE|APRICALE|AQUILA DI ARROSCIA|ARMO|AURIGO|BADALUCCO|BAIARDO|BORDIGHERA|BORGHETTO DI ARROSCIA|BORGOMARO|CAMPOROSSO|CARAVONICA|CASTEL VITTORIO|CASTELLARO|CERIANA|CERVO|CESIO|CHIUSANICO|CHIUSAVECCHIA|CIPRESSA|CIVEZZA|COSIO DI ARROSCIA|COSTARAINERA|DIANO ARENTINO|DIANO CASTELLO|DIANO MARINA|DIANO SAN PIETRO|DOLCEACQUA|DOLCEDO|IMPERIA|ISOLABONA|LUCINASCO|MENDATICA|MOLINI DI TRIORA|MONTALTO CARPASIO|MONTEGROSSO PIAN LATTE|OLIVETTA SAN MICHELE|OSPEDALETTI|PERINALDO|PIETRABRUNA|PIEVE DI TECO|PIGNA|POMPEIANA|PONTEDASSIO|PORNASSIO|PRELA|RANZO|REZZO|RIVA LIGURE|ROCCHETTA NERVINA|SAN BARTOLOMEO AL MARE|SAN BIAGIO DELLA CIMA|SAN LORENZO AL MARE|SAN REMO|SANREMO|SANTO STEFANO AL MARE|SEBORGA|SOLDANO|TAGGIA|TERZORIO|TRIORA|VALLEBONA|VALLECROSIA|VASIA|VENTIMIGLIA|VESSALICO|VILLA FARALDI|ALASSIO|ALBENGA|ANDORA|ARNASCO|BALESTRINO|BOISSANO|BORGHETTO SANTO SPIRITO|BORGIO VEREZZI|CALICE LIGURE|CASANOVA LERRONE|CASTELBIANCO|CASTELVECCHIO DI ROCCA BARBENA|CERIALE|CISANO SUL NEVA|ERLI|FINALE LIGURE|GARLENDA|GIUSTENICE|LAIGUEGLIA|LOANO|MAGLIOLO|NASINO|NOLI|ONZO|ORCO FEGLINO|ORTOVERO|PIETRA LIGURE|RIALTO|STELLANELLO|TESTICO|TOIRANO|TOVO SAN GIACOMO|VENDONE|VEZZI PORTIO|VILLANOVA DI ALBENGA|ZUCCARELLO',
        'B'=>'ALBISOLA SUPERIORE|ALBISSOLA MARINA|BERGEGGI|CELLE LIGURE|QUILIANO|SAVONA|SPOTORNO|STELLA|VADO LIGURE|VARAZZE|ARENZANO|AVEGNO|BARGAGLI|BOGLIASCO|CAMOGLI|CAMPOMORONE|CERANESI|COGOLETO|DAVAGNA|GENOVA|MELE|MIGNANEGO|PIEVE LIGURE|RECCO|SANTO OLCESE|SERRA RICCO|SORI',
        'C'=>'BORZONASCA|CARASCO|CASARZA LIGURE|CASTIGLIONE CHIAVARESE|CHIAVARI|CICAGNA|COGORNO|COREGLIA LIGURE|FAVALE DI MALVARO|LAVAGNA|LEIVI|LORSICA|LUMARZO|MEZZANEGO|MOCONESI|MONEGLIA|NE|NEIRONE|ORERO|PORTOFINO|RAPALLO|SAN COLOMBANO CERTENOLI|SANTA MARGHERITA LIGURE|SESTRI LEVANTE|TRIBOGNA|USCIO|ZOAGLI|AMEGLIA|ARCOLA|BEVERINO|BOLANO|BONASSOLA|BORGHETTO DI VARA|BRUGNATO|CALICE AL CORNOVIGLIO|CARRO|CARRODANO|CASTELNUOVO MAGRA|DEIVA MARINA|FOLLO|FRAMURA|LA SPEZIA|LERICI|LEVANTO|LUNI|MAISSANA|MONTEROSSO AL MARE|PIGNONE|PORTOVENERE|RICCO DEL GOLFO DI SPEZIA|RIOMAGGIORE|ROCCHETTA DI VARA|SANTO STEFANO DI MAGRA|SARZANA|SESTA GODANO|VARESE LIGURE|VERNAZZA|VEZZANO LIGURE|ZIGNAGO',
        'D'=>'ALTARE|BARDINETO|BORMIDA|CAIRO MONTENOTTE|CALIZZANO|CARCARE|CENGIO|COSSERIA|DEGO|GIUSVALLA|MALLARE|MASSIMINO|MILLESIMO|MIOGLIA|MURIALDO|OSIGLIA|PALLARE|PIANA CRIXIA|PLODIO|PONTINVREA|ROCCAVIGNALE|SASSELLO|URBE|CAMPO LIGURE|MASONE|ROSSIGLIONE|TIGLIETO',
        'E'=>'BUSALLA|CASELLA|CROCEFIESCHI|FASCIA|FONTANIGORDA|GORRETO|ISOLA DEL CANTONE|MONTEBRUNO|MONTOGGIO|PROPATA|REZZOAGLIO|RONCO SCRIVIA|RONDANINA|ROVEGNO|SANTO STEFANO D AVETO|SAVIGNONE|TORRIGLIA|VALBREVENNA|VOBBIA'
    ];
    $zones = [];
    foreach ($groups as $zone=>$list) {
        if (in_array($name, explode('|', $list), true)) $zones[] = $zone;
    }
    if ($name === 'LORSICA' || $name === 'MOCONESI') $zones[] = 'E';
    if ($name === 'USCIO') $zones[] = 'B';
    $zones = array_values(array_unique($zones));
    sort($zones, SORT_STRING);
    return $zones;
}

function meteonexa_liguria_parse_zone_statuses(string $html): array {
    $text = html_entity_decode((string)preg_replace('/<[^>]+>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $zones = [];
    if (preg_match_all('/\bzona\s*([A-E])\s*(?:[:\-–]\s*)?(?:Emessa\s+allerta\s+(gialla|arancione|rossa)|Nessuna\s+allerta)\b/iu', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $zone = strtoupper((string)$match[1]);
            $severity = isset($match[2]) && trim((string)$match[2]) !== '' ? strtolower((string)$match[2]) : 'green';
            $severity = ['gialla'=>'yellow','arancione'=>'orange','rossa'=>'red'][$severity] ?? $severity;
            if (!isset($zones[$zone]) || (['green'=>0,'yellow'=>1,'orange'=>2,'red'=>3][$severity] ?? 0) > (['green'=>0,'yellow'=>1,'orange'=>2,'red'=>3][$zones[$zone]] ?? 0)) {
                $zones[$zone] = $severity;
            }
        }
    }
    return $zones;
}

function meteonexa_liguria_zone_status_snapshot(): array {
    $cacheDir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
    $cacheFile = $cacheDir . '/allertaliguria-zone-status.json';
    $cached = null;
    $cacheAge = null;
    if (is_file($cacheFile)) {
        $cacheAge = max(0, time() - (int)filemtime($cacheFile));
        $decoded = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($decoded) && is_array($decoded['zones'] ?? null)) $cached = $decoded;
        if ($cached !== null && $cacheAge < 300) {
            $cached['providerFresh'] = true;
            $cached['staleProviderCache'] = false;
            $cached['cacheAgeSeconds'] = $cacheAge;
            return $cached;
        }
    }
    try {
        $response = meteonexa_http_request('https://allertaliguria.regione.liguria.it/', ['timeout'=>6, 'max_bytes'=>2500000]);
        if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300) {
            $zones = meteonexa_liguria_parse_zone_statuses((string)($response['body'] ?? ''));
            if ($zones !== []) {
                $snapshot = ['zones'=>$zones, 'fetchedAt'=>gmdate('c'), 'source'=>'AllertaLiguria / ARPAL'];
                @file_put_contents($cacheFile, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
                return $snapshot + ['providerFresh'=>true, 'staleProviderCache'=>false, 'cacheAgeSeconds'=>0];
            }
        }
    } catch (Throwable $ignored) {
    }
    if ($cached !== null && $cacheAge !== null && $cacheAge < 3600) {
        $cached['providerFresh'] = false;
        $cached['staleProviderCache'] = true;
        $cached['cacheAgeSeconds'] = $cacheAge;
        return $cached;
    }
    return ['zones'=>[], 'providerFresh'=>false, 'staleProviderCache'=>false, 'cacheAgeSeconds'=>$cacheAge, 'source'=>'AllertaLiguria / ARPAL'];
}

function meteonexa_liguria_apply_zone_status(array $alerts, string $locationName, array $zones, array $snapshot): array {
    if (!empty($alerts['relevant']) || $zones === []) return $alerts;
    $rank = ['green'=>0,'yellow'=>1,'orange'=>2,'red'=>3];
    $bestZone = '';
    $bestSeverity = 'green';
    foreach ($zones as $zone) {
        $severity = strtolower((string)($snapshot['zones'][$zone] ?? 'green'));
        if (($rank[$severity] ?? 0) > ($rank[$bestSeverity] ?? 0)) {
            $bestZone = $zone;
            $bestSeverity = $severity;
        }
    }
    if (($rank[$bestSeverity] ?? 0) < 1 || $bestZone === '') return $alerts;

    $regional = array_values((array)($alerts['regionalAdvisories'] ?? []));
    $candidate = null;
    $remaining = [];
    foreach ($regional as $row) {
        if (!is_array($row)) continue;
        $hasGeometry = !empty($row['geometry']) || !empty($row['polygons']) || !empty($row['circles']);
        $hay = strtolower((string)($row['title'] ?? '') . ' ' . (string)($row['summary'] ?? '') . ' ' . (string)($row['area'] ?? ''));
        if ($candidate === null && !$hasGeometry && str_contains($hay, 'ligur')) {
            $candidate = $row;
            continue;
        }
        $remaining[] = $row;
    }

    $zoneLabel = implode('/', $zones);
    $row = is_array($candidate) ? $candidate : [
        'id'=>'allertaliguria-' . strtolower($bestZone) . '-' . gmdate('Ymd'),
        'identifier'=>'allertaliguria-' . strtolower($bestZone) . '-' . gmdate('Ymd'),
        'title'=>'weather',
        'summary'=>'',
        'event'=>'weather',
        'startsAt'=>null,
        'endsAt'=>null,
        'updatedAt'=>$snapshot['fetchedAt'] ?? gmdate('c'),
        'messageType'=>'Alert',
        'status'=>'Actual',
        'certainty'=>'Likely',
        'urgency'=>'Expected'
    ];
    $row['severity'] = $bestSeverity;
    $row['source'] = 'AllertaLiguria / ARPAL';
    $row['authority'] = 'Regione Liguria / ARPAL';
    $row['area'] = 'Liguria - zona ' . $zoneLabel;
    $row['geospatialMatch'] = true;
    $row['matchScope'] = 'official-municipality-zone';
    $row['officialZone'] = $bestZone;
    $row['officialZones'] = $zones;
    $row['municipality'] = $locationName;
    $row['municipalityZoneVerified'] = true;
    $row['verificationSource'] = 'AllertaLiguria / ARPAL';
    $row['providerFresh'] = !empty($snapshot['providerFresh']);
    $row['staleProviderCache'] = !empty($snapshot['staleProviderCache']);

    $alerts['relevant'] = [$row];
    $alerts['regionalAdvisories'] = $remaining;
    $alerts['source'] = 'AllertaLiguria / ARPAL';
    $alerts['officialZone'] = $bestZone;
    $alerts['officialZones'] = $zones;
    $alerts['municipalityZoneVerified'] = true;
    $alerts['administrativeZoneFallback'] = true;
    $alerts['mode'] = 'allertaliguria-municipality-zone';
    $alerts['providerFresh'] = !empty($snapshot['providerFresh']);
    $alerts['staleProviderCache'] = !empty($snapshot['staleProviderCache']);
    return $alerts;
}

function meteonexa_official_liguria_zone_fallback(array $alerts, string $locationName, string $admin1 = ''): array {
    if (!empty($alerts['relevant'])) return $alerts;
    $admin = meteonexa_liguria_normalize_place($admin1);
    if ($admin !== '' && !str_contains($admin, 'LIGURIA')) return $alerts;
    $zones = meteonexa_liguria_municipality_zones($locationName);
    if ($zones === []) return $alerts;
    return meteonexa_liguria_apply_zone_status($alerts, $locationName, $zones, meteonexa_liguria_zone_status_snapshot());
}
