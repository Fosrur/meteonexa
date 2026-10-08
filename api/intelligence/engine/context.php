<?php
declare(strict_types=1);
function meteonexa_intelligence_accuracy(PDO $pdo, string $deviceId, string $locationKey) : array {
    try {
        $stmt = $pdo->prepare("SELECT f.model_name,COUNT(*) samples,AVG(ABS(f.temperature-o.temperature)) temp_mae,AVG(ABS(f.precipitation-o.precipitation)) rain_mae,AVG(ABS(f.wind_gust-o.wind_gust)) wind_mae FROM model_forecast_samples f JOIN model_observation_samples o ON o.device_id=f.device_id AND o.location_key=f.location_key AND o.observed_time=f.target_time WHERE f.device_id=:device AND f.location_key=:location AND f.horizon_hours IN (1,3,6) GROUP BY f.model_name");
        $stmt->execute([':device'=>$deviceId, ':location'=>$locationKey]);
        $rows = $stmt->fetchAll();
        if (!$rows)return['samples'=>0, 'score'=>55, 'models'=>0, 'weights'=>[], 'learning'=>true];
        $weights =[];
        $quality =[];
        $totalSamples = 0;
        foreach ($rows as $row) {
            $samples = (int)$row['samples'];
            $totalSamples+=$samples;
            $mae = (float)$row['temp_mae'] * .8 + (float)$row['rain_mae'] * 4 + (float)$row['wind_mae'] * .12;
            $quality[(string)$row['model_name']] = 1 / max(.5, $mae);
        }
        $sum = array_sum($quality);
        foreach ($quality as $model=>$q)$weights[$model] = $sum > 0 ? round($q / $sum, 4) : 0;
        $score = (int)round(meteonexa_intel_clamp(55 + min(25, $totalSamples * 1.1) + min(15, count($rows) * 3), 45, 95));
        return['samples'=>$totalSamples, 'score'=>$score, 'models'=>count($rows), 'weights'=>$weights, 'learning'=>$totalSamples < 12];
    } catch (Throwable $ignored) {
        return['samples'=>0, 'score'=>55, 'models'=>0, 'weights'=>[], 'learning'=>true];
    }
}
function meteonexa_intelligence_hyperlocal(PDO $pdo, array $config, string $deviceId, float $lat, float $lon) : array {
    if (!meteonexa_db_table_exists($pdo, 'netatmo_accounts'))return['available'=>false];
    $exists = $pdo->prepare('SELECT 1 FROM netatmo_accounts WHERE device_id=:device LIMIT 1');
    $exists->execute([':device'=>$deviceId]);
    if (!$exists->fetchColumn())return['available'=>false];
    if (trim((string)($config['netatmo']['client_id']??''))===''||trim((string)($config['netatmo']['client_secret']??''))==='')return['available'=>false];
    try {
        require_once dirname(__DIR__) . '/netatmo/helpers.php';
        $token = netatmo_access_token($pdo, $config, $deviceId);
        $raw = meteonexa_http_json('https://api.netatmo.com/api/getstationsdata?get_favorites=true',['timeout'=>18, 'headers'=>['Authorization: Bearer ' . $token, 'Accept: application/json'], 'max_bytes'=>1500000]);
        $best = null;
        $bestDistance = INF;
        foreach ((array)($raw['body']['devices']??[]) as $station) {
            $place = $station['place']['location']??[];
            if (!isset($place[0], $place[1]))continue;
            $distance = haversine_km($lat, $lon, (float)$place[1], (float)$place[0]);
            if ($distance > $bestDistance)continue;
            $modules = array_merge([$station], is_array($station['modules']??null) ? $station['modules'] :[]);
            $ob =[];
            foreach ($modules as $module) {
                $dash = $module['dashboard_data']??[];
                foreach (['Temperature'=>'temperature', 'Humidity'=>'humidity', 'Pressure'=>'pressure', 'Rain'=>'rain', 'WindStrength'=>'wind', 'GustStrength'=>'gust'] as $src=>$dst) if (isset($dash[$src])&&is_numeric($dash[$src]))$ob[$dst] = (float)$dash[$src];
                if (isset($dash['time_utc']))$ob['observedAt'] = (int)$dash['time_utc'];
            }
            $best =['available'=>true, 'distanceKm'=>round($distance, 1), 'station'=>(string)($station['station_name']??'Netatmo'), 'observation'=>$ob];
            $bestDistance = $distance;
        }
        return $best??['available'=>false];
    } catch (Throwable $ignored) {
        return['available'=>false, 'degraded'=>true];
    }
}
function meteonexa_official_atom_local(SimpleXMLElement $entry, string $name) : string {
    $nodes = $entry->xpath('.//*[local-name()="' . $name . '"]');
    if (!is_array($nodes)||!isset($nodes[0]))return '';
    return trim((string)$nodes[0]);
}
function meteonexa_official_atom_locals(SimpleXMLElement $entry, string $name) : array {
    $nodes = $entry->xpath('.//*[local-name()="' . $name . '"]');
    if (!is_array($nodes))return [];
    $out=[];foreach($nodes as $node){$value=trim((string)$node);if($value!==''&&!in_array($value,$out,true))$out[]=$value;}
    return $out;
}
function meteonexa_official_severity_from_text(string $text) : string {
    $text = strtolower($text);
    if (preg_match('/\b(red|rosso|rouge|rot|rojo|extreme)\b/u', $text))return 'red';
    if (preg_match('/\b(orange|aranc(?:ione)?|naranja|severe)\b/u', $text))return 'orange';
    if (preg_match('/\b(green|verde|vert|grün|gruen|no warning|nessuna allerta)\b/u', $text))return 'green';
    if (preg_match('/\b(yellow|giall[ao]?|jaune|gelb|amarill[oa]|moderate)\b/u', $text))return 'yellow';
    return 'yellow';
}
function meteonexa_official_alerts(float $lat, float $lon, string $locationName = '', string $admin1 = '') : array {
    $cacheDir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($cacheDir))@mkdir($cacheDir, 0770, true);
    $cacheFile = $cacheDir . '/meteoalarm-it-atom.json';
    $rows = null;
    $staleRows = null;
    $cacheAge = null;
    $providerFresh = false;
    $staleFallback = false;
    if (is_file($cacheFile)) {
        $cacheAge = max(0, time() - (int)filemtime($cacheFile));
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            if ($cacheAge < 600)$rows = $cached;
            if ($cacheAge < 21600)$staleRows = $cached;
            
        }
    }
    if ($rows===null) {
        try {
            $response = meteonexa_http_request('https://feeds.meteoalarm.org/feeds/meteoalarm-legacy-atom-italy',['timeout'=>15, 'max_bytes'=>2000000]);
            if ($response['status']>=200&&$response['status'] < 300&&function_exists('simplexml_load_string')) {
                $xml = @simplexml_load_string((string)$response['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                if ($xml) {
                    $fresh =[];
                    $entries = $xml->entry;
                    if (count($entries)===0) {
                        $ns = $xml->getNamespaces(true);
                        $children = $xml->children((string)($ns['']??''));
                        $entries = $children->entry;
                    }
                    foreach ($entries as $entry) {
                        $title = trim((string)$entry->title);
                        $summary = trim(strip_tags((string)$entry->summary));
                        $id = trim((string)$entry->id);
                        $updated = trim((string)$entry->updated);
                        
                        
                        $event = meteonexa_official_atom_local($entry, 'event');
                        $effective = meteonexa_official_atom_local($entry, 'effective');
                        $expires = meteonexa_official_atom_local($entry, 'expires');
                        $capSeverity = meteonexa_official_atom_local($entry, 'severity');
                        $area = meteonexa_official_atom_local($entry, 'areaDesc');
                        $certainty = meteonexa_official_atom_local($entry, 'certainty');
                        $urgency = meteonexa_official_atom_local($entry, 'urgency');
                        $sender = meteonexa_official_atom_local($entry, 'sender');
                        $sent = meteonexa_official_atom_local($entry, 'sent');
                        $msgType = meteonexa_official_atom_local($entry, 'msgType');
                        $status = meteonexa_official_atom_local($entry, 'status');
                        $references = meteonexa_official_atom_local($entry, 'references');
                        $instruction = meteonexa_official_atom_local($entry, 'instruction');
                        $polygons = meteonexa_official_atom_locals($entry, 'polygon');
                        $circles = meteonexa_official_atom_locals($entry, 'circle');
                        $text = trim($title . ' ' . $summary . ' ' . $event . ' ' . $capSeverity . ' ' . $area);
                        $severity = meteonexa_official_severity_from_text($text);
                        $fresh[] =['id'=>$id!=='' ? $id : hash('sha256', $title . $updated . $effective . $expires), 'identifier'=>$id, 'title'=>$title, 'summary'=>$summary, 'event'=>$event, 'area'=>$area, 'certainty'=>$certainty, 'urgency'=>$urgency, 'sender'=>$sender, 'sentAt'=>$sent, 'messageType'=>$msgType, 'status'=>$status, 'references'=>$references, 'instruction'=>$instruction, 'polygons'=>$polygons, 'circles'=>$circles, 'updatedAt'=>$updated, 'startsAt'=>$effective ? : null, 'endsAt'=>$expires ? : null, 'severity'=>$severity, 'source'=>'MeteoAlarm Atom', 'authority'=>'MeteoAlarm / national warning authority', 'geospatialMatch'=>false,];
                        if (count($fresh)>=100)break;
                    }
                    
                    
                    $rows = $fresh;
                    $providerFresh = true;
                    $cacheAge = 0;
                    @file_put_contents($cacheFile, json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
                }
            }
        } catch (Throwable $ignored) {
        }
        if ($rows===null&&is_array($staleRows)) {
            $rows = $staleRows;
            $staleFallback = true;
        }
    }
    if ($rows===null) {
        return['available'=>false, 'source'=>'MeteoAlarm', 'relevant'=>[], 'countryFeedCount'=>0, 'matchedByAreaText'=>false, 'providerFresh'=>false, 'staleProviderCache'=>false];
    }
    $locationNeedle = strtolower(trim($locationName));
    $adminNeedle = strtolower(trim($admin1));
    $relevant =[];
    $regional =[];
    $geospatialMatches = 0;
    foreach ($rows as $row) {
        if (!is_array($row))continue;
        
        
        if (strtolower((string)($row['severity']??''))==='green')continue;
        $row = meteonexa_official_hub_normalize_row($row, $lat, $lon);
        $hay = strtolower(trim((string)($row['title']??'') . ' ' . (string)($row['summary']??'') . ' ' . (string)($row['area']??'')));
        $locationMatch = $locationNeedle!==''&&(function_exists('mb_strlen') ? mb_strlen($locationNeedle, 'UTF-8') : strlen($locationNeedle))>=4&&str_contains($hay, $locationNeedle);
        $adminMatch = $adminNeedle!==''&&(function_exists('mb_strlen') ? mb_strlen($adminNeedle, 'UTF-8') : strlen($adminNeedle))>=4&&str_contains($hay, $adminNeedle);
        if (!empty($row['geometry'])) {
            if (($row['geospatialMatch']??false)===true) {
                $row['matchScope'] = 'polygon';
                $relevant[] = $row;
                $geospatialMatches++;
            } elseif ($adminMatch) {
                $row['matchScope'] = 'regional-polygon-outside';
                $regional[] = $row;
            }
            continue;
        }
        if ($locationMatch) {
            $row['matchScope'] = 'location-text';
            $relevant[] = $row;
        } elseif ($adminMatch) {
            $row['matchScope'] = 'regional-text';
            $regional[] = $row;
        }
    }
    return['available'=>true, 'source'=>'MeteoAlarm', 'relevant'=>$relevant, 'regionalAdvisories'=>$regional, 'countryFeedCount'=>count($rows), 'matchedByAreaText'=>$relevant!==[], 'regionalTextMatch'=>$regional!==[], 'geospatial'=>$geospatialMatches>0, 'geospatialMatchCount'=>$geospatialMatches, 'providerFresh'=>$providerFresh||(!$staleFallback&&$cacheAge!==null&&$cacheAge < 600), 'staleProviderCache'=>$staleFallback, 'cacheAgeSeconds'=>$cacheAge];
}
