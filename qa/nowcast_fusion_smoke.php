<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/api/intelligence/nowcast_helpers.php';
$cons=['primary'=>['type'=>'storm','agreementPct'=>80,'weightedAgreementPct'=>86],'modelsAvailable'=>5];
$motion=['available'=>true,'confidence'=>80,'etaMinutes'=>34,'direction'=>'NE'];
$cell=['available'=>true,'impactProbability'=>78,'trackConfidence'=>84,'etaMinutes'=>31,'direction'=>'NE','speedCellsMin'=>.015,'growthPct'=>28,'stage'=>'growing','towardLocation'=>true];
$light=['available'=>true,'degraded'=>false,'recent30m'=>4,'nearestKm'=>18];$sat=['available'=>true,'cloudAttenuationPct'=>72,'support'=>.8];$official=['relevant'=>[['title'=>'Thunderstorms','severity'=>'orange']],'geospatial'=>true,'mode'=>'edr-geospatial'];$obs=['available'=>true,'sourceCount'=>3];
$f=meteonexa_nowcast_fusion($cons,$motion,$cell,$light,$sat,$official,$obs,45.46);
if(empty($f['available'])||($f['impactProbability']??0)<50||!is_array($f['etaRangeMinutes']??null)||empty($f['capGeoJson'])||($f['speedKmh']??0)<=0||count($f['sourceIds']??[])<4)throw new RuntimeException('fusion output');
echo "Nowcast Fusion smoke PASS\n";
