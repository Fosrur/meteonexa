<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/api/database.php';
require_once dirname(__DIR__) . '/api/intelligence/engine_helpers.php';

function radar4_test_grid(int $cx, int $cy, float $scale = 1.0, int $size = 32) : array {
    $grid = array_fill(0, $size, array_fill(0, $size, 0.0));
    $sum = 0.0;
    $wx = 0.0;
    $wy = 0.0;
    for ($y = max(1, $cy - 2); $y <= min($size - 2, $cy + 2); $y++) {
        for ($x = max(1, $cx - 2); $x <= min($size - 2, $cx + 2); $x++) {
            $dist = hypot($x - $cx, $y - $cy);
            $value = max(0.0, (1.0 - $dist / 3.2) * $scale);
            $grid[$y][$x] = $value;
            $sum += $value;
            $wx += $x * $value;
            $wy += $y * $value;
        }
    }
    return['grid'=>$grid, 'sum'=>$sum, 'cx'=>$sum > 0 ? $wx / $sum : null, 'cy'=>$sum > 0 ? $wy / $sum : null, 'size'=>$size];
}

$now = 1_800_000_000;
$prepared =[
    ['time'=>$now, 'grid'=>radar4_test_grid(13, 15, 1.00)],
    ['time'=>$now - 300, 'grid'=>radar4_test_grid(12, 15, .92)],
    ['time'=>$now - 600, 'grid'=>radar4_test_grid(11, 15, .84)],
    ['time'=>$now - 900, 'grid'=>radar4_test_grid(10, 15, .78)],
];
$radar3 = meteonexa_radar_object_tracks_v3($prepared);
$radar4 = meteonexa_radar4_shadow_tracks($prepared, $radar3);
$checks =[];
$checks['radar3 fixture produces tracks'] = !empty($radar3['available']) && count((array)($radar3['cells'] ?? [])) >= 1;
$checks['radar4 stays shadow only'] = !empty($radar4['available']) && ($radar4['mode'] ?? '')==='shadow' && empty($radar4['authoritative']) && !empty($radar4['policy']['productionDecisionsUnaffected']);
$checks['optical flow uses multiple pairs'] = !empty($radar4['flow']['available']) && (int)($radar4['flow']['pairCount'] ?? 0) >= 2 && (int)($radar4['flow']['confidence'] ?? 0) >= 15;
$checks['trajectory extends to 120 minutes'] = !empty($radar4['cells'][0]['trajectoryCone']) && (int)(end($radar4['cells'][0]['trajectoryCone'])['minute'] ?? 0)===120;
$checks['growth decay score exposed'] = array_key_exists('growthDecayScore', (array)($radar4['cells'][0] ?? []));
$checks['ETA is independently produced'] = is_numeric($radar4['dominantEtaMinutes'] ?? null) && (int)$radar4['dominantEtaMinutes'] >= 1 && (int)$radar4['dominantEtaMinutes'] <= 120;

final class Radar4GateFakeStatement extends PDOStatement
{
    private string $sql = '';
    private Radar4GateFakePDO $owner;
    public function __construct(Radar4GateFakePDO $owner, string $sql){$this->owner=$owner;$this->sql=$sql;}
    public function execute(?array $params=null): bool{return true;}
    public function fetchColumn(int $column=0): mixed{if(str_contains($this->sql,'sqlite_master'))return 1;return false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        if(str_starts_with($this->sql,'PRAGMA table_info'))return [['name'=>'algorithm']];
        if(str_contains($this->sql,"algorithm IN ('radar-v3','radar-v4')"))return $this->owner->historyRows;
        return [];
    }
}
final class Radar4GateFakePDO extends PDO
{
    public array $historyRows=[];
    public function __construct(){}
    public function getAttribute(int $attribute): mixed{if($attribute===PDO::ATTR_DRIVER_NAME)return 'sqlite';return null;}
    public function prepare(string $query,array $options=[]): PDOStatement|false{return new Radar4GateFakeStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false{return new Radar4GateFakeStatement($this,$query);}
}
$pdo = new Radar4GateFakePDO();
$pdo->historyRows =[
    ['algorithm'=>'radar-v3','samples'=>30,'mae'=>10.0,'within_ratio'=>.78],
    ['algorithm'=>'radar-v4','samples'=>30,'mae'=>8.5,'within_ratio'=>.80],
];
$gate = meteonexa_radar4_shadow_gate($pdo, 'device', 'loc', 30);
$checks['backtest can identify promotion candidate'] = !empty($gate['available']) && !empty($gate['promotionCandidate']) && !empty($gate['authorityLockedToRadar3']) && ($gate['reason'] ?? '')==='guardrails_pass_shadow_only';
$pdo->historyRows =[
    ['algorithm'=>'radar-v3','samples'=>30,'mae'=>8.0,'within_ratio'=>.82],
    ['algorithm'=>'radar-v4','samples'=>30,'mae'=>8.2,'within_ratio'=>.79],
];
$gateBad = meteonexa_radar4_shadow_gate($pdo, 'device', 'loc', 30);
$checks['failed backtest never promotes'] = !empty($gateBad['available']) && empty($gateBad['promotionCandidate']) && !empty($gateBad['authorityLockedToRadar3']);

$failed=[];
foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL')." {$name}\n";if(!$pass)$failed[]=$name;}
if($failed){fwrite(STDERR,'Radar4 shadow smoke failed: '.implode(', ',$failed)."\n");exit(1);}
echo "Radar4 P3.1 shadow smoke PASS\n";
