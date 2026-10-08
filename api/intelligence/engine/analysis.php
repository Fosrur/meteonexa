<?php
declare(strict_types=1);
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
    
    
    $hailCode = static fn($c) : bool=>in_array((int)$c,[96, 99], true);
    $thunderNear = in_array(true, array_map($stormCode, $nearCodes), true)||$maxCapeNear>=800||(($lightning['recent30m']??0)>=2&&($lightning['nearestKm']??999)<=40);
    $thunderWindow = in_array(true, array_map($stormCode, $codes), true)||$maxCape>=800;
    
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
