<?php
declare(strict_types=1);
function meteonexa_intelq_point_in_ring(float $lon, float $lat, array $ring) : bool {
    $inside = false;
    $n = count($ring);
    if ($n < 3)return false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float)($ring[$i][0]??0);
        $yi = (float)($ring[$i][1]??0);
        $xj = (float)($ring[$j][0]??0);
        $yj = (float)($ring[$j][1]??0);
        $intersect =(($yi > $lat)!==($yj > $lat))&&($lon <($xj - $xi) *($lat - $yi) /(($yj - $yi) ? : 1e-12) + $xi);
        if ($intersect)$inside = !$inside;
    }
    return $inside;
}
function meteonexa_intelq_geometry_contains(array $geometry, float $lat, float $lon) : bool {
    $type = (string)($geometry['type']??'');
    $coords = (array)($geometry['coordinates']??[]);
    if ($type==='Polygon')return isset($coords[0])&&meteonexa_intelq_point_in_ring($lon, $lat, (array)$coords[0]);
    if ($type==='MultiPolygon') foreach ($coords as $polygon) if (isset($polygon[0])&&meteonexa_intelq_point_in_ring($lon, $lat, (array)$polygon[0]))return true;
    return false;
}
function meteonexa_intelq_meteoalarm_edr(array $config, float $lat, float $lon, string $locale = 'en') : ? array {
    $token = trim((string)($config['official']['meteoalarm_edr_token']??''));
    if ($token==='')return null;
    $country = strtoupper(trim((string)($config['official']['meteoalarm_country']??'IT')));
    if (!preg_match('/^[A-Z]{2}$/', $country))$country = 'IT';
    $language = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) ? $locale : 'en';
    try {
        
        
        
        
        $raw = meteonexa_intel_provider_cache('meteoalarm-edr-' . $country . '-' . $language, 0.0, 0.0, 300, static function()use($country, $language, $token) : array {
            $url = 'https://api.meteoalarm.org/edr/v1/collections/warnings/locations/' . rawurlencode($country) . '?' . http_build_query(['f'=>'geojson', 'language'=>$language, 'active'=>gmdate('c') . '/..'], '', '&', PHP_QUERY_RFC3986); return meteonexa_http_json($url,['timeout'=>18, 'headers'=>['Authorization: Bearer ' . $token, 'Accept: application/geo+json, application/json'], 'max_bytes'=>4000000]);
        }, 21600);
        $cacheMeta = is_array($raw['_meteonexaCache']??null) ? (array)$raw['_meteonexaCache'] :[];
        $staleProvider = !empty($cacheMeta['stale']);
        $cacheAge = is_numeric($cacheMeta['ageSeconds']??null) ? max(0, (int)$cacheMeta['ageSeconds']) : 0;
        $relevant =[];
        foreach ((array)($raw['features']??[]) as $feature) {
            if (!is_array($feature))continue;
            $geometry = (array)($feature['geometry']??[]);
            if ($geometry&&!meteonexa_intelq_geometry_contains($geometry, $lat, $lon))continue;
            
            if (!$geometry)continue;
            $p = (array)($feature['properties']??[]);
            $level = strtolower((string)($p['awareness_level']??$p['severity']??''));
            $severity =(str_contains($level, 'green')||preg_match('/(^|[^0-9])1(?:[^0-9]|$)/', $level)===1) ? 'green' :(str_contains($level, 'red')||str_contains($level, 'extreme') ? 'red' :(str_contains($level, 'orange')||str_contains($level, 'severe') ? 'orange' : 'yellow'));
            $identifier=(string)($p['identifier']??$feature['id']??hash('sha256', json_encode($p)));
            $relevant[] =[
                'id'=>(string)($feature['id']??$identifier),
                'identifier'=>$identifier,
                'title'=>(string)($p['headline']??$p['event']??$p['awareness_type']??'MeteoAlarm'),
                'summary'=>(string)($p['description']??''),
                'instruction'=>(string)($p['instruction']??''),
                'event'=>(string)($p['event']??$p['awareness_type']??''),
                'area'=>(string)($p['areaDesc']??$p['area_desc']??$p['area']??''),
                'severity'=>$severity,
                'certainty'=>(string)($p['certainty']??'unknown'),
                'urgency'=>(string)($p['urgency']??'unknown'),
                'sender'=>(string)($p['sender']??$p['issuer']??''),
                'sentAt'=>$p['sent']??null,
                'updatedAt'=>$p['updated']??$p['sent']??null,
                'startsAt'=>$p['onset']??$p['effective']??$p['valid_from']??null,
                'endsAt'=>$p['expires']??$p['valid_to']??null,
                'messageType'=>(string)($p['msgType']??$p['msg_type']??$p['message_type']??'alert'),
                'status'=>(string)($p['status']??'actual'),
                'references'=>$p['references']??[],
                'geocodes'=>is_array($p['geocode']??null)?(array)$p['geocode']:[],
                'geometry'=>$geometry,
                'source'=>'MeteoAlarm EDR',
                'authority'=>(string)($p['senderName']??$p['issuer_name']??'MeteoAlarm / national warning authority'),
                'geospatialMatch'=>true,
                'locationId'=>$country,
            ];
        }
        return['available'=>true, 'relevant'=>$relevant, 'mode'=>'edr-geospatial', 'geospatial'=>true, 'locationId'=>$country, 'generatedAt'=>gmdate('c'), 'providerFresh'=>!$staleProvider, 'staleProviderCache'=>$staleProvider, 'cacheAgeSeconds'=>$cacheAge];
    } catch (Throwable $ignored) {
        return null;
    }
}
function meteonexa_intelq_radar_components(array $grid, float $threshold = .12) : array {
    $matrix = (array)($grid['grid']??[]);
    $size = (int)($grid['size']??count($matrix));
    if ($size<=0)return[];
    $seen =[];
    $components =[];
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
        $key = "$x:$y";
        if (isset($seen[$key])||((float)($matrix[$y][$x]??0)) < $threshold)continue;
        $queue =[[$x, $y]];
        $seen[$key] = true;
        $mass = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        $pixels = 0;
        $peak = 0.0;
        while ($queue) {
            [$px, $py] = array_pop($queue);
            $v = (float)($matrix[$py][$px]??0);
            $mass+=$v;
            $cx+=$px * $v;
            $cy+=$py * $v;
            $pixels++;
            $peak = max($peak, $v);
            foreach ([[1, 0],[ - 1, 0],[0, 1],[0, - 1]] as[$dx, $dy]) {
                $nx = $px + $dx;
                $ny = $py + $dy;
                if ($nx < 0||$ny < 0||$nx>=$size||$ny>=$size)continue;
                $nk = "$nx:$ny";
                if (isset($seen[$nk])||((float)($matrix[$ny][$nx]??0)) < $threshold)continue;
                $seen[$nk] = true;
                $queue[] =[$nx, $ny];
            }
        }
        if ($pixels>=2&&$mass > .25)$components[] =['mass'=>$mass, 'pixels'=>$pixels, 'cx'=>$mass > 0 ? $cx / $mass : $x, 'cy'=>$mass > 0 ? $cy / $mass : $y, 'peak'=>$peak];
    }
    usort($components, static fn($a, $b)=>$b['mass']<=>$a['mass']);
    return $components;
}
function meteonexa_intelq_component_distance(array $a, array $b) : float {
    return sqrt(((float)$a['cx'] - (float)$b['cx'])**2 +((float)$a['cy'] - (float)$b['cy'])**2);
}
function meteonexa_intelq_track_cell_sequence(array $frames) : array {
    if (count($frames) < 2)return[];
    $latestIndex = count($frames) - 1;
    $latest = $frames[$latestIndex];
    $size = (int)($latest['grid']['size']??0);
    $center = max(0,($size - 1) / 2);
    $candidates = (array)($latest['components']??[]);
    if (!$candidates)return[];
    usort($candidates, static function($a, $b)use($center) {
        $sa = meteonexa_intelq_component_distance($a,['cx'=>$center, 'cy'=>$center]) - min(6, (float)($a['mass']??0)) * .25; $sb = meteonexa_intelq_component_distance($b,['cx'=>$center, 'cy'=>$center]) - min(6, (float)($b['mass']??0)) * .25; return $sa<=>$sb;
    });
    $tracked =[['time'=>$latest['time'], 'grid'=>$latest['grid'], 'cell'=>$candidates[0]]];
    $target = $candidates[0];
    $jumps =[];
    for ($i = $latestIndex - 1; $i>=0; $i--) {
        $best = null;
        $bestDistance = INF;
        foreach ((array)($frames[$i]['components']??[]) as $component) {
            $d = meteonexa_intelq_component_distance($target, $component);
            if ($d < $bestDistance) {
                $best = $component;
                $bestDistance = $d;
            }
        }
        $threshold = max(4.5, min(12.0, $size * .24));
        if (!$best||$bestDistance > $threshold)continue;
        array_unshift($tracked,['time'=>$frames[$i]['time'], 'grid'=>$frames[$i]['grid'], 'cell'=>$best]);
        $jumps[] = $bestDistance;
        $target = $best;
    }
    if (count($tracked) < 2)return[];
    $tracked[count($tracked) - 1]['associationMeanJump'] = $jumps ? array_sum($jumps) / count($jumps) : 0.0;
    return $tracked;
}
