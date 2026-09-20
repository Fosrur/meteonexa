<?php
declare(strict_types=1);
require_once __DIR__ . '/engine_helpers.php';
function meteonexa_convective_vector(float $speed, float $deg) : array {
    $r = deg2rad($deg);
    return['u'=> - $speed * sin($r), 'v'=> - $speed * cos($r)];
}
function meteonexa_convective_risk_v3(float $lat, float $lon, array $severeOutlook =[], int $hours = 72) : array {
    try {
        $params = http_build_query(['latitude'=>round($lat, 4), 'longitude'=>round($lon, 4), 'hourly'=>'cape,convective_inhibition,freezing_level_height,showers,precipitation,weather_code,wind_speed_850hPa,wind_direction_850hPa,wind_speed_500hPa,wind_direction_500hPa', 'forecast_hours'=>max(24, min(96, $hours)), 'timezone'=>'GMT', 'wind_speed_unit'=>'kmh'], '', '&', PHP_QUERY_RFC3986);
        $raw = meteonexa_intel_provider_cache('convective-v3-' . $hours, $lat, $lon, 300, static fn()=>meteonexa_http_json('https://api.open-meteo.com/v1/forecast?' . $params,['timeout'=>18, 'max_bytes'=>1400000]));
        $h = (array)($raw['hourly']??[]);
        $times = (array)($h['time']??[]);
        $rows =[];
        $peak = null;
        for ($i = 0; $i < count($times); $i++) {
            if (!is_numeric($h['cape'][$i]??null))continue;
            $cape = (float)$h['cape'][$i];
            $cin = abs((float)($h['convective_inhibition'][$i]??0));
            $fz = is_numeric($h['freezing_level_height'][$i]??null) ? (float)$h['freezing_level_height'][$i] : null;
            $showers = (float)($h['showers'][$i]??0);
            $p = (float)($h['precipitation'][$i]??0);
            $a = meteonexa_convective_vector((float)($h['wind_speed_850hPa'][$i]??0), (float)($h['wind_direction_850hPa'][$i]??0));
            $b = meteonexa_convective_vector((float)($h['wind_speed_500hPa'][$i]??0), (float)($h['wind_direction_500hPa'][$i]??0));
            $shear = sqrt(($b['u'] - $a['u'])**2 +($b['v'] - $a['v'])**2);
            $ensemble = 0;
            foreach ((array)($severeOutlook['stormWindows']??[]) as $w) {
                $s = meteonexa_intel_time_utc($w['startsAt']??'');
                $e = meteonexa_intel_time_utc($w['endsAt']??'');
                $t = meteonexa_intel_time_utc($times[$i]??'');
                if ($t!==null&&$s!==null&&$e!==null&&$t>=$s&&$t<=$e)$ensemble = max($ensemble, (int)($w['agreementPct']??0));
            }
            $score = 0;
            $score+=min(35, $cape / 70);
            $score+=max(0, min(20,(200 - $cin) / 10));
            $score+=min(20, $shear / 3);
            $score+=min(10, $showers * 5);
            $score+=min(10, $ensemble / 10);
            if ($fz!==null&&$fz>=1800&&$fz<=3800)$score+=5;
            $score = (int)round(max(0, min(100, $score)));
            $row =['time'=>$times[$i], 'score'=>$score, 'level'=>$score>=75 ? 'high' :($score>=50 ? 'moderate' :($score>=30 ? 'elevated' : 'low')), 'cape'=>round($cape), 'cin'=>round($cin), 'shear850to500Kmh'=>round($shear, 1), 'freezingLevelM'=>$fz===null ? null : round($fz), 'convectivePrecipMm'=>round($showers, 2), 'precipitationMm'=>round($p, 2), 'ensembleStormAgreementPct'=>$ensemble];
            $rows[] = $row;
            if ($peak===null||$row['score'] > $peak['score'])$peak = $row;
        }
        return['available'=>$rows!==[], 'method'=>'convective-risk-v3', 'note'=>'diagnostic-guidance-not-official-warning', 'peak'=>$peak, 'timeline'=>$rows, 'generatedAt'=>gmdate('c')];
    } catch (Throwable $e) {
        return['available'=>false, 'method'=>'convective-risk-v3', 'timeline'=>[], 'generatedAt'=>gmdate('c')];
    }
}
