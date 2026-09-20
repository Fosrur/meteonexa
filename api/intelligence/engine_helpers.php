<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/backend_i18n.php';
require_once __DIR__ . '/verification_helpers.php';
function meteonexa_intel_clamp(float $value, float $min, float $max) : float {
    return max($min, min($max, $value));
}
function meteonexa_intel_number(mixed $value, float $fallback = 0.0) : float {
    return is_numeric($value) ? (float)$value : $fallback;
}
function meteonexa_intel_arr(array $data, string $key) : array {
    return isset($data[$key])&&is_array($data[$key]) ? $data[$key] :[];
}
/** Parse provider timestamps deterministically in UTC. */
function meteonexa_intel_time_utc(mixed $value) : ? int {
    $raw = trim((string)$value);
    if ($raw==='')return null;
    try {
        return(new DateTimeImmutable($raw, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable $ignored) {
        return null;
    }
}
/** Small shared-hosting cache to avoid repeated upstream calls for equal coordinates. */
function meteonexa_intel_provider_cache(string $kind, float $lat, float $lon, int $ttl, callable $loader, int $staleTtl = 21600) : array {
    $dir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($dir))@mkdir($dir, 0770, true);
    $key = hash('sha256', $kind . '|' . number_format($lat, 4, '.', '') . '|' . number_format($lon, 4, '.', ''));
    $path = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '-', $kind) . '-' . substr($key, 0, 24) . '.json';
    $cached = null;
    $age = null;
    if (is_file($path)) {
        $mtime = (int)@filemtime($path);
        $age = max(0, time() - $mtime);
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (is_array($decoded))$cached = $decoded;
        if (is_array($cached)&&$age < max(30, $ttl)) {
            $cached['_meteonexaCache'] =['stale'=>false, 'ageSeconds'=>$age, 'fetchedAt'=>$mtime > 0 ? gmdate('c', $mtime) : null, 'cacheHit'=>true];
            return $cached;
        }
    }
    try {
        $started = microtime(true);
        $data = $loader();
        if (!is_array($data))throw new RuntimeException('PROVIDER_RESPONSE_INVALID');
        $persist = $data;
        unset($persist['_meteonexaCache']);
        @file_put_contents($path, json_encode($persist, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($path, 0600);
        $data['_meteonexaCache'] =['stale'=>false, 'ageSeconds'=>0, 'fetchedAt'=>gmdate('c'), 'cacheHit'=>false, 'latencyMs'=>(int)round((microtime(true) - $started) * 1000)];
        return $data;
    } catch (Throwable $error) {
        // Shared/free upstreams can briefly return 429/5xx. Keep the last good
        // observation as a bounded stale fallback rather than cascading the
        // provider outage into the PWA. Consumers may inspect this marker.
        if (is_array($cached)&&$age!==null&&$age<=max($ttl, $staleTtl)) {
            $cached['_meteonexaCache'] =['stale'=>true, 'ageSeconds'=>$age, 'fetchedAt'=>is_file($path) ? gmdate('c', (int)@filemtime($path)) : null, 'cacheHit'=>true];
            meteonexa_observability_event('provider', $kind, 'stale_fallback',['ageSeconds'=>$age]);
            return $cached;
        }
        meteonexa_observability_event('provider', $kind, 'error',['class'=>get_class($error)]);
        throw $error;
    }
}
function meteonexa_intelligence_location_key(float $lat, float $lon) : string {
    return number_format($lat, 4, '.', '') . ':' . number_format($lon, 4, '.', '');
}
function meteonexa_intelligence_weather(float $lat, float $lon, bool $demo = false, int $forecastHours = 36) : array {
    // Demo deliberately reduces coordinate precision before sending it upstream.
    if ($demo) {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
    }
    $forecastHours = max(24, min(96, $forecastHours));
    return meteonexa_intel_provider_cache('weather-' . $forecastHours, $lat, $lon, 180, static function()use($lat, $lon, $forecastHours) : array {
        $params = http_build_query(['latitude'=>$lat, 'longitude'=>$lon, 'current'=>'temperature_2m,apparent_temperature,precipitation,rain,snowfall,weather_code,cloud_cover,wind_speed_10m,wind_gusts_10m,visibility', 'minutely_15'=>'precipitation,rain,snowfall,weather_code', 'forecast_minutely_15'=>8, 'hourly'=>'temperature_2m,apparent_temperature,precipitation_probability,precipitation,rain,snowfall,weather_code,visibility,wind_speed_10m,wind_gusts_10m,cape,relative_humidity_2m,cloud_cover,is_day,sunshine_duration,shortwave_radiation', 'forecast_hours'=>$forecastHours,
        // Server-side calculations must not depend on PHP's deployment timezone.
        'timezone'=>'GMT', 'wind_speed_unit'=>'kmh',]); return meteonexa_http_json('https://api.open-meteo.com/v1/forecast?' . $params,['timeout'=>18, 'max_bytes'=>1500000]);
    });
}
function meteonexa_intelligence_air(float $lat, float $lon, bool $demo = false) : ? array {
    if ($demo) {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
    }
    try {
        return meteonexa_intel_provider_cache('air', $lat, $lon, 300, static function()use($lat, $lon) : array {
            $params = http_build_query(['latitude'=>$lat, 'longitude'=>$lon, 'hourly'=>'european_aqi,pm2_5', 'forecast_days'=>2, 'timezone'=>'GMT',]); return meteonexa_http_json('https://air-quality-api.open-meteo.com/v1/air-quality?' . $params,['timeout'=>14, 'max_bytes'=>700000]);
        });
    } catch (Throwable $ignored) {
        return null;
    }
}
function meteonexa_intelligence_lightning(array $config, float $lat, float $lon) : array {
    $client = trim((string)($config['lightning']['client_id']??''));
    $secret = trim((string)($config['lightning']['client_secret']??''));
    if ($client===''||$secret==='')return['available'=>false, 'count'=>0, 'nearestKm'=>null, 'approaching'=>false];
    $radius = max(10, min(100, (int)($config['lightning']['radius_km']??80)));
    try {
        return meteonexa_intel_provider_cache('lightning-' . $radius, $lat, $lon, 60, static function()use($client, $secret, $lat, $lon, $radius) : array {
            $url = 'https://data.api.xweather.com/lightning/closest?p=' . rawurlencode($lat . ',' . $lon) . '&radius=' . $radius . 'km&limit=100&client_id=' . rawurlencode($client) . '&client_secret=' . rawurlencode($secret); $raw = meteonexa_http_json($url,['timeout'=>14, 'max_bytes'=>1000000]); $responses = is_array($raw['response']??null) ? $raw['response'] :[]; $nearest = null; $recent = 0; $latestTs = 0; foreach ($responses as $row) {
                if (!is_array($row))continue; $distance = null; foreach (['distanceKM', 'distance_km', 'distance'] as $field) if (isset($row[$field])&&is_numeric($row[$field])) {
                    $distance = (float)$row[$field]; break;
                }
                if ($distance!==null)$nearest = $nearest===null ? $distance : min($nearest, $distance); $ts = (int)($row['timestamp']??$row['ob']['timestamp']??0); if ($ts > $latestTs)$latestTs = $ts; $age = $row['ob']['age']??$row['age']??null; if (($ts > 0&&$ts>=time() - 1800)||(is_numeric($age)&&(float)$age>=0&&(float)$age<=1800))$recent++;
            }
            return['available'=>true, 'count'=>count($responses), 'recent30m'=>$recent, 'nearestKm'=>$nearest===null ? null : round($nearest, 1), 'approaching'=>$recent>=3&&($nearest===null||$nearest<=35), 'observedAt'=>$latestTs > 0 ? gmdate('c', $latestTs) : null];
        }, 300);
    } catch (Throwable $ignored) {
        return['available'=>true, 'count'=>0, 'nearestKm'=>null, 'approaching'=>false, 'degraded'=>true];
    }
}
function meteonexa_intelligence_satellite(float $lat, float $lon) : array {
    // Satellite-derived irradiance is used as an observational cloud-support
    // signal only. It never creates an alert by itself. The clear-sky ratio
    // removes most solar-angle effects and keeps the signal meaningful by day.
    $cacheDir = meteonexa_storage_path() . '/provider-cache';
    if (!is_dir($cacheDir))@mkdir($cacheDir, 0770, true);
    $cacheKey = number_format(round($lat, 1), 1, '.', '_') . '_' . number_format(round($lon, 1), 1, '.', '_');
    $cacheFile = $cacheDir . '/satellite-' . $cacheKey . '.json';
    if (is_file($cacheFile)&&time() - (int)filemtime($cacheFile) < 300) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached))return $cached;
    }
    $result =['available'=>false, 'source'=>'Open-Meteo Satellite / EUMETSAT'];
    try {
        $url = 'https://satellite-api.open-meteo.com/v1/archive?' . http_build_query(['latitude'=>round($lat, 4), 'longitude'=>round($lon, 4), 'hourly'=>'shortwave_radiation,shortwave_radiation_clear_sky', 'past_days'=>1, 'forecast_days'=>1, 'timezone'=>'GMT'], '', '&', PHP_QUERY_RFC3986);
        $raw = meteonexa_http_json($url,['timeout'=>15, 'max_bytes'=>750000]);
        $hourly = (array)($raw['hourly']??[]);
        $times = (array)($hourly['time']??[]);
        $actual = (array)($hourly['shortwave_radiation']??[]);
        $clear = (array)($hourly['shortwave_radiation_clear_sky']??[]);
        $best = null;
        $now = time() + 1200;
        for ($i = min(count($times), count($actual), count($clear)) - 1; $i>=0; $i--) {
            if (!is_numeric($actual[$i]??null)||!is_numeric($clear[$i]??null))continue;
            $ts = meteonexa_intel_time_utc($times[$i]??'');
            $clr = (float)$clear[$i];
            if ($ts===null||$ts > $now||$clr < 40)continue;
            $act = max(0.0, (float)$actual[$i]);
            $ratio = meteonexa_intel_clamp($act / max(1.0, $clr), 0, 1.25);
            $attenuation = meteonexa_intel_clamp((1 - $ratio) * 100, 0, 100);
            $best =['available'=>true, 'source'=>'Open-Meteo Satellite / EUMETSAT', 'observedAt'=>gmdate('c', $ts), 'shortwaveWm2'=>round($act, 1), 'clearSkyWm2'=>round($clr, 1), 'cloudAttenuationPct'=>round($attenuation), 'support'=>round($attenuation / 100, 3)];
            break;
        }
        if ($best)$result = $best;
    } catch (Throwable $ignored) {
        $result['degraded'] = true;
    }
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $result;
}
function meteonexa_radar_signal_grid(string $path, int $size = 32) : ? array {
    if (!function_exists('imagecreatefromstring')||!is_file($path)||(int)@filesize($path)<=0)return null;
    $bytes = @file_get_contents($path);
    if (!is_string($bytes)||$bytes==='')return null;
    $image = @imagecreatefromstring($bytes);
    if (!$image)return null;
    $w = imagesx($image);
    $h = imagesy($image);
    if ($w < 8||$h < 8) {
        imagedestroy($image);
        return null;
    }
    $grid =[];
    $sum = 0.0;
    $cx = 0.0;
    $cy = 0.0;
    for ($gy = 0; $gy < $size; $gy++) {
        $row =[];
        $py = (int)floor(($gy + 0.5) * $h / $size);
        $py = max(0, min($h - 1, $py));
        for ($gx = 0; $gx < $size; $gx++) {
            $px = (int)floor(($gx + 0.5) * $w / $size);
            $px = max(0, min($w - 1, $px));
            $rgba = imagecolorat($image, $px, $py);
            $a =($rgba>>24)&0x7F;
            $r =($rgba>>16)&0xFF;
            $g =($rgba>>8)&0xFF;
            $b = $rgba&0xFF;
            $opacity =(127 - $a) / 127;
            $max = max($r, $g, $b);
            $min = min($r, $g, $b);
            $sat = $max > 0 ?($max - $min) / $max : 0;
            // Radar overlays are normally transparent outside precipitation.
            // Saturation protects against opaque monochrome basemap artefacts.
            $v = meteonexa_intel_clamp($opacity * max(0.0,($sat - .08) / .72), 0, 1);
            $row[] = $v;
            $sum+=$v;
            $cx+=$gx * $v;
            $cy+=$gy * $v;
        }
        $grid[] = $row;
    }
    imagedestroy($image);
    return['grid'=>$grid, 'sum'=>$sum, 'cx'=>$sum > 0 ? $cx / $sum : null, 'cy'=>$sum > 0 ? $cy / $sum : null, 'size'=>$size];
}
function meteonexa_radar_pair_motion(array $old, array $new) : array {
    $size = (int)$new['size'];
    $bestScore = - INF;
    $second = - INF;
    $bestDx = 0;
    $bestDy = 0;
    for ($dy = - 5; $dy<=5; $dy++) for ($dx = - 5; $dx<=5; $dx++) {
        $score = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($y = 5; $y < $size - 5; $y++) for ($x = 5; $x < $size - 5; $x++) {
            $xx = $x - $dx;
            $yy = $y - $dy;
            if ($xx < 0||$yy < 0||$xx>=$size||$yy>=$size)continue;
            $a = $old['grid'][$yy][$xx];
            $b = $new['grid'][$y][$x];
            $score+=$a * $b;
            $normA+=$a * $a;
            $normB+=$b * $b;
        }
        $corr =($normA > 0&&$normB > 0) ? $score / sqrt($normA * $normB) : 0;
        if ($corr > $bestScore) {
            $second = $bestScore;
            $bestScore = $corr;
            $bestDx = $dx;
            $bestDy = $dy;
        } elseif ($corr > $second) {
            $second = $corr;
        }
    }
    $confidence = (int)round(meteonexa_intel_clamp(($bestScore - .15) * 120 + max(0, $bestScore - $second) * 180, 8, 95));
    return['dx'=>$bestDx, 'dy'=>$bestDy, 'correlation'=>$bestScore, 'confidence'=>$confidence];
}
function meteonexa_radar_components(array $signal, float $threshold = .14, int $minPixels = 3) : array {
    $grid = (array)($signal['grid']??[]);
    $n = (int)($signal['size']??count($grid));
    if ($n < 2)return[];
    $seen =[];
    $out =[];
    for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
        $key = $y . ':' . $x;
        if (isset($seen[$key])||(float)($grid[$y][$x]??0) < $threshold)continue;
        $stack =[[$x, $y]];
        $seen[$key] = 1;
        $count = 0;
        $sum = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        $peak = 0.0;
        $minX = $x;
        $maxX = $x;
        $minY = $y;
        $maxY = $y;
        while ($stack) {
            [$xx, $yy] = array_pop($stack);
            $v = (float)($grid[$yy][$xx]??0);
            $count++;
            $sum+=$v;
            $cx+=$xx * $v;
            $cy+=$yy * $v;
            $peak = max($peak, $v);
            $minX = min($minX, $xx);
            $maxX = max($maxX, $xx);
            $minY = min($minY, $yy);
            $maxY = max($maxY, $yy);
            foreach ([[1, 0],[ - 1, 0],[0, 1],[0, - 1]] as[$dx, $dy]) {
                $nx = $xx + $dx;
                $ny = $yy + $dy;
                if ($nx < 0||$ny < 0||$nx>=$n||$ny>=$n)continue;
                $nk = $ny . ':' . $nx;
                if (isset($seen[$nk])||(float)($grid[$ny][$nx]??0) < $threshold)continue;
                $seen[$nk] = 1;
                $stack[] =[$nx, $ny];
            }
        }
        if ($count < $minPixels||$sum<=0)continue;
        $out[] =['pixels'=>$count, 'energy'=>round($sum, 3), 'peak'=>round($peak, 3), 'cx'=>round($cx / $sum, 2), 'cy'=>round($cy / $sum, 2), 'bbox'=>['minX'=>$minX, 'minY'=>$minY, 'maxX'=>$maxX, 'maxY'=>$maxY], 'width'=>$maxX - $minX + 1, 'height'=>$maxY - $minY + 1];
    }
    usort($out, static fn($a, $b)=>($b['energy']<=>$a['energy']));
    return array_slice($out, 0, 8);
}
function meteonexa_radar_multicell_tracks(array $old, array $new, float $minutes) : array {
    $before = meteonexa_radar_components($old);
    $after = meteonexa_radar_components($new);
    $tracks =[];
    $used =[];
    $minutes = max(1, $minutes);
    foreach ($after as $i=>$cell) {
        $best = null;
        $bestD = INF;
        foreach ($before as $j=>$prev) {
            $d = hypot((float)$cell['cx'] - (float)$prev['cx'], (float)$cell['cy'] - (float)$prev['cy']);
            if ($d < $bestD&&$d<=8) {
                $bestD = $d;
                $best = $j;
            }
        }
        $prev = $best===null ? null : $before[$best];
        if ($best!==null)$used[$best] =($used[$best]??0) + 1;
        $growth = $prev&&($prev['energy']??0) > 0 ? 100 *((float)$cell['energy'] - (float)$prev['energy']) / (float)$prev['energy'] : null;
        $vx = $prev ?((float)$cell['cx'] - (float)$prev['cx']) / $minutes : 0.0;
        $vy = $prev ?((float)$cell['cy'] - (float)$prev['cy']) / $minutes : 0.0;
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $direction = $prev ? $dirs[((int)round($angle / 45)) % 8] : null;
        $stage = $growth===null ? 'new' :($growth > 20 ? 'growing' :($growth < - 20 ? 'decaying' : 'stable'));
        $tracks[] =['id'=>'cell-' . substr(hash('sha256', round((float)$cell['cx'], 1) . '|' . round((float)$cell['cy'], 1)), 0, 8), 'centroid'=>['x'=>$cell['cx'], 'y'=>$cell['cy']], 'energy'=>$cell['energy'], 'peak'=>$cell['peak'], 'growthPct'=>$growth===null ? null : round($growth, 1), 'stage'=>$stage, 'direction'=>$direction, 'vxCellsMin'=>round($vx, 3), 'vyCellsMin'=>round($vy, 3), 'matched'=>$prev!==null, 'splitCandidate'=>$best!==null&&($used[$best]??0) > 1, 'trajectoryCone'=>array_map(static fn($m)=>['minute'=>$m, 'x'=>round((float)$cell['cx'] + $vx * $m, 2), 'y'=>round((float)$cell['cy'] + $vy * $m, 2), 'radiusCells'=>round(1.2 + $m * .035, 2)],[15, 30, 45, 60, 90])];
    }
    $merge = false;
    if (count($before) > count($after)&&count($after) > 0)$merge = true;
    return['available'=>$tracks!==[], 'cells'=>$tracks, 'cellCount'=>count($tracks), 'splitDetected'=>count(array_filter($tracks, static fn($c)=>!empty($c['splitCandidate']))) > 0, 'mergeDetected'=>$merge, 'method'=>'connected-components-centroid-tracking'];
}
/**
 * Radar Object Tracking 3.0.
 *
 * Tracks connected radar objects over all usable archived frames using a cost
 * that combines predicted position, area, energy and peak intensity. It is
 * deliberately deterministic and runs in shadow mode by default. Persistent
 * IDs are derived from the oldest observed ancestor so re-processing the same
 * frame sequence remains stable. Split/merge candidates are exposed instead
 * of being collapsed into one synthetic cell.
 */
