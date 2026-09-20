<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/intelligence/engine_helpers.php';

$failures = [];
function check_true(bool $condition, string $label): void {
    global $failures;
    if ($condition) echo "[ OK ] {$label}\n";
    else { echo "[FAIL] {$label}\n"; $failures[] = $label; }
}
function base_weather(): array {
    $base = (int)(floor(time()/3600)*3600);
    $times=[];$zeros=[];$temps=[];$vis=[];
    for($i=0;$i<36;$i++){
        $times[]=gmdate('Y-m-d\\TH:00',$base+$i*3600);
        $zeros[]=0;$temps[]=20;$vis[]=10000;
    }
    $minuteTimes=[];$minute=[];
    for($i=0;$i<8;$i++){$minuteTimes[]=gmdate('Y-m-d\\TH:i',$base+$i*900);$minute[]=0;}
    return [
        'hourly'=>[
            'time'=>$times,'precipitation_probability'=>$zeros,'precipitation'=>$zeros,'wind_gusts_10m'=>$zeros,
            'temperature_2m'=>$temps,'snowfall'=>$zeros,'visibility'=>$vis,'cape'=>$zeros,'weather_code'=>$zeros,
        ],
        'minutely_15'=>['time'=>$minuteTimes,'precipitation'=>$minute],
    ];
}
function types(array $analysis): array { return array_column((array)($analysis['events']??[]),'type'); }
function first_type(array $analysis, string $type): ?array {
    foreach((array)($analysis['events']??[]) as $event) if(($event['type']??'')===$type) return $event;
    return null;
}
$profile=meteonexa_intelligence_profile([]);
$ctx=['accuracy'=>['score'=>70],'radarMotion'=>['available'=>false],'lightning'=>['available'=>false,'recent30m'=>0,'nearestKm'=>null],'satellite'=>['available'=>false],'official'=>['relevant'=>[]],'hyperlocal'=>['available'=>false]];

$clear=meteonexa_intelligence_analyze(base_weather(),null,$profile,$ctx);
check_true(count($clear['events'])===0,'clear weather produces no Smart Alert');

$rain=base_weather();$rain['minutely_15']['precipitation'][1]=0.8;$rain['hourly']['precipitation_probability'][0]=82;$rain['hourly']['precipitation'][0]=1.2;
$a=meteonexa_intelligence_analyze($rain,null,$profile,$ctx);$e=first_type($a,'rain');
check_true(is_array($e),'rain alert generated');
check_true(($e['horizon']??'')==='nowcast','rain within 60m is classified as nowcast');
check_true(isset($e['etaMinutes']) && $e['etaMinutes']!==null && $e['etaMinutes']<=60,'rain nowcast has ETA <= 60m');

$futureRain=base_weather();$futureRain['hourly']['precipitation_probability'][10]=88;$futureRain['hourly']['precipitation'][10]=4.0;
$a=meteonexa_intelligence_analyze($futureRain,null,$profile,$ctx);$e=first_type($a,'rain');
check_true(is_array($e) && ($e['horizon']??'')==='forecast','rain many hours away is forecast, not nowcast');
check_true(($e['title']??'')!=='Pioggia in arrivo','future rain is not labelled “in arrivo”');

$nearStorm=base_weather();$nearStorm['hourly']['weather_code'][1]=95;$nearStorm['hourly']['cape'][1]=1200;
$a=meteonexa_intelligence_analyze($nearStorm,null,$profile,$ctx);$e=first_type($a,'storm');
check_true(is_array($e) && ($e['horizon']??'')==='nowcast','near thunderstorm classified as nowcast');

$futureStorm=base_weather();$futureStorm['hourly']['weather_code'][10]=95;$futureStorm['hourly']['cape'][10]=1000;
$a=meteonexa_intelligence_analyze($futureStorm,null,$profile,$ctx);$e=first_type($a,'storm');
check_true(is_array($e) && ($e['horizon']??'')==='forecast','distant thunderstorm classified as forecast');
check_true(($e['title']??'')!=='Temporale in avvicinamento','distant storm is not labelled “in avvicinamento”');

$disabled=$profile;$disabled['events']['rain']=false;
$a=meteonexa_intelligence_analyze($rain,null,$disabled,$ctx);
check_true(!in_array('rain',types($a),true),'disabled event is not generated');

// AQI input deliberately contains old high values; only current/future values should count.
$airTimes=[];$airValues=[];$base=(int)(floor(time()/3600)*3600)-10*3600;
for($i=0;$i<40;$i++){$airTimes[]=gmdate('Y-m-d\\TH:00',$base+$i*3600);$airValues[]=$i<10?180:50;}
$air=['hourly'=>['time'=>$airTimes,'european_aqi'=>$airValues]];
$a=meteonexa_intelligence_analyze(base_weather(),$air,$profile,$ctx);
check_true((int)($a['metrics']['aqi']??0)===50,'AQI ignores high values from already elapsed hours');

$keyA=meteonexa_intelligence_event_key(['type'=>'rain','severity'=>'yellow','horizon'=>'nowcast','etaMinutes'=>30],'Milano');
$keyB=meteonexa_intelligence_event_key(['type'=>'rain','severity'=>'yellow','horizon'=>'forecast','etaMinutes'=>null],'Milano');
check_true($keyA!==$keyB,'nowcast and forecast episodes have distinct dedup keys');

if($failures){fwrite(STDERR,"\nEngine smoke FAILED: ".count($failures)."\n");exit(1);}echo "\nEngine smoke PASS\n";
