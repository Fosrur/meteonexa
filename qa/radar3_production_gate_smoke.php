<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/database.php';
require_once dirname(__DIR__).'/api/intelligence/engine_helpers.php';

final class RadarGateFakeStatement extends PDOStatement
{
    private string $sql=''; private RadarGateFakePDO $owner; private array $params=[];
    public function __construct(RadarGateFakePDO $owner,string $sql){$this->owner=$owner;$this->sql=$sql;}
    public function execute(?array $params=null): bool{$this->params=$params??[];return true;}
    public function fetchColumn(int $column=0): mixed
    {
        if(str_contains($this->sql,'sqlite_master'))return 1;
        return false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        if(str_starts_with($this->sql,'PRAGMA table_info'))return [['name'=>'algorithm']];
        if(str_contains($this->sql,'GROUP BY algorithm'))return $this->owner->historyRows;
        return [];
    }
}
final class RadarGateFakePDO extends PDO
{
    public array $historyRows=[];
    public function __construct(){}
    public function getAttribute(int $attribute): mixed{if($attribute===PDO::ATTR_DRIVER_NAME)return 'sqlite';return null;}
    public function prepare(string $query,array $options=[]): PDOStatement|false{return new RadarGateFakeStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false{return new RadarGateFakeStatement($this,$query);}
}

$pdo=new RadarGateFakePDO();
$good=['available'=>true,'cells'=>[array_merge(['id'=>'obj-test','trackConfidence'=>78,'frameCount'=>4,'energy'=>7.5],['trajectoryCone'=>[['minute'=>15],['minute'=>30],['minute'=>45],['minute'=>60],['minute'=>90]]])]];
$checks=[];
$g=meteonexa_radar3_production_gate($pdo,'dev','loc',$good,2);
$checks['learning mode stays probation']=empty($g['eligible'])&&($g['reason']??'')==='probation_insufficient_history';
$stale=meteonexa_radar3_production_gate($pdo,'dev','loc',$good,16);
$checks['stale radar falls back']=empty($stale['eligible'])&&($stale['reason']??'')==='stale_radar';
$weak=$good;$weak['cells'][0]['trackConfidence']=54;
$q=meteonexa_radar3_production_gate($pdo,'dev','loc',$weak,2);
$checks['weak track falls back']=empty($q['eligible'])&&($q['reason']??'')==='track_quality';

$pdo->historyRows=[
 ['algorithm'=>'radar-v2','samples'=>20,'mae'=>6.0,'within_ratio'=>.85],
 ['algorithm'=>'radar-v3','samples'=>20,'mae'=>12.0,'within_ratio'=>.62],
];
$reg=meteonexa_radar3_production_gate($pdo,'dev','loc',$good,2);
$checks['historical MAE regression auto-demotes']=empty($reg['eligible'])&&($reg['reason']??'')==='historical_mae_regression';
$pdo->historyRows=[
 ['algorithm'=>'radar-v2','samples'=>20,'mae'=>7.0,'within_ratio'=>.78],
 ['algorithm'=>'radar-v3','samples'=>20,'mae'=>6.5,'within_ratio'=>.82],
];
$ok=meteonexa_radar3_production_gate($pdo,'dev','loc',$good,2);
$checks['verified non-worse history stays active']=!empty($ok['eligible'])&&($ok['reason']??'')==='verified_active';

$failed=[];foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL')." {$name}\n";if(!$pass)$failed[]=$name;}
if($failed){fwrite(STDERR,'Radar3 production gate failed: '.implode(', ',$failed)."\n");exit(1);}echo "Radar3 probation gate PASS\n";
