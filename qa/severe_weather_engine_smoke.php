<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/intelligence/engine_helpers.php';

$now=time();
$times=[];for($i=0;$i<24;$i++)$times[]=gmdate('Y-m-d\TH:00',$now+$i*3600);
$minuteTimes=[];for($i=0;$i<8;$i++)$minuteTimes[]=gmdate('Y-m-d\TH:i',$now+$i*900);
$baseHourly=[
    'time'=>$times,
    'temperature_2m'=>array_fill(0,24,12.0),
    'precipitation_probability'=>array_fill(0,24,20.0),
    'precipitation'=>array_fill(0,24,0.0),
    'rain'=>array_fill(0,24,0.0),
    'snowfall'=>array_fill(0,24,0.0),
    'weather_code'=>array_fill(0,24,2),
    'visibility'=>array_fill(0,24,10000.0),
    'wind_speed_10m'=>array_fill(0,24,10.0),
    'wind_gusts_10m'=>array_fill(0,24,20.0),
    'cape'=>array_fill(0,24,50.0),
    'relative_humidity_2m'=>array_fill(0,24,70.0),
    'cloud_cover'=>array_fill(0,24,60.0),
];
$profile=meteonexa_intelligence_profile(['events'=>['rain'=>false,'storm'=>true,'hail'=>true,'wind'=>false,'snow'=>true,'ice'=>false,'fog'=>false,'heat'=>false,'aqi'=>false,'official'=>false]]);

// WMO 96 is explicit thunderstorm with hail: it must create a dedicated hail event.
$hailHourly=$baseHourly;$hailHourly['weather_code'][0]=96;
$hailWeather=['current'=>['weather_code'=>96],'hourly'=>$hailHourly,'minutely_15'=>['time'=>$minuteTimes,'precipitation'=>array_fill(0,8,0.2),'rain'=>array_fill(0,8,0.2),'snowfall'=>array_fill(0,8,0.0),'weather_code'=>[96,96,95,95,2,2,2,2]]];
$hail=meteonexa_intelligence_analyze($hailWeather,null,$profile,['forecastWindowHours'=>24,'accuracy'=>['score'=>70],'lightning'=>['available'=>true,'recent30m'=>4,'nearestKm'=>12]]);
$hailEvents=array_values(array_filter($hail['events']??[],fn($e)=>($e['type']??'')==='hail'));
if(count($hailEvents)!==1)throw new RuntimeException('Hail WMO 96 did not produce exactly one hail event');
if(($hailEvents[0]['etaMinutes']??null)!==0)throw new RuntimeException('Current hail must have ETA 0');
if(!in_array($hailEvents[0]['severity']??'',['orange','red'],true))throw new RuntimeException('Hail severity must be orange/red');

// High CAPE without WMO 96/99 must not invent hail.
$capeHourly=$baseHourly;$capeHourly['cape'][0]=2400;
$capeWeather=['current'=>['weather_code'=>95],'hourly'=>$capeHourly,'minutely_15'=>['time'=>$minuteTimes,'precipitation'=>array_fill(0,8,0.0),'rain'=>array_fill(0,8,0.0),'snowfall'=>array_fill(0,8,0.0),'weather_code'=>array_fill(0,8,95)]];
$cape=meteonexa_intelligence_analyze($capeWeather,null,$profile,['forecastWindowHours'=>24,'accuracy'=>['score'=>70],'lightning'=>['available'=>true,'recent30m'=>8,'nearestKm'=>8]]);
foreach($cape['events']??[] as $event)if(($event['type']??'')==='hail')throw new RuntimeException('CAPE/lightning-only path invented hail');

// 15-minute snowfall must produce a near-term snow ETA when snow is forecast.
$snowHourly=$baseHourly;$snowHourly['snowfall'][0]=0.8;$snowHourly['weather_code'][0]=71;
$snowMin=array_fill(0,8,0.0);$snowMin[2]=0.3;
$snowWeather=['current'=>['weather_code'=>3],'hourly'=>$snowHourly,'minutely_15'=>['time'=>$minuteTimes,'precipitation'=>array_fill(0,8,0.0),'rain'=>array_fill(0,8,0.0),'snowfall'=>$snowMin,'weather_code'=>[3,3,71,71,3,3,3,3]]];
$snow=meteonexa_intelligence_analyze($snowWeather,null,$profile,['forecastWindowHours'=>24,'accuracy'=>['score'=>70]]);
$snowEvents=array_values(array_filter($snow['events']??[],fn($e)=>($e['type']??'')==='snow'));
if(count($snowEvents)!==1)throw new RuntimeException('Snow event missing');
if(!is_int($snowEvents[0]['etaMinutes']??null) || $snowEvents[0]['etaMinutes']<0 || $snowEvents[0]['etaMinutes']>45)throw new RuntimeException('Snow 15-minute ETA missing/out of range');

echo "Severe weather engine synthetic smoke PASS\n";
