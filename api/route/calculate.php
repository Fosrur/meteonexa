<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';
assert_same_origin();
require_method('GET');
$config=load_config();$pdo=meteonexa_db($config);
$device=clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID']??'');require_authenticated_device_session($pdo, $config, $device);
require_ip_rate_limit($pdo,'route_ip',120,3600);require_global_rate_limit($pdo,'route_global',(int)($config['abuse_limits']['route_global_hour']??600),3600);require_device_rate_limit($pdo,'route_device',$device,120,3600);
$originLat=query_float('originLat',999);$originLon=query_float('originLon',999);
$destinationLat=query_float('destinationLat',999);$destinationLon=query_float('destinationLon',999);
$mode=clean_text($_GET['mode'] ?? 'car',20,'car');if(!in_array($mode,['car','motorcycle','bike','walk','trekking'],true))$mode='car';
if (abs($originLat)>90||abs($originLon)>180||abs($destinationLat)>90||abs($destinationLon)>180) respond(['ok'=>false,'code'=>'INVALID_COORDINATES','message'=>'api.backend.invalid_coordinates'],422);
$server=match($mode){'bike'=>'https://routing.openstreetmap.de/routed-bike','walk','trekking'=>'https://routing.openstreetmap.de/routed-foot',default=>'https://routing.openstreetmap.de/routed-car'};
$profile='driving';
$coords=round($originLon,6).','.round($originLat,6).';'.round($destinationLon,6).','.round($destinationLat,6);
$query='?overview=full&geometries=geojson&steps=true&alternatives=false';
$urls=["{$server}/route/v1/{$profile}/{$coords}{$query}","https://router.project-osrm.org/route/v1/driving/{$coords}{$query}"];
foreach($urls as $url){
  try{
    $data=http_json($url,20);$route=$data['routes'][0]??null;
    if(!is_array($route)||!isset($route['geometry']['coordinates'])) throw new RuntimeException('route_unavailable');
    $steps=[];
    foreach(($route['legs']??[]) as $leg){foreach(($leg['steps']??[]) as $index=>$step){
      $maneuver=is_array($step['maneuver']??null)?$step['maneuver']:[];
      $location=is_array($maneuver['location']??null)?$maneuver['location']:[null,null];
      $steps[]=[
        'maneuver'=>[
          'type'=>(string)($maneuver['type']??'continue'),
          'modifier'=>(string)($maneuver['modifier']??'straight'),
          'location'=>[isset($location[0])?(float)$location[0]:null,isset($location[1])?(float)$location[1]:null],
          'exit'=>isset($maneuver['exit'])?(int)$maneuver['exit']:null,
        ],
        'name'=>(string)($step['name']??''),
        'distance'=>(float)($step['distance']??0),
        'duration'=>(float)($step['duration']??0),
      ];
    }}
    respond(['ok'=>true,'mode'=>$mode,'distanceMeters'=>(float)($route['distance']??0),'durationSeconds'=>(float)($route['duration']??0),'geometry'=>$route['geometry'],'steps'=>$steps,'source'=>meteonexa_backend_text('provider.osm_osrm')]);
  }catch(Throwable $error){}
}
respond(['ok'=>false,'code'=>'ROUTE_UNAVAILABLE','message'=>'api.backend.route_unavailable'],503);
