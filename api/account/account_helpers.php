<?php
declare(strict_types=1);
function meteonexa_account_sync_hash(array $config, array $session) : string {
    $email = strtolower(trim((string)($session['email']??'')));
    if ($email===''||!str_contains($email, '@'))throw new RuntimeException('ACCOUNT_IDENTITY_UNAVAILABLE');
    return hash_hmac('sha256', 'meteonexa-account-sync|' . $email, auth_secret($config));
}
function meteonexa_account_sync_read(PDO $pdo, string $account, string $namespace, string $key) : ? array {
    $st = $pdo->prepare('SELECT payload_json,deleted,revision,updated_at FROM account_sync_state WHERE account_hash=:a AND namespace=:n AND item_key=:k LIMIT 1');
    $st->execute([':a'=>$account, ':n'=>$namespace, ':k'=>$key]);
    $r = $st->fetch();
    if (!is_array($r)||(int)($r['deleted']??0)===1)return null;
    $p = json_decode((string)$r['payload_json'], true);
    return['payload'=>is_array($p) ? $p :[], 'revision'=>(int)($r['revision']??0), 'updatedAt'=>(string)($r['updated_at']??'')];
}
function meteonexa_account_sync_list(PDO $pdo, string $account, string $namespace) : array {
    $st = $pdo->prepare('SELECT item_key,payload_json,revision,updated_at FROM account_sync_state WHERE account_hash=:a AND namespace=:n AND deleted=0 ORDER BY updated_at DESC,item_key');
    $st->execute([':a'=>$account, ':n'=>$namespace]);
    $out =[];
    foreach ($st->fetchAll() as $r) {
        $p = json_decode((string)$r['payload_json'], true);
        if (is_array($p))$out[] =['itemKey'=>(string)$r['item_key'], 'payload'=>$p, 'revision'=>(int)$r['revision'], 'updatedAt'=>(string)$r['updated_at']];
    }
    return $out;
}
function meteonexa_account_sync_upsert(PDO $pdo, string $account, string $namespace, string $key, array $payload, string $device, bool $deleted = false) : int {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)||strlen($json) > 32768)throw new RuntimeException('SYNC_PAYLOAD_INVALID');
    $st = $pdo->prepare('SELECT revision FROM account_sync_state WHERE account_hash=:a AND namespace=:n AND item_key=:k LIMIT 1');
    $st->execute([':a'=>$account, ':n'=>$namespace, ':k'=>$key]);
    $current = $st->fetchColumn();
    $rev = max(1, (int)$current + 1);
    $now = gmdate('c');
    if ($current!==false)$q = $pdo->prepare('UPDATE account_sync_state SET payload_json=:p,deleted=:d,revision=:r,updated_by_device=:dev,updated_at=:u WHERE account_hash=:a AND namespace=:n AND item_key=:k');
    else $q = $pdo->prepare('INSERT INTO account_sync_state(account_hash,namespace,item_key,payload_json,deleted,revision,updated_by_device,updated_at) VALUES(:a,:n,:k,:p,:d,:r,:dev,:u)');
    $q->execute([':a'=>$account, ':n'=>$namespace, ':k'=>$key, ':p'=>$json, ':d'=>$deleted ? 1 : 0, ':r'=>$rev, ':dev'=>$device, ':u'=>$now]);
    return $rev;
}
function meteonexa_account_activity_defaults() : array {
    return['motorcycle'=>['rainMax'=>20, 'gustMax'=>40, 'tempMin'=>8, 'tempMax'=>36, 'visibilityMin'=>5], 'bike'=>['rainMax'=>25, 'gustMax'=>35, 'tempMin'=>5, 'tempMax'=>34, 'visibilityMin'=>4], 'run'=>['rainMax'=>35, 'gustMax'=>45, 'tempMin'=> - 2, 'tempMax'=>32, 'visibilityMin'=>3], 'trekking'=>['rainMax'=>30, 'gustMax'=>50, 'tempMin'=> - 5, 'tempMax'=>30, 'visibilityMin'=>3], 'sea'=>['rainMax'=>25, 'gustMax'=>35, 'tempMin'=>18, 'tempMax'=>38, 'visibilityMin'=>5], 'outdoor'=>['rainMax'=>40, 'gustMax'=>55, 'tempMin'=> - 5, 'tempMax'=>36, 'visibilityMin'=>2], 'worksite'=>['rainMax'=>25, 'gustMax'=>45, 'tempMin'=> - 5, 'tempMax'=>34, 'visibilityMin'=>3], 'commute'=>['rainMax'=>45, 'gustMax'=>60, 'tempMin'=> - 10, 'tempMax'=>40, 'visibilityMin'=>2], 'kids'=>['rainMax'=>25, 'gustMax'=>35, 'tempMin'=>5, 'tempMax'=>30, 'visibilityMin'=>4], 'pets'=>['rainMax'=>35, 'gustMax'=>45, 'tempMin'=>0, 'tempMax'=>31, 'visibilityMin'=>3]];
}
function meteonexa_account_activity_sanitize(string $activity, array $in) : array {
    $d = meteonexa_account_activity_defaults();
    if (!isset($d[$activity]))throw new InvalidArgumentException('ACTIVITY_INVALID');
    $b = $d[$activity];
    $cl = function($v, $min, $max, $fallback) {
        return is_numeric($v) ? max($min, min($max, (float)$v)) : $fallback;
    };
    $o =['rainMax'=>(int)round($cl($in['rainMax']??null, 0, 100, $b['rainMax'])), 'gustMax'=>(int)round($cl($in['gustMax']??null, 5, 180, $b['gustMax'])), 'tempMin'=>(int)round($cl($in['tempMin']??null, - 40, 45, $b['tempMin'])), 'tempMax'=>(int)round($cl($in['tempMax']??null, - 20, 60, $b['tempMax'])), 'visibilityMin'=>round($cl($in['visibilityMin']??null, .1, 50, $b['visibilityMin']), 1)];
    if ($o['tempMax']<=$o['tempMin'])$o['tempMax'] = $o['tempMin'] + 5;
    return $o;
}
function meteonexa_account_activity_load(PDO $pdo, string $account) : array {
    $out = meteonexa_account_activity_defaults();
    $st = $pdo->prepare('SELECT activity,thresholds_json,revision,updated_at FROM account_activity_profiles WHERE account_hash=:a');
    $st->execute([':a'=>$account]);
    foreach ($st->fetchAll() as $r) {
        $a = (string)$r['activity'];
        $p = json_decode((string)$r['thresholds_json'], true);
        if (isset($out[$a])&&is_array($p))$out[$a] = meteonexa_account_activity_sanitize($a, $p) +['revision'=>(int)$r['revision'], 'updatedAt'=>(string)$r['updated_at']];
    }
    return $out;
}
function meteonexa_account_activity_upsert(PDO $pdo, string $account, string $activity, array $thresholds, string $device) : array {
    $clean = meteonexa_account_activity_sanitize($activity, $thresholds);
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare('SELECT revision FROM account_activity_profiles WHERE account_hash=:a AND activity=:act');
    $st->execute([':a'=>$account, ':act'=>$activity]);
    $cur = $st->fetchColumn();
    $rev = max(1, (int)$cur + 1);
    $now = gmdate('c');
    if ($cur!==false)$q = $pdo->prepare('UPDATE account_activity_profiles SET thresholds_json=:j,revision=:r,updated_by_device=:d,updated_at=:u WHERE account_hash=:a AND activity=:act');
    else $q = $pdo->prepare('INSERT INTO account_activity_profiles(account_hash,activity,thresholds_json,revision,updated_by_device,updated_at) VALUES(:a,:act,:j,:r,:d,:u)');
    $q->execute([':a'=>$account, ':act'=>$activity, ':j'=>$json, ':r'=>$rev, ':d'=>$device, ':u'=>$now]);
    return $clean +['revision'=>$rev, 'updatedAt'=>$now];
}