function meteonexa_radar_object_tracks_v3(array $prepared) : array {
    if (count($prepared) < 2)return['available'=>false, 'cells'=>[], 'method'=>'object-tracking-v3', 'reason'=>'insufficient_frames'];
    $frames = array_reverse($prepared);
    $tracks =[];
    $active =[];
    $previousComponents =[];
    $splitDetected = false;
    $mergeDetected = false;
    foreach ($frames as $frameIndex=>$frame) {
        $time = (int)($frame['time']??0);
        $signal = (array)($frame['grid']??[]);
        $components = meteonexa_radar_components($signal, .13, 3);
        if (!$components)continue;
        if ($frameIndex===0||!$active) {
            foreach ($components as $ci=>$c) {
                $id = 'obj-' . substr(hash('sha256', $time . '|' . round((float)$c['cx'], 1) . '|' . round((float)$c['cy'], 1) . '|' . $ci), 0, 10);
                $tracks[$id] =['id'=>$id, 'history'=>[['time'=>$time, 'cell'=>$c]], 'parents'=>[], 'mergedFrom'=>[]];
                $active[$id] = $c;
            }
            $previousComponents = $active;
            continue;
        }
        $dt = max(1,($time - (int)($frames[$frameIndex - 1]['time']??($time - 300))) / 60);
        $candidates =[];
        $nearbyByComponent =[];
        foreach ($components as $ci=>$c) {
            foreach ($active as $id=>$prev) {
                $history = $tracks[$id]['history']??[];
                $last = end($history);
                $vx = 0.0;
                $vy = 0.0;
                if (count($history)>=2) {
                    $h2 = $history[count($history) - 2];
                    $dth = max(1,((int)$last['time'] - (int)$h2['time']) / 60);
                    $vx =((float)$last['cell']['cx'] - (float)$h2['cell']['cx']) / $dth;
                    $vy =((float)$last['cell']['cy'] - (float)$h2['cell']['cy']) / $dth;
                }
                $px = (float)$prev['cx'] + $vx * $dt;
                $py = (float)$prev['cy'] + $vy * $dt;
                $dist = hypot((float)$c['cx'] - $px, (float)$c['cy'] - $py);
                $areaPenalty = abs(log(max(.2, (float)$c['pixels'] / max(1, (float)$prev['pixels'])))) * 2.2;
                $energyPenalty = abs(log(max(.2, (float)$c['energy'] / max(.05, (float)$prev['energy'])))) * 1.6;
                $peakPenalty = abs((float)$c['peak'] - (float)$prev['peak']) * 2.0;
                $cost = $dist + $areaPenalty + $energyPenalty + $peakPenalty;
                if ($dist<=10.5) {
                    $candidates[] =['cost'=>$cost, 'id'=>$id, 'ci'=>$ci];
                    $nearbyByComponent[$ci][] =['id'=>$id, 'cost'=>$cost];
                }
            }
        }
        usort($candidates, static fn($a, $b)=>$a['cost']<=>$b['cost']);
        $usedTracks =[];
        $usedComponents =[];
        $newActive =[];
        foreach ($candidates as $cand) {
            $id = $cand['id'];
            $ci = $cand['ci'];
            if (isset($usedTracks[$id])||isset($usedComponents[$ci])||$cand['cost'] > 13.5)continue;
            $usedTracks[$id] = true;
            $usedComponents[$ci] = true;
            $tracks[$id]['history'][] =['time'=>$time, 'cell'=>$components[$ci]];
            $newActive[$id] = $components[$ci];
        }
        foreach ($components as $ci=>$c) {
            if (isset($usedComponents[$ci]))continue;
            $near = $nearbyByComponent[$ci]??[];
            usort($near, static fn($a, $b)=>$a['cost']<=>$b['cost']);
            $parents = array_values(array_unique(array_map(static fn($r)=>(string)$r['id'], array_filter($near, static fn($r)=>(float)$r['cost']<=11.5))));
            $id = 'obj-' . substr(hash('sha256', $time . '|' . round((float)$c['cx'], 1) . '|' . round((float)$c['cy'], 1) . '|' . $ci), 0, 10);
            $tracks[$id] =['id'=>$id, 'history'=>[['time'=>$time, 'cell'=>$c]], 'parents'=>$parents, 'mergedFrom'=>[]];
            if (count($parents)===1)$splitDetected = true;
            if (count($parents) > 1) {
                $mergeDetected = true;
                $tracks[$id]['mergedFrom'] = $parents;
            }
            $newActive[$id] = $c;
        }
        // One previously tracked object near several current objects indicates a split.
        foreach ($active as $oldId=>$oldCell) {
            $nearCount = 0;
            foreach ($components as $c) {
                if (hypot((float)$c['cx'] - (float)$oldCell['cx'], (float)$c['cy'] - (float)$oldCell['cy'])<=8.5)$nearCount++;
            }
            if ($nearCount>=2)$splitDetected = true;
        }
        $active = $newActive;
        $previousComponents = $active;
    }
    if (!$active)return['available'=>false, 'cells'=>[], 'method'=>'object-tracking-v3', 'reason'=>'no_objects'];
    $cells =[];
    $center =((int)($prepared[0]['grid']['size']??32) - 1) / 2;
    foreach ($active as $id=>$cell) {
        $track = $tracks[$id];
        $history = $track['history'];
        $n = count($history);
        $vx = 0.0;
        $vy = 0.0;
        $prevVx = null;
        $prevVy = null;
        $accel = null;
        if ($n>=2) {
            $a = $history[$n - 2];
            $b = $history[$n - 1];
            $dt = max(1,((int)$b['time'] - (int)$a['time']) / 60);
            $vx =((float)$b['cell']['cx'] - (float)$a['cell']['cx']) / $dt;
            $vy =((float)$b['cell']['cy'] - (float)$a['cell']['cy']) / $dt;
            if ($n>=3) {
                $p = $history[$n - 3];
                $dt2 = max(1,((int)$a['time'] - (int)$p['time']) / 60);
                $prevVx =((float)$a['cell']['cx'] - (float)$p['cell']['cx']) / $dt2;
                $prevVy =((float)$a['cell']['cy'] - (float)$p['cell']['cy']) / $dt2;
                $accel = hypot($vx - $prevVx, $vy - $prevVy) / max(1, $dt);
            }
        }
        $growth = null;
        if ($n>=2) {
            $old = (float)$history[$n - 2]['cell']['energy'];
            if ($old > 0)$growth = 100 *((float)$cell['energy'] - $old) / $old;
        }
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $direction = $n>=2 ? $dirs[((int)round($angle / 45)) % 8] : null;
        $speed = hypot($vx, $vy);
        $eta = null;
        if ($speed > .015) {
            $toX = $center - (float)$cell['cx'];
            $toY = $center - (float)$cell['cy'];
            $dot = $toX * $vx + $toY * $vy;
            if ($dot > 0) {
                $eta = (int)round($dot /($speed * $speed));
                if ($eta < 0||$eta > 180)$eta = null;
            }
        }
        $stage = $growth===null ? 'new' :($growth > 18 ? 'growing' :($growth < - 18 ? 'decaying' : 'stable'));
        $ageMinutes = max(0,((int)$history[$n - 1]['time'] - (int)$history[0]['time']) / 60);
        $confidence = (int)round(meteonexa_intel_clamp(38 + min(30, $n * 8) + min(18, (float)$cell['peak'] * 20) - min(20,($accel??0) * 35), 15, 96));
        $cone =[];
        foreach ([15, 30, 45, 60, 90] as $minute) {
            $uncertainty = 1.2 + $minute *(1 - $confidence / 100) * .05 +($accel??0) * $minute * .5;
            $cone[] =['minute'=>$minute, 'x'=>round((float)$cell['cx'] + $vx * $minute, 2), 'y'=>round((float)$cell['cy'] + $vy * $minute, 2), 'radiusCells'=>round($uncertainty, 2)];
        }
        $cells[] =['id'=>$id, 'centroid'=>['x'=>$cell['cx'], 'y'=>$cell['cy']], 'areaPixels'=>(int)$cell['pixels'], 'bbox'=>$cell['bbox']??null, 'energy'=>$cell['energy'], 'peak'=>$cell['peak'], 'growthPct'=>$growth===null ? null : round($growth, 1), 'stage'=>$stage, 'direction'=>$direction, 'vxCellsMin'=>round($vx, 3), 'vyCellsMin'=>round($vy, 3), 'speedCellsMin'=>round($speed, 3), 'accelerationCellsMin2'=>$accel===null ? null : round($accel, 4), 'etaMinutes'=>$eta, 'trackConfidence'=>$confidence, 'ageMinutes'=>round($ageMinutes, 1), 'frameCount'=>$n, 'parentIds'=>$track['parents']??[], 'mergedFrom'=>$track['mergedFrom']??[], 'trajectoryCone'=>$cone];
    }
    usort($cells, static fn($a, $b)=>($b['energy']<=>$a['energy']));
    $dominant = $cells[0]??null;
    return['available'=>$cells!==[], 'cells'=>$cells, 'cellCount'=>count($cells), 'splitDetected'=>$splitDetected, 'mergeDetected'=>$mergeDetected, 'dominantEtaMinutes'=>$dominant['etaMinutes']??null, 'dominantCellId'=>$dominant['id']??null, 'method'=>'object-tracking-v3', 'shadowSafe'=>true];
}
function meteonexa_radar3_historical_guard(PDO $pdo, string $deviceId, string $locationKey, int $minimumSamples = 20) : array {
    $out =['available'=>false, 'allow'=>true, 'reason'=>'learning', 'minimumSamples'=>$minimumSamples, 'radar2'=>null, 'radar3'=>null];
    if (!meteonexa_db_table_exists($pdo, 'radar_eta_predictions')||!meteonexa_db_column_exists($pdo, 'radar_eta_predictions', 'algorithm'))return $out;
    try {
        $st = $pdo->prepare("SELECT algorithm,COUNT(*) samples,AVG(absolute_error_minutes) mae,AVG(CASE WHEN absolute_error_minutes<=tolerance_minutes THEN 1.0 ELSE 0.0 END) within_ratio FROM radar_eta_predictions WHERE device_id=:d AND location_key=:l AND status='verified' AND absolute_error_minutes IS NOT NULL AND algorithm IN ('radar-v2','radar-v3') GROUP BY algorithm");
        $st->execute([':d'=>$deviceId, ':l'=>$locationKey]);
        $rows =[];
        foreach ($st->fetchAll() as $r) {
            $rows[(string)$r['algorithm']] =['samples'=>(int)$r['samples'], 'maeMinutes'=>is_numeric($r['mae']??null) ? round((float)$r['mae'], 1) : null, 'withinTolerancePct'=>is_numeric($r['within_ratio']??null) ? round(100 * (float)$r['within_ratio'], 1) : null];
        }
        $out['radar2'] = $rows['radar-v2']??null;
        $out['radar3'] = $rows['radar-v3']??null;
        if (!$out['radar2']||!$out['radar3']||$out['radar2']['samples'] < $minimumSamples||$out['radar3']['samples'] < $minimumSamples)return $out;
        $out['available'] = true;
        $v2 = $out['radar2'];
        $v3 = $out['radar3'];
        $maeRegression = $v2['maeMinutes']!==null&&$v3['maeMinutes']!==null&&$v3['maeMinutes'] > max($v2['maeMinutes'] + 2.0, $v2['maeMinutes'] * 1.15);
        $toleranceRegression = $v2['withinTolerancePct']!==null&&$v3['withinTolerancePct']!==null&&$v3['withinTolerancePct'] < $v2['withinTolerancePct'] - 8.0;
        if ($maeRegression||$toleranceRegression) {
            $out['allow'] = false;
            $out['reason'] = $maeRegression ? 'mae_regression' : 'tolerance_regression';
            return $out;
        }
        $out['reason'] = 'verified_not_worse';
        return $out;
    } catch (Throwable $ignored) {
        return $out;
    }
}
function meteonexa_radar3_production_gate(PDO $pdo, string $deviceId, string $locationKey, array $radar3, int $radarAgeMinutes) : array {
    $result =['eligible'=>false, 'reason'=>'unavailable', 'quality'=>null, 'history'=>null];
    if (empty($radar3['available'])||empty($radar3['cells'])||!is_array($radar3['cells']))return $result;
    if ($radarAgeMinutes > 15) {
        $result['reason'] = 'stale_radar';
        return $result;
    }
    $best = null;
    foreach ($radar3['cells'] as $cell) {
        if (!is_array($cell))continue;
        $confidence = (int)($cell['trackConfidence']??0);
        $frames = (int)($cell['frameCount']??0);
        $cone = count((array)($cell['trajectoryCone']??[]));
        $energy = (float)($cell['energy']??0);
        if ($frames < 2||$confidence < 55||$cone < 5||$energy<=0)continue;
        if ($best===null||$confidence > (int)($best['trackConfidence']??0)||($confidence===(int)($best['trackConfidence']??0)&&$energy > (float)($best['energy']??0)))$best = $cell;
    }
    if (!$best) {
        $result['reason'] = 'track_quality';
        return $result;
    }
    $history = meteonexa_radar3_historical_guard($pdo, $deviceId, $locationKey, 20);
    $result['history'] = $history;
    $result['quality'] =['cellId'=>$best['id']??null, 'trackConfidence'=>(int)($best['trackConfidence']??0), 'frameCount'=>(int)($best['frameCount']??0), 'energy'=>round((float)($best['energy']??0), 3), 'radarAgeMinutes'=>$radarAgeMinutes];
    if (empty($history['available'])) {
        $result['reason'] = 'probation_insufficient_history';
        return $result;
    }
    if (empty($history['allow'])) {
        $result['reason'] = 'historical_' . $history['reason'];
        return $result;
    }
    $result['eligible'] = true;
    $result['reason'] = 'verified_active';
    return $result;
}
function meteonexa_radar_motion(PDO $pdo, string $deviceId, float $lat, float $lon) : array {
    if (!function_exists('imagecreatefromstring'))return['available'=>false, 'reason'=>'gd'];
    try {
        $stmt = $pdo->prepare('SELECT id,latitude,longitude FROM radar_archive_locations WHERE device_id=:device AND active=1 ORDER BY updated_at DESC');
        $stmt->execute([':device'=>$deviceId]);
        $best = null;
        $bestDistance = INF;
        foreach ($stmt->fetchAll() as $row) {
            $d = haversine_km($lat, $lon, (float)$row['latitude'], (float)$row['longitude']);
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $best = $row;
            }
        }
        if (!$best||$bestDistance > 80)return['available'=>false, 'reason'=>'no_archive'];
        $frames = $pdo->prepare('SELECT frame_time,image_path FROM radar_archive_frames WHERE location_id=:id ORDER BY frame_time DESC LIMIT 4');
        $frames->execute([':id'=>$best['id']]);
        $rows = $frames->fetchAll();
        if (count($rows) < 2) {
            meteonexa_observability_event('radar', 'motion', 'insufficient_frames',['frames'=>count($rows)]);
            return['available'=>false, 'reason'=>'insufficient_frames'];
        }
        $prepared =[];
        foreach ($rows as $row) {
            $grid = meteonexa_radar_signal_grid((string)$row['image_path']);
            if ($grid&&$grid['sum']>=1.5)$prepared[] =['time'=>(int)$row['frame_time'], 'grid'=>$grid];
        }
        if (count($prepared) < 2) {
            meteonexa_observability_event('radar', 'motion', 'weak_signal',['usableFrames'=>count($prepared)]);
            return['available'=>false, 'reason'=>'weak_signal'];
        }
        $vectors =[];
        for ($i = 0; $i < count($prepared) - 1; $i++) {
            $new = $prepared[$i];
            $old = $prepared[$i + 1];
            $dt = max(60, $new['time'] - $old['time']);
            $pair = meteonexa_radar_pair_motion($old['grid'], $new['grid']);
            if ($pair['confidence'] < 12)continue;
            $minutes = $dt / 60;
            $weight = max(.05, (float)$pair['correlation']) * max(.15, $pair['confidence'] / 100);
            $vectors[] =['vx'=>$pair['dx'] / $minutes, 'vy'=>$pair['dy'] / $minutes, 'dx'=>$pair['dx'], 'dy'=>$pair['dy'], 'dt'=>$dt, 'confidence'=>$pair['confidence'], 'weight'=>$weight];
        }
        if (!$vectors)return['available'=>false, 'reason'=>'motion_uncertain'];
        $sumW = array_sum(array_column($vectors, 'weight'));
        $vx = 0.0;
        $vy = 0.0;
        $confidence = 0.0;
        foreach ($vectors as $v) {
            $w = $v['weight'];
            $vx+=$v['vx'] * $w;
            $vy+=$v['vy'] * $w;
            $confidence+=$v['confidence'] * $w;
        }
        $vx/=$sumW;
        $vy/=$sumW;
        $confidence/=$sumW;
        // Reward consistent vectors and penalize rapid direction changes.
        $spread = 0.0;
        foreach ($vectors as $v)$spread+=sqrt(($v['vx'] - $vx)**2 +($v['vy'] - $vy)**2) * $v['weight'];
        $spread/=$sumW;
        $confidence = (int)round(meteonexa_intel_clamp($confidence - max(0, $spread * 9) + min(8,(count($vectors) - 1) * 3), 10, 96));
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $dir = $dirs[((int)round($angle / 45)) % 8];
        $latest = $prepared[0]['grid'];
        $center =((int)$latest['size'] - 1) / 2;
        $eta = null;
        $speed2 = $vx * $vx + $vy * $vy;
        if ($speed2 > .0001&&$latest['cx']!==null&&$latest['cy']!==null) {
            $toX = $center - $latest['cx'];
            $toY = $center - $latest['cy'];
            $dot = $toX * $vx + $toY * $vy;
            if ($dot > 0) {
                $eta = (int)round($dot / $speed2);
                if ($eta < 0||$eta > 180)$eta = null;
            }
        }
        $avgFrameMinutes = array_sum(array_map(static fn($v)=>$v['dt'] / 60, $vectors)) / count($vectors);
        $multiCells = meteonexa_radar_multicell_tracks($prepared[1]['grid'], $prepared[0]['grid'], max(1,($prepared[0]['time'] - $prepared[1]['time']) / 60));
        $radar3 = meteonexa_radar_object_tracks_v3($prepared);
        $radar3RequestedMode = 'active';
        if (function_exists('load_config')) {
            $cfg = load_config();
            $radar3RequestedMode = (string)($cfg['radar3']['mode']??'active');
        }
        $radarAgeMinutes = max(0, (int)round((time() - (int)$prepared[0]['time']) / 60));
        $locationKey = meteonexa_intelligence_location_key($lat, $lon);
        $radar3Gate = meteonexa_radar3_production_gate($pdo, $deviceId, $locationKey, $radar3, $radarAgeMinutes);
        $radar3Mode = $radar3RequestedMode;
        if ($radar3RequestedMode==='active') {
            if (!empty($radar3Gate['eligible'])) {
                $multiCells = $radar3;
                $radar3Mode = 'active';
            } elseif (($radar3Gate['reason']??'')==='probation_insufficient_history') {
                $radar3Mode = 'probation-v2';
            } else {
                $radar3Mode = 'active-fallback-v2';
            }
        }
        if (function_exists('meteonexa_observability_event'))meteonexa_observability_event('radar', 'radar3', $radar3Mode,['reason'=>(string)($radar3Gate['reason']??'unknown'), 'radarAgeMinutes'=>$radarAgeMinutes, 'cellCount'=>(int)($radar3['cellCount']??0)]);
        $centerIndex = (int)round($center);
        $centerSamples =[];
        for ($yy = max(0, $centerIndex - 1); $yy<=min((int)$latest['size'] - 1, $centerIndex + 1); $yy++) for ($xx = max(0, $centerIndex - 1); $xx<=min((int)$latest['size'] - 1, $centerIndex + 1); $xx++)$centerSamples[] = (float)($latest['grid'][$yy][$xx]??0);
        $centerSignal = $centerSamples ? array_sum($centerSamples) / count($centerSamples) : 0.0;
        return['available'=>true, 'multiCellTracking'=>$multiCells, 'radar3Tracking'=>$radar3, 'radar3Mode'=>$radar3Mode, 'radar3RequestedMode'=>$radar3RequestedMode, 'radar3ProductionGate'=>$radar3Gate, 'radarObservation'=>['observedAt'=>gmdate('c', (int)$prepared[0]['time']), 'centerSignal'=>round($centerSignal, 3), 'archiveDistanceKm'=>round($bestDistance, 1), 'independentFromTrajectory'=>true], 'direction'=>$dir, 'angle'=>round($angle, 1), 'vxCellsMin'=>round($vx, 3), 'vyCellsMin'=>round($vy, 3), 'confidence'=>$confidence, 'etaMinutes'=>$eta, 'frameMinutes'=>round($avgFrameMinutes, 1), 'vectorSamples'=>count($vectors), 'archiveDistanceKm'=>round($bestDistance, 1), 'method'=>'multi-frame-block-correlation', 'latestFrameAt'=>gmdate('c', (int)$prepared[0]['time']), 'ageMinutes'=>$radarAgeMinutes];
    } catch (Throwable $ignored) {
        meteonexa_observability_event('radar', 'motion', 'analysis_failed',['class'=>get_class($ignored)]);
        return['available'=>false, 'reason'=>'analysis_failed'];
    }
}
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
            // 6h stale-if-error window; warnings are filtered by validity/lifecycle later.
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
                        // MeteoAlarm Atom entries expose CAP extension fields. Read by
                        // local-name so the parser remains robust to namespace prefixes.
                        $event = meteonexa_official_atom_local($entry, 'event');
                        $effective = meteonexa_official_atom_local($entry, 'effective');
                        $expires = meteonexa_official_atom_local($entry, 'expires');
                        $capSeverity = meteonexa_official_atom_local($entry, 'severity');
                        $area = meteonexa_official_atom_local($entry, 'areaDesc');
                        $certainty = meteonexa_official_atom_local($entry, 'certainty');
                        $text = trim($title . ' ' . $summary . ' ' . $event . ' ' . $capSeverity . ' ' . $area);
                        $severity = meteonexa_official_severity_from_text($text);
                        $fresh[] =['id'=>$id!=='' ? $id : hash('sha256', $title . $updated . $effective . $expires), 'title'=>$title, 'summary'=>$summary, 'event'=>$event, 'area'=>$area, 'certainty'=>$certainty, 'updatedAt'=>$updated, 'startsAt'=>$effective ? : null, 'endsAt'=>$expires ? : null, 'severity'=>$severity, 'source'=>'MeteoAlarm', 'geospatialMatch'=>false,];
                        if (count($fresh)>=100)break;
                    }
                    // A syntactically valid Atom document, including a valid empty feed,
                    // is authoritative. Never replace a good cache when HTTP/XML failed.
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
    foreach ($rows as $row) {
        if (!is_array($row))continue;
        // Never promote an explicit green/no-warning entry to yellow merely
        // because it mentions the requested region.
        if (strtolower((string)($row['severity']??''))==='green')continue;
        $hay = strtolower(trim((string)($row['title']??'') . ' ' . (string)($row['summary']??'') . ' ' . (string)($row['area']??'')));
        $locationMatch = $locationNeedle!==''&&(function_exists('mb_strlen') ? mb_strlen($locationNeedle, 'UTF-8') : strlen($locationNeedle))>=4&&str_contains($hay, $locationNeedle);
        $adminMatch = $adminNeedle!==''&&(function_exists('mb_strlen') ? mb_strlen($adminNeedle, 'UTF-8') : strlen($adminNeedle))>=4&&str_contains($hay, $adminNeedle);
        if ($locationMatch) {
            $row['matchScope'] = 'location-text';
            $relevant[] = $row;
        } elseif ($adminMatch) {
            $row['matchScope'] = 'regional-text';
            $regional[] = $row;
        }
    }
    return['available'=>true, 'source'=>'MeteoAlarm', 'relevant'=>$relevant, 'regionalAdvisories'=>$regional, 'countryFeedCount'=>count($rows), 'matchedByAreaText'=>$relevant!==[], 'regionalTextMatch'=>$regional!==[], 'geospatial'=>false, 'providerFresh'=>$providerFresh||(!$staleFallback&&$cacheAge!==null&&$cacheAge < 600), 'staleProviderCache'=>$staleFallback, 'cacheAgeSeconds'=>$cacheAge];
}
function meteonexa_intelligence_analyze(array $weather, ? array $air, array $profile, array $context =[]) : array {
    $hourly = (array)($weather['hourly']??[]);
    $times = meteonexa_intel_arr($hourly, 'time');
    $start = 0;
    $now = time();
    foreach ($times as $i=>$time) {
        $ts = meteonexa_intel_time_utc($time);
        if ($ts!==null&&$ts>=$now - 1800) {
            $start = $i;
            break;
        }
    }
    $forecastWindowHours = max(24, min(72, (int)($context['forecastWindowHours']??24)));
    $slice = static fn(string $key, int $count = 0) : array=>array_slice(array_map('floatval', (array)($hourly[$key]??[])), $start, $count > 0 ? $count : $forecastWindowHours);
    $windowTimes = array_slice($times, $start, $forecastWindowHours);
    $rainProb = $slice('precipitation_probability');
    $rainMm = $slice('precipitation');
    $wind = $slice('wind_gusts_10m');
    $temp = $slice('temperature_2m');
    $snow = $slice('snowfall');
    $vis = $slice('visibility');
    $cape = $slice('cape');
    $codes = array_map('intval', array_slice((array)($hourly['weather_code']??[]), $start, $forecastWindowHours));
    $nearHours = 3;
    $nearRainProb = array_slice($rainProb, 0, $nearHours);
    $nearRainMm = array_slice($rainMm, 0, $nearHours);
    $nearCape = array_slice($cape, 0, $nearHours);
    $nearCodes = array_slice($codes, 0, $nearHours);
    $maxRain = $rainProb ? max($rainProb) : 0;
    $maxRainNear = $nearRainProb ? max($nearRainProb) : 0;
    $rainProb24 = array_slice($rainProb, 0, 24);
    $maxRain24 = $rainProb24 ? max($rainProb24) : 0;
    $maxMm = $rainMm ? max($rainMm) : 0;
    $maxMmNear = $nearRainMm ? max($nearRainMm) : 0;
    $maxWind = $wind ? max($wind) : 0;
    $maxTemp = $temp ? max($temp) : 0;
    $minTemp = $temp ? min($temp) : 99;
    $maxSnow = $snow ? max($snow) : 0;
    $minVis = $vis ? min($vis) / 1000 : 99;
    $maxCape = $cape ? max($cape) : 0;
    $maxCapeNear = $nearCape ? max($nearCape) : 0;
    $minute = (array)($weather['minutely_15']??[]);
    $minuteTimes = (array)($minute['time']??[]);
    $minuteMm = array_map('floatval', (array)($minute['precipitation']??[]));
    $minuteSnow = array_map('floatval', (array)($minute['snowfall']??[]));
    $minuteCodes = array_map('intval', (array)($minute['weather_code']??[]));
    $firstWet = - 1;
    $lastWet = - 1;
    $peak = 0.0;
    foreach ($minuteMm as $i=>$mm) {
        if ($mm>=.05) {
            if ($firstWet < 0)$firstWet = $i;
            $lastWet = $i;
            $peak = max($peak, $mm);
        }
    }
    $eta = null;
    $startsAt = null;
    $endsAt = null;
    if ($firstWet>=0&&isset($minuteTimes[$firstWet])) {
        $ts = meteonexa_intel_time_utc($minuteTimes[$firstWet]);
        if ($ts!==null) {
            $eta = max(0, (int)round(($ts - $now) / 60));
            $startsAt = gmdate('c', $ts);
        }
    }
    if ($lastWet>=0&&isset($minuteTimes[$lastWet])) {
        $ts = meteonexa_intel_time_utc($minuteTimes[$lastWet]);
        if ($ts!==null)$endsAt = gmdate('c', $ts + 15 * 60);
    }
    $motion = (array)($context['radarMotion']??[]);
    if (($motion['available']??false)&&isset($motion['etaMinutes'])&&is_numeric($motion['etaMinutes'])) {
        $motionEta = (int)$motion['etaMinutes'];
        if ($motionEta>=0&&$motionEta<=120)$eta = $eta===null ? $motionEta : (int)round($eta * .65 + $motionEta * .35);
    }
    $lightning = (array)($context['lightning']??[]);
    $stormCode = static fn($c) : bool=>in_array((int)$c,[95, 96, 99], true);
    // WMO 96/99 explicitly encode thunderstorm with hail. Hail is deliberately
    // not inferred from CAPE/probability alone: that would create false alarms.
    $hailCode = static fn($c) : bool=>in_array((int)$c,[96, 99], true);
    $thunderNear = in_array(true, array_map($stormCode, $nearCodes), true)||$maxCapeNear>=800||(($lightning['recent30m']??0)>=2&&($lightning['nearestKm']??999)<=40);
    $thunderWindow = in_array(true, array_map($stormCode, $codes), true)||$maxCape>=800;
    // Air-quality arrays may start at midnight: only evaluate the next 24h.
    $aqi = 0.0;
    if ($air) {
        $airH = (array)($air['hourly']??[]);
        $airTimes = (array)($airH['time']??[]);
        $airStart = 0;
        foreach ($airTimes as $i=>$time) {
            $ts = meteonexa_intel_time_utc($time);
            if ($ts!==null&&$ts>=$now - 1800) {
                $airStart = $i;
                break;
            }
        }
        $values = array_map('floatval', array_slice((array)($airH['european_aqi']??[]), $airStart, 24));
        if ($values)$aqi = max($values);
    }
    $thresholds = is_array($profile['thresholds']??null) ? $profile['thresholds'] : $profile;
    $rainThreshold = (float)($thresholds['rain']??55);
    $windThreshold = (float)($thresholds['wind']??55);
    $heatThreshold = (float)($thresholds['heat']??35);
    $coldThreshold = (float)($thresholds['cold']??2);
    $enabled = is_array($profile['events']??null) ? $profile['events'] :[];
    $isEnabled = static fn(string $key) : bool=>!array_key_exists($key, $enabled)||(bool)$enabled[$key];
    $events =[];
    $add = static function(string $type, string $severity, float $confidence, string $title, string $body, array $extra =[])use(&$events) {
        $events[] = array_merge(['type'=>$type, 'severity'=>$severity, 'confidence'=>(int)round(meteonexa_intel_clamp($confidence, 1, 99)), 'title'=>$title, 'body'=>$body], $extra);
    };
    // Intelligence pages may inspect up to 72 hours, while the push dispatcher
    // keeps the default 24-hour alert horizon. Every forecast event receives a
    // concrete UTC time window when the hourly data can identify one.
    $eventWindow = static function(callable $matches)use($windowTimes) : array {
        $first = - 1;
        $last = - 1;
        $gap = 0;
        foreach ($windowTimes as $i=>$unused) {
            $hit = (bool)$matches($i);
            if ($hit) {
                if ($first < 0)$first = $i;
                $last = $i;
                $gap = 0;
                continue;
            }
            if ($first>=0) {
                $gap++;
                if ($gap > 1)break;
            }
        }
        if ($first < 0||!isset($windowTimes[$first]))return['startsAt'=>null, 'endsAt'=>null, 'startIndex'=> - 1, 'endIndex'=> - 1];
        $startTs = meteonexa_intel_time_utc($windowTimes[$first]);
        $endTs = isset($windowTimes[$last]) ? meteonexa_intel_time_utc($windowTimes[$last]) : null;
        return['startsAt'=>$startTs===null ? null : gmdate('c', $startTs), 'endsAt'=>$endTs===null ? null : gmdate('c', $endTs + 3600), 'startIndex'=>$first, 'endIndex'=>$last];
    };
    $rainWindow = $eventWindow(static fn(int $i) : bool=>((float)($rainProb[$i]??0)>=$rainThreshold)||((float)($rainMm[$i]??0)>=.1));
    $stormWindow = $eventWindow(static fn(int $i) : bool=>$stormCode((int)($codes[$i]??0))||((float)($cape[$i]??0)>=800));
    $hailWindow = $eventWindow(static fn(int $i) : bool=>$hailCode((int)($codes[$i]??0)));
    $snowWindow = $eventWindow(static fn(int $i) : bool=>((float)($snow[$i]??0) > .05)||in_array((int)($codes[$i]??0),[71, 73, 75, 77, 85, 86], true));
    $eventHorizon = static function( ? string $startIso)use($now) : string {
        if (!$startIso)return 'forecast';
        $ts = meteonexa_intel_time_utc($startIso);
        return $ts!==null&&$ts > $now + 86400 ? 'outlook' : 'forecast';
    };
    $hyper = (array)($context['hyperlocal']??[]);
    if (($hyper['available']??false)) {
        $ob = (array)($hyper['observation']??[]);
        if (isset($ob['gust'])&&is_numeric($ob['gust']))$maxWind = max($maxWind, (float)$ob['gust']);
        if (isset($ob['temperature'])&&is_numeric($ob['temperature'])) {
            $maxTemp = max($maxTemp, (float)$ob['temperature']);
            $minTemp = min($minTemp, (float)$ob['temperature']);
        }
        if (isset($ob['rain'])&&is_numeric($ob['rain'])&&(float)$ob['rain'] > .01) {
            $eta = 0;
            $startsAt = $startsAt??gmdate('c');
            $peak = max($peak, (float)$ob['rain']);
            $maxRain = max($maxRain, 92);
            $maxRainNear = max($maxRainNear, 92);
            $maxMm = max($maxMm, (float)$ob['rain']);
            $maxMmNear = max($maxMmNear, (float)$ob['rain']);
        }
    }
    $satellite = (array)($context['satellite']??[]);
    $baseConfidence = (float)($context['accuracy']['score']??58);
    if (($motion['available']??false))$baseConfidence = $baseConfidence * .8 + (float)($motion['confidence']??50) * .2;
    if (($hyper['available']??false))$baseConfidence = min(96, $baseConfidence + 4);
    if (($satellite['available']??false)&&$maxRainNear>=35) {
        $support = (float)($satellite['support']??0);
        $baseConfidence = min(96, $baseConfidence + max(0, min(5, $support * 6)));
    }
    if ($isEnabled('rain')) {
        $rainNowcast =($eta!==null&&$eta<=60)||$maxRainNear>=$rainThreshold;
        if ($rainNowcast) {
            $severity = $peak>=2||$maxMmNear>=5 ? 'orange' : 'yellow';
            $add('rain', $severity, $baseConfidence +($eta!==null ? 12 : 0), meteonexa_backend_text('intelligence.event.rain.now.title'), $eta!==null ? meteonexa_backend_text('intelligence.event.rain.now.eta',['minutes'=>$eta]) : meteonexa_backend_text('intelligence.event.rain.now.probability',['value'=>round($maxRainNear)]),['horizon'=>'nowcast', 'etaMinutes'=>$eta, 'startsAt'=>$startsAt, 'endsAt'=>$endsAt, 'peak15m'=>round($peak, 2)]);
        } elseif ($maxRain>=$rainThreshold) {
            $rainHorizon = $eventHorizon($rainWindow['startsAt']);
            $hoursLabel = $forecastWindowHours > 24 ? $forecastWindowHours : 24;
            $add('rain', $maxMm>=8 ? 'orange' : 'yellow', $baseConfidence, meteonexa_backend_text('intelligence.event.rain.forecast.title'), meteonexa_backend_text('intelligence.event.rain.forecast.body',['value'=>round($maxRain), 'hours'=>$hoursLabel]),['horizon'=>$rainHorizon, 'etaMinutes'=>null, 'startsAt'=>$rainWindow['startsAt'], 'endsAt'=>$rainWindow['endsAt'], 'peak15m'=>round($peak, 2), 'rainProbability'=>round($maxRain)]);
        }
    }
    if ($isEnabled('hail')) {
        $currentCode = (int)($weather['current']['weather_code']??0);
        $hailMinuteIndex = - 1;
        foreach ($minuteCodes as $i=>$minuteCode) {
            if ($hailCode($minuteCode)) {
                $hailMinuteIndex = $i;
                break;
            }
        }
        $hailNear = $hailCode($currentCode)||$hailMinuteIndex>=0||in_array(true, array_map($hailCode, $nearCodes), true);
        $hailWindowDetected = in_array(true, array_map($hailCode, $codes), true);
        if ($hailNear||$hailWindowDetected) {
            $hailStarts = $hailWindow['startsAt']??null;
            $hailEnds = $hailWindow['endsAt']??null;
            $hailEta = null;
            if ($hailMinuteIndex>=0&&isset($minuteTimes[$hailMinuteIndex])) {
                $hailTs = meteonexa_intel_time_utc($minuteTimes[$hailMinuteIndex]);
                if ($hailTs!==null) {
                    $hailEta = max(0, (int)round(($hailTs - $now) / 60));
                    $hailStarts = gmdate('c', $hailTs);
                }
            }
            if ($hailCode($currentCode)) {
                $hailEta = 0;
                $hailStarts = $hailStarts??gmdate('c');
            }
            $nearest = $lightning['nearestKm']??null;
            $hailHasSevereCode = $currentCode===99||in_array(99, $minuteCodes, true)||in_array(99, $codes, true);
            $hailSeverity = $hailHasSevereCode||($hailNear&&$nearest!==null&&(float)$nearest<=15) ? 'red' : 'orange';
            $hailBody = $hailEta!==null&&$hailEta > 0 ? meteonexa_backend_text('intelligence.event.hail.eta',['minutes'=>$hailEta]) : meteonexa_backend_text($hailEta===0 ? 'intelligence.event.hail.now' : 'intelligence.event.hail.forecast');
            $add('hail', $hailSeverity, $baseConfidence +($hailNear ? 14 : 7), meteonexa_backend_text('intelligence.event.hail.title'), $hailBody,['horizon'=>$hailNear ? 'nowcast' : $eventHorizon($hailStarts), 'etaMinutes'=>$hailEta, 'startsAt'=>$hailStarts, 'endsAt'=>$hailEnds, 'lightning'=>$lightning]);
        }
    }
    if ($isEnabled('storm')) {
        $nearest = $lightning['nearestKm']??null;
        if ($thunderNear) {
            $sev =($nearest!==null&&$nearest<=15)||$maxCapeNear>=1800 ? 'red' : 'orange';
            $body = $nearest!==null ? meteonexa_backend_text('intelligence.event.storm.near.distance',['distance'=>round((float)$nearest)]) : meteonexa_backend_text('intelligence.event.storm.near.signals');
            $stormStarts = $stormWindow['startsAt']??null;
            $stormEnds = $stormWindow['endsAt']??null;
            $add('storm', $sev, $baseConfidence + 10, meteonexa_backend_text('intelligence.event.storm.near.title'), $body,['horizon'=>'nowcast', 'startsAt'=>$stormStarts, 'endsAt'=>$stormEnds, 'lightning'=>$lightning, 'cape'=>round($maxCapeNear)]);
        } elseif ($thunderWindow) {
            $stormHorizon = $eventHorizon($stormWindow['startsAt']);
            $hoursLabel = $forecastWindowHours > 24 ? $forecastWindowHours : 24;
            $add('storm', $maxCape>=1800 ? 'orange' : 'yellow', $baseConfidence, meteonexa_backend_text('intelligence.event.storm.forecast.title'), meteonexa_backend_text('intelligence.event.storm.forecast.body',['hours'=>$hoursLabel]),['horizon'=>$stormHorizon, 'startsAt'=>$stormWindow['startsAt'], 'endsAt'=>$stormWindow['endsAt'], 'lightning'=>$lightning, 'cape'=>round($maxCape)]);
        }
    }
    if ($isEnabled('wind')&&$maxWind>=$windThreshold) {
        $add('wind', $maxWind>=90 ? 'red' :($maxWind>=70 ? 'orange' : 'yellow'), $baseConfidence, meteonexa_backend_text('intelligence.event.wind.title'), meteonexa_backend_text('intelligence.event.wind.body',['value'=>round($maxWind)]),['horizon'=>'forecast', 'maxWind'=>round($maxWind)]);
    }
    if ($isEnabled('snow')) {
        $snowCodes =[71, 73, 75, 77, 85, 86];
        $currentCode = (int)($weather['current']['weather_code']??0);
        $currentSnow = (float)($weather['current']['snowfall']??0);
        $minuteSnowPeak = $minuteSnow ? max($minuteSnow) : 0.0;
        $minuteSnowCodeIndex = - 1;
        foreach ($minuteCodes as $i=>$minuteCode) {
            if (in_array((int)$minuteCode, $snowCodes, true)) {
                $minuteSnowCodeIndex = $i;
                break;
            }
        }
        $snowDetected = $maxSnow > .05||$currentSnow > .01||$minuteSnowPeak > .01||in_array($currentCode, $snowCodes, true)||$minuteSnowCodeIndex>=0||in_array(true, array_map(static fn($c)=>in_array((int)$c, $snowCodes, true), $codes), true);
        if ($snowDetected) {
            $snowStarts = $snowWindow['startsAt']??null;
            $snowEta = null;
            $snowMinuteIndex = - 1;
            foreach ($minuteSnow as $i=>$snowfall) {
                if ($snowfall > .01) {
                    $snowMinuteIndex = $i;
                    break;
                }
            }
            if ($snowMinuteIndex < 0)$snowMinuteIndex = $minuteSnowCodeIndex;
            if ($snowMinuteIndex>=0&&isset($minuteTimes[$snowMinuteIndex])) {
                $snowTs = meteonexa_intel_time_utc($minuteTimes[$snowMinuteIndex]);
                if ($snowTs!==null) {
                    $snowEta = max(0, (int)round(($snowTs - $now) / 60));
                    $snowStarts = gmdate('c', $snowTs);
                }
            }
            if (($currentSnow > .01||in_array($currentCode, $snowCodes, true))&&$snowEta===null) {
                $snowEta = 0;
                $snowStarts = $snowStarts??gmdate('c');
            }
            $snowPeak = max($maxSnow, $currentSnow, $minuteSnowPeak);
            $add('snow', $snowPeak>=2 ? 'orange' : 'yellow', $baseConfidence +($snowEta!==null ? 8 : 0), meteonexa_backend_text('intelligence.event.snow.title'), meteonexa_backend_text('intelligence.event.snow.body',['value'=>round($snowPeak, 1)]),['horizon'=>$snowEta!==null&&$snowEta<=120 ? 'nowcast' : $eventHorizon($snowStarts), 'etaMinutes'=>$snowEta, 'startsAt'=>$snowStarts, 'endsAt'=>$snowWindow['endsAt']??null, 'maxSnow'=>round($snowPeak, 1)]);
        }
    }
    if ($isEnabled('ice')&&$minTemp<=$coldThreshold&&($maxMm > .05||$maxSnow > .01)) {
        $add('ice', 'orange', $baseConfidence, meteonexa_backend_text('intelligence.event.ice.title'), meteonexa_backend_text('intelligence.event.ice.body',['value'=>round($minTemp, 1)]),['horizon'=>'forecast', 'minTemp'=>round($minTemp, 1)]);
    }
    if ($isEnabled('fog')&&$minVis < 2) {
        $add('fog', $minVis < .5 ? 'orange' : 'yellow', $baseConfidence, meteonexa_backend_text('intelligence.event.fog.title'), meteonexa_backend_text('intelligence.event.fog.body',['value'=>round($minVis, 1)]),['horizon'=>'forecast', 'visibilityKm'=>round($minVis, 1)]);
    }
    if ($isEnabled('heat')&&$maxTemp>=$heatThreshold) {
        $add('heat', $maxTemp>=40 ? 'red' :($maxTemp>=37 ? 'orange' : 'yellow'), $baseConfidence, meteonexa_backend_text('intelligence.event.heat.title'), meteonexa_backend_text('intelligence.event.heat.body',['value'=>round($maxTemp, 1)]),['horizon'=>'forecast', 'maxTemp'=>round($maxTemp, 1)]);
    }
    if ($isEnabled('aqi')&&$aqi>=100) {
        $add('aqi', $aqi>=150 ? 'orange' : 'yellow', 70, meteonexa_backend_text('intelligence.event.aqi.title'), meteonexa_backend_text('intelligence.event.aqi.body',['value'=>round($aqi)]),['horizon'=>'forecast', 'aqi'=>round($aqi)]);
    }
    foreach ((array)($context['official']['relevant']??[]) as $official) {
        if (!$isEnabled('official'))break;
        $sev = in_array(($official['severity']??''),['red', 'orange', 'yellow'], true) ? $official['severity'] : 'yellow';
        $add('official', $sev, 99, meteonexa_backend_text('intelligence.event.official.title'), meteonexa_backend_text('intelligence.event.official.body'),['horizon'=>'official', 'source'=>(string)($official['source']??'MeteoAlarm'), 'officialId'=>$official['id']??'', 'officialTitle'=>trim((string)($official['title']??'')), 'updatedAt'=>$official['updatedAt']??null, 'startsAt'=>$official['startsAt']??null, 'endsAt'=>$official['endsAt']??null, 'officialLifecycle'=>$official['lifecycle']??null]);
    }
    usort($events, static function($a, $b) {
        $rank =['red'=>3, 'orange'=>2, 'yellow'=>1]; return($rank[$b['severity']]??0)<=>($rank[$a['severity']]??0) ? :($b['confidence']<=>$a['confidence']);
    });
    $severity = $events[0]['severity']??'green';
    $confidence = $events ? (int)round(array_sum(array_column($events, 'confidence')) / count($events)) : (int)round($baseConfidence);
    $summary = $events ?($events[0]['title'] . ': ' . $events[0]['body']) : meteonexa_backend_text('intelligence.summary.none');
    return['severity'=>$severity, 'confidence'=>$confidence, 'summary'=>$summary, 'events'=>$events, 'outlookHours'=>$forecastWindowHours, 'metrics'=>['rainProbability'=>round($maxRain), 'rainProbabilityNowcast'=>round($maxRainNear), 'rainProbability24h'=>round($maxRain24), 'rainProbabilityWindow'=>round($maxRain), 'rainPeakMm'=>round($maxMm, 1), 'windGust'=>round($maxWind), 'temperatureMax'=>round($maxTemp, 1), 'temperatureMin'=>round($minTemp, 1), 'visibilityKm'=>round($minVis, 1), 'cape'=>round($maxCape), 'capeNowcast'=>round($maxCapeNear), 'aqi'=>round($aqi)], 'nowcast'=>['etaMinutes'=>$eta, 'startsAt'=>$startsAt, 'endsAt'=>$endsAt, 'radarMotion'=>$motion, 'satellite'=>$satellite, 'hyperlocal'=>$hyper]];
}
function meteonexa_intelligence_profile(array $profile) : array {
    $defaults =['thresholds'=>['rain'=>55, 'wind'=>55, 'heat'=>35, 'cold'=>2], 'events'=>['rain'=>true, 'storm'=>true, 'hail'=>true, 'wind'=>true, 'snow'=>true, 'ice'=>true, 'fog'=>true, 'heat'=>true, 'aqi'=>true, 'official'=>true], 'quietHours'=>['enabled'=>false, 'start'=>'23:00', 'end'=>'07:00'], 'minimumSeverity'=>'yellow'];
    if (isset($profile['rain'])&&!isset($profile['thresholds']))$profile['thresholds'] =['rain'=>$profile['rain']??55, 'wind'=>$profile['wind']??55, 'heat'=>$profile['heat']??35, 'cold'=>$profile['cold']??2];
    return array_replace_recursive($defaults, $profile);
}
function meteonexa_intelligence_event_key(array $event, string $locationName) : string {
    $type = substr((string)($event['type']??'weather'), 0, 24);
    if ($type==='official') {
        $life = (array)($event['officialLifecycle']??[]);
        $revision = (string)($life['changedAt']??'') . '|' . (string)($life['changeType']??'') . '|' . (string)($event['officialId']??'') . '|' . (string)($event['severity']??'') . '|' . (string)($event['endsAt']??'');
        return $type . ':' . substr(hash('sha256', strtolower($locationName) . '|' . $revision), 0, 32);
    }
    $bucket = (int)floor(time() / 900);
    $eta = isset($event['etaMinutes'])&&$event['etaMinutes']!==null ? (int)round((int)$event['etaMinutes'] / 15) : 0;
    $horizon = (string)($event['horizon']??'forecast');
    return $type . ':' . substr(hash('sha256', strtolower($locationName) . '|' . $horizon . '|' .($event['severity']??'') . '|' . $eta . '|' . $bucket), 0, 32);
}
function meteonexa_intelligence_quiet(array $profile, string $timezone) : bool {
    $quiet = (array)($profile['quietHours']??[]);
    if (empty($quiet['enabled']))return false;
    try {
        $tz = new DateTimeZone($timezone==='auto' ? 'UTC' : $timezone);
    } catch (Throwable $ignored) {
        $tz = new DateTimeZone('UTC');
    }
    $now = new DateTimeImmutable('now', $tz);
    $time = $now->format('H:i');
    $start = preg_match('/^\d{2}:\d{2}$/', (string)($quiet['start']??'')) ? (string)$quiet['start'] : '23:00';
    $end = preg_match('/^\d{2}:\d{2}$/', (string)($quiet['end']??'')) ? (string)$quiet['end'] : '07:00';
    return $start<=$end ?($time>=$start&&$time < $end) :($time>=$start||$time < $end);
}
