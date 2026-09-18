<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/public_helpers.php';

assert_same_origin();
require_method('POST');
$config = load_config();
if (!(bool)($config['product_metrics']['enabled'] ?? true)) respond(['ok'=>true,'accepted'=>0,'enabled'=>false]);
$pdo = meteonexa_db($config);
require_global_rate_limit($pdo, 'product_metrics_global', (int)($config['product_metrics']['max_global_hour'] ?? 6000), 3600);
$input = input_json();
if (array_diff(array_keys($input), ['events']) !== []) respond(['ok'=>false,'code'=>'INVALID_METRICS','message'=>'api.backend.invalid_request'],422);
$events = $input['events'] ?? null;
if (!is_array($events) || array_is_list($events) || count($events) < 1 || count($events) > 11) {
    respond(['ok'=>false,'code'=>'INVALID_METRICS','message'=>'api.backend.invalid_request'],422);
}
$allowed = array_fill_keys([
    'page_home','page_radar','page_intelligence','search_location','open_alert','open_explainability',
    'use_route','use_ai','enable_notifications','bug_report','install_pwa'
], true);
$clean = [];$total = 0;
foreach ($events as $name=>$count) {
    $name = (string)$name;
    if (!isset($allowed[$name]) || !is_int($count) || $count < 1 || $count > 50) {
        respond(['ok'=>false,'code'=>'INVALID_METRICS','message'=>'api.backend.invalid_request'],422);
    }
    $clean[$name]=$count;$total+=$count;
}
if ($total > 100) respond(['ok'=>false,'code'=>'INVALID_METRICS','message'=>'api.backend.invalid_request'],422);
$date = gmdate('Y-m-d');$updated=gmdate('c');$driver=meteonexa_pdo_driver($pdo);
$sql = $driver === 'mysql'
    ? 'INSERT INTO product_metrics_daily(metric_date,event_name,event_count,updated_at) VALUES(:date,:event,:count,:updated) ON DUPLICATE KEY UPDATE event_count=LEAST(event_count+VALUES(event_count),2147483647),updated_at=VALUES(updated_at)'
    : 'INSERT INTO product_metrics_daily(metric_date,event_name,event_count,updated_at) VALUES(:date,:event,:count,:updated) ON CONFLICT(metric_date,event_name) DO UPDATE SET event_count=MIN(product_metrics_daily.event_count+excluded.event_count,2147483647),updated_at=excluded.updated_at';
$statement=$pdo->prepare($sql);$pdo->beginTransaction();
try {
    foreach($clean as $event=>$count)$statement->execute([':date'=>$date,':event'=>$event,':count'=>$count,':updated'=>$updated]);
    $cutoff=gmdate('Y-m-d',time()-((int)($config['product_metrics']['retention_days']??180)*86400));
    $prune=$pdo->prepare('DELETE FROM product_metrics_daily WHERE metric_date < :cutoff');$prune->execute([':cutoff'=>$cutoff]);
    $pdo->commit();
} catch(Throwable $error) {
    if($pdo->inTransaction())$pdo->rollBack();
    meteonexa_log_event('product_metrics_write_failed',$error);
    respond(['ok'=>false,'code'=>'METRICS_UNAVAILABLE','message'=>'api.backend.internal_error'],503);
}
respond(['ok'=>true,'accepted'=>$total,'enabled'=>true]);
