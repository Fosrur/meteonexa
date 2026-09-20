<?php
declare(strict_types=1);

function meteonexa_watch_plan_activities(): array
{
    return ['run','bike','motorcycle','sea','trekking','kids','pets','worksite','commute','event','photography'];
}

function meteonexa_watch_plan_location_map(PDO $pdo,string $account): array
{
    $out=[];
    foreach(meteonexa_account_sync_list($pdo,$account,'location') as $row){
        $payload=(array)($row['payload']??[]);$lat=$payload['latitude']??null;$lon=$payload['longitude']??null;
        if(!is_numeric($lat)||!is_numeric($lon)||abs((float)$lat)>90||abs((float)$lon)>180)continue;
        $key=(string)($row['itemKey']??'');if($key==='')continue;
        $out[$key]=[
            'itemKey'=>$key,
            'label'=>clean_text($payload['label']??$payload['name']??'',80,''),
            'name'=>clean_text($payload['name']??$payload['location_name']??'',191,''),
            'role'=>clean_text($payload['role']??'custom',24,'custom'),
            'timezone'=>clean_text($payload['timezone']??'auto',80,'auto'),
            'latitude'=>round((float)$lat,5),'longitude'=>round((float)$lon,5),
        ];
    }
    return $out;
}

function meteonexa_watch_plan_public_location(array $location): array
{
    return ['itemKey'=>(string)($location['itemKey']??''),'label'=>(string)($location['label']??''),'name'=>(string)($location['name']??''),'role'=>(string)($location['role']??'custom'),'timezone'=>(string)($location['timezone']??'auto')];
}

function meteonexa_watch_plan_sanitize(array $input,array $locations,?array $existing=null): array
{
    $activity=strtolower(clean_text($input['activity']??'',32,''));
    if(!in_array($activity,meteonexa_watch_plan_activities(),true))throw new InvalidArgumentException('PLAN_ACTIVITY_INVALID');
    $locationKey=clean_text($input['locationKey']??'',191,'');
    if($locationKey===''||!isset($locations[$locationKey]))throw new InvalidArgumentException('PLAN_LOCATION_INVALID');
    $rawStart=trim((string)($input['startsAt']??''));$startTs=strtotime($rawStart);
    if($startTs===false||$startTs<time()+600||$startTs>time()+14*86400)throw new InvalidArgumentException('PLAN_TIME_INVALID');
    $duration=(int)($input['durationMinutes']??120);if($duration<30||$duration>360||$duration%30!==0)throw new InvalidArgumentException('PLAN_DURATION_INVALID');
    $id=clean_text($input['id']??$existing['id']??'',64,'');
    if($id!==''&&!preg_match('/^[a-f0-9]{24}$/',$id))throw new InvalidArgumentException('PLAN_ID_INVALID');
    if($id==='')$id=bin2hex(random_bytes(12));
    $startsAt=gmdate('c',$startTs);$created=(string)($existing['createdAt']??gmdate('c'));
    $changed=is_array($existing)&&((string)($existing['activity']??'')!==$activity||(string)($existing['locationKey']??'')!==$locationKey||(string)($existing['startsAt']??'')!==$startsAt||(int)($existing['durationMinutes']??0)!==$duration);
    return [
        'id'=>$id,'activity'=>$activity,'locationKey'=>$locationKey,'startsAt'=>$startsAt,'durationMinutes'=>$duration,'enabled'=>true,
        'baseline'=>$changed?null:(is_array($existing['baseline']??null)?$existing['baseline']:null),
        'lastEvaluation'=>$changed?null:(is_array($existing['lastEvaluation']??null)?$existing['lastEvaluation']:null),
        'lastNotifiedAt'=>$changed?'':(string)($existing['lastNotifiedAt']??''),'lastNotifiedStatus'=>$changed?'':(string)($existing['lastNotifiedStatus']??''),
        'createdAt'=>$created,'updatedAt'=>gmdate('c'),
    ];
}

function meteonexa_watch_plan_list(PDO $pdo,string $account): array
{
    $locations=meteonexa_watch_plan_location_map($pdo,$account);$plans=[];
    foreach(meteonexa_account_sync_list($pdo,$account,'watch_plan') as $row){
        $payload=(array)($row['payload']??[]);$key=(string)($payload['locationKey']??'');
        if(empty($payload['id'])||!isset($locations[$key]))continue;
        $payload['location']=meteonexa_watch_plan_public_location($locations[$key]);$payload['revision']=(int)($row['revision']??0);$plans[]=$payload;
    }
    usort($plans,static fn($a,$b)=>(strtotime((string)($a['startsAt']??''))?:0)<=>(strtotime((string)($b['startsAt']??''))?:0));
    return ['plans'=>$plans,'locations'=>array_values(array_map('meteonexa_watch_plan_public_location',$locations))];
}
