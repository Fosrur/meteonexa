from pathlib import Path
import json, subprocess, sys, tempfile
root=Path(__file__).resolve().parents[1]
checks=[]

def require(cond,msg):
    if not cond:
        raise SystemExit('Roadmap 20.1 completion smoke FAIL: '+msg)

manifest=(root/'api/manifest.php').read_text(encoding='utf-8')
for token in ["'shortcuts'", "../#radar", "../#intelligence", "../#alerts", "../#route"]:
    require(token in manifest, f'manifest missing {token}')

summary=(root/'api/intelligence/summary.php').read_text(encoding='utf-8')
require("sun_cloud_helpers.php" in summary and "'sunCloudWindow'=>$sunCloudWindow" in summary,'sun cloud response not wired')
engine=(root/'api/intelligence/engine_helpers.php').read_text(encoding='utf-8')
for field in ['is_day','sunshine_duration','shortwave_radiation']:
    require(field in engine, f'weather evidence missing {field}')

push=(root/'api/push/dispatch.php').read_text(encoding='utf-8')
require('$quietNow = meteonexa_intelligence_quiet' in push,'quiet hours not evaluated')
require("!== 'red'" in push,'red severity quiet-hours override missing')

workflow=(root/'.github/workflows/meteonexa-tests.yml').read_text(encoding='utf-8')
require('browser: [chromium, firefox]' in workflow,'Chromium/Firefox matrix missing')

for removed in ['analytics.js','product-metrics.js','api/metrics/product.php']:
    require(not (root/removed).exists(),f'analytics artifact still present: {removed}')

# Execute deterministic Sun & Cloud Window helper with synthetic daylight input.
php='''<?php
require %s;
$times=[];$cloud=[];$rain=[];$rad=[];$sun=[];$day=[];
$base=time()+3600;
for($i=0;$i<6;$i++){ $times[]=gmdate('c',$base+$i*3600); $cloud[]=[80,45,30,25,70,85][$i]; $rain[]=[40,15,10,5,20,40][$i]; $rad[]=[50,250,450,600,180,30][$i]; $sun[]=[0,2400,3000,3300,1500,0][$i]; $day[]=[1,1,1,1,1,0][$i]; }
$w=['hourly'=>['time'=>$times,'cloud_cover'=>$cloud,'precipitation_probability'=>$rain,'shortwave_radiation'=>$rad,'sunshine_duration'=>$sun,'is_day'=>$day]];
$r=meteonexa_sun_cloud_window($w,['score'=>82,'timeline'=>[]],['available'=>true,'cloudAttenuationPct'=>20]);
echo json_encode($r);
?>''' % json.dumps(str(root/'api/intelligence/sun_cloud_helpers.php'))
res=subprocess.run(['php'],input=php,text=True,capture_output=True,check=True)
data=json.loads(res.stdout)
require(data.get('available') is True,'sun cloud helper unavailable')
require(data.get('bestWindow') and data['bestWindow'].get('score',0)>=52,'sun cloud best window missing')
require(data['bestWindow'].get('confidence',0)>0,'sun cloud confidence missing')
print('Roadmap 20.1 completion smoke PASS')
