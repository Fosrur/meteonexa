<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/public_helpers.php';
function meteonexa_official_severity_rank(string $severity) : int {
    return['green'=>0, 'yellow'=>1, 'orange'=>2, 'red'=>3][strtolower($severity)]??0;
}
function meteonexa_official_event_kind(array $row) : string {
    $text = strtolower((string)($row['title']??'') . ' ' . (string)($row['summary']??''));
    foreach ([['storm', 'thunder|tempor'],['rain', 'rain|piogg'],['snow', 'snow|neve'],['wind', 'wind|vento'],['ice', 'ice|ghiacc'],['fog', 'fog|nebb'],['heat', 'heat|caldo']] as[$id, $rx]) if (preg_match('/' . $rx . '/i', $text))return $id;
    return 'weather';
}
function meteonexa_official_parse_iso( ? string $value) : ? string {
    $v = trim((string)$value);
    if ($v==='')return null;
    $ts = strtotime($v);
    return $ts===false ? null : gmdate('c', $ts);
}
function meteonexa_official_enrich_window(array $row) : array {
    foreach (['startsAt', 'onset', 'effective', 'validFrom', 'start'] as $k) if (empty($row['startsAt'])&&!empty($row[$k]))$row['startsAt'] = meteonexa_official_parse_iso((string)$row[$k]);
    foreach (['endsAt', 'expires', 'validTo', 'end'] as $k) if (empty($row['endsAt'])&&!empty($row[$k]))$row['endsAt'] = meteonexa_official_parse_iso((string)$row[$k]);
    $text = (string)($row['summary']??'') . ' ' . (string)($row['title']??'');
    if (empty($row['startsAt'])||empty($row['endsAt'])) {
        preg_match_all('/\b20\d{2}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?\b/', $text, $m);
        $dates = $m[0]??[];
        if (empty($row['startsAt'])&&isset($dates[0]))$row['startsAt'] = meteonexa_official_parse_iso($dates[0]);
        if (empty($row['endsAt'])&&isset($dates[1]))$row['endsAt'] = meteonexa_official_parse_iso($dates[1]);
    }
    if (empty($row['endsAt'])&&preg_match('/(?:until|fino\s+a(?:lle)?|jusqu[’\']?à|hasta|bis)\s+(?:le\s+|il\s+|al\s+)?(\d{1,2})[\/:.-](\d{1,2})(?:[\/:.-](20\d{2}))?[^0-9]{0,20}(\d{1,2})[:.]([0-5]\d)/iu', $text, $m)) {
        $year = isset($m[3])&&$m[3]!=='' ? (int)$m[3] : (int)gmdate('Y');
        $row['endsAt'] = meteonexa_official_parse_iso(sprintf('%04d-%02d-%02d %02d:%02d UTC', $year, (int)$m[2], (int)$m[1], (int)$m[4], (int)$m[5]));
    }
    return $row;
}
/**
 * Reliability gate for official warnings.
 *
 * MeteoAlarm EDR can return currently active as well as upcoming warnings and
 * legacy Atom rows may omit an explicit validity window.  Keep those states
 * separate so the UI never labels a future/ambiguous row as "active now" and
 * expired rows can never survive through provider/server cache reuse.
 */
function meteonexa_official_window_state(array $row, ? int $now = null) : array {
    $row = meteonexa_official_enrich_window($row);
    $now = $now??time();
    $startTs = strtotime((string)($row['startsAt']??'')) ? : 0;
    $endTs = strtotime((string)($row['endsAt']??'')) ? : 0;
    $severity = strtolower(trim((string)($row['severity']??'yellow')));
    if (!in_array($severity,['green', 'yellow', 'orange', 'red'], true))$severity = 'yellow';
    if ($severity==='green')$state = 'inactive';
    elseif ($endTs > 0&&$endTs < $now - 60)$state = 'expired';
    elseif ($startTs > 0&&$startTs > $now + 60)$state = 'upcoming';
    elseif ($startTs > 0||$endTs > 0)$state = 'active';
    else $state = 'unknown';
    $row['severity'] = $severity;
    $row['windowState'] = $state;
    $row['startsInMinutes'] = $startTs > 0 ? (int)round(($startTs - $now) / 60) : null;
    $row['endsInMinutes'] = $endTs > 0 ? (int)round(($endTs - $now) / 60) : null;
    $row['validityKnown'] = $startTs > 0||$endTs > 0;
    return $row;
}
/**
 * Normalize/dedupe the provider collection before persistence or display.
 * Regional text-only Atom matches are kept outside `relevant` because they do
 * not prove that the warning polygon contains the requested point.
 */
function meteonexa_official_normalize(array $alerts, ? int $now = null) : array {
    $now = $now??time();
    $rows =[];
    $seen =[];
    $active = 0;
    $upcoming = 0;
    $unknown = 0;
    foreach ((array)($alerts['relevant']??[]) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_window_state($row, $now);
        if (in_array((string)$row['windowState'],['expired', 'inactive'], true))continue;
        $identity = trim((string)($row['id']??''));
        if ($identity==='')$identity = hash('sha256', strtolower(trim((string)($row['source']??'official')) . '|' . trim((string)($row['title']??'')) . '|' . trim((string)($row['startsAt']??'')) . '|' . trim((string)($row['endsAt']??''))));
        if (isset($seen[$identity]))continue;
        $seen[$identity] = true;
        if ($row['windowState']==='active')$active++;
        elseif ($row['windowState']==='upcoming')$upcoming++;
        else $unknown++;
        $rows[] = $row;
    }
    usort($rows, static function($a, $b) {
        $stateRank =['active'=>3, 'upcoming'=>2, 'unknown'=>1]; $sev =['red'=>3, 'orange'=>2, 'yellow'=>1, 'green'=>0]; $sa = $stateRank[(string)($a['windowState']??'unknown')]??0; $sb = $stateRank[(string)($b['windowState']??'unknown')]??0; if ($sa!==$sb)return $sb<=>$sa; $va = $sev[(string)($a['severity']??'green')]??0; $vb = $sev[(string)($b['severity']??'green')]??0; if ($va!==$vb)return $vb<=>$va; return(strtotime((string)($a['startsAt']??'')) ? : PHP_INT_MAX)<=>(strtotime((string)($b['startsAt']??'')) ? : PHP_INT_MAX);
    });
    $alerts['relevant'] = $rows;
    $alerts['activeCount'] = $active;
    $alerts['upcomingCount'] = $upcoming;
    $alerts['unknownTimingCount'] = $unknown;
    $regional =[];
    $regionalSeen =[];
    foreach ((array)($alerts['regionalAdvisories']??[]) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_window_state($row, $now);
        if (in_array((string)$row['windowState'],['expired', 'inactive'], true))continue;
        $id = trim((string)($row['id']??'')) ? : hash('sha256', json_encode([$row['title']??'', $row['startsAt']??'', $row['endsAt']??'']));
        if (isset($regionalSeen[$id]))continue;
        $regionalSeen[$id] = true;
        $regional[] = $row;
    }
    $alerts['regionalAdvisories'] = $regional;
    return $alerts;
}
function meteonexa_official_change_type( ? array $previous, string $severity, string $ends, string $contentHash) : string {
    if (!$previous)return 'new';
    $previousSeverity = (string)($previous['severity']??'');
    $previousEnds = (string)($previous['ends_at']??'');
    $oldRank = meteonexa_official_severity_rank($previousSeverity);
    $newRank = meteonexa_official_severity_rank($severity);
    $oldEnd = strtotime($previousEnds) ? : 0;
    $newEnd = strtotime($ends) ? : 0;
    if ($newRank > $oldRank)return 'escalated';
    if ($newRank < $oldRank)return 'downgraded';
    if ($oldEnd > 0&&$newEnd > $oldEnd + 60)return 'extended';
    if ($oldEnd > 0&&$newEnd > 0&&$newEnd < $oldEnd - 60)return 'shortened';
    if (!hash_equals((string)($previous['content_hash']??''), $contentHash))return 'updated';
    return 'unchanged';
}
function meteonexa_official_semantic_key(array $row) : string {
    $kind = meteonexa_official_event_kind($row);
    $source = strtolower(trim((string)($row['source']??'official')));
    return substr(hash('sha256', $source . '|' . $kind), 0, 40);
}
function meteonexa_official_db_text(mixed $value, int $max) : string {
    $text = (string)$value;
    if ($max < 1)return '';
    return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
}
function meteonexa_official_public_snapshot(PDO $pdo, float $lat, float $lon, array $alerts, string $locationName = '', string $admin1 = '') : array {
    $alerts = meteonexa_official_normalize($alerts);
    // Guest/public reads must never require_once an account and never write lifecycle.
    // Reuse location-scoped evidence already produced by the server-side pipeline.
    if (!meteonexa_db_table_exists($pdo, 'official_alert_state'))return $alerts;
    $locationKey = number_format($lat, 3, '.', '') . ':' . number_format($lon, 3, '.', '');
    $statement = $pdo->prepare('SELECT * FROM official_alert_state WHERE location_key=:location ORDER BY last_change_at DESC');
    $statement->execute([':location'=>$locationKey]);
    $stateRows = $statement->fetchAll();
    if (!is_array($stateRows))$stateRows =[];
    // Search a nearby server snapshot only when the exact rounded geocode has no
    // lifecycle evidence. Geocoders may return slightly different centroids for
    // the same city. Nearby evidence is accepted only if its payload also matches
    // the requested administrative area/name, reducing cross-boundary leakage.
    if (!$stateRows) {
        $near = $pdo->query('SELECT * FROM official_alert_state ORDER BY last_seen_at DESC LIMIT 250');
        $candidates = $near ? $near->fetchAll() :[];
        $adminNeedle = strtolower(trim($admin1));
        $nameNeedle = strtolower(trim($locationName));
        $ranked =[];
        foreach ((array)$candidates as $stored) {
            if (!is_array($stored)||(string)($stored['last_change_type']??'')==='expired')continue;
            $parts = explode(':', (string)($stored['location_key']??''), 2);
            if (count($parts)!==2||!is_numeric($parts[0])||!is_numeric($parts[1]))continue;
            $d = haversine_km($lat, $lon, (float)$parts[0], (float)$parts[1]);
            if ($d > 60)continue;
            $payload = json_decode((string)($stored['payload_json']??'{}'), true);
            if (!is_array($payload))continue;
            $hay = strtolower((string)($payload['title']??'') . ' ' . (string)($payload['summary']??'') . ' ' . (string)($stored['location_name']??''));
            $areaMatch =($adminNeedle!==''&&strlen($adminNeedle)>=4&&str_contains($hay, $adminNeedle))||($nameNeedle!==''&&strlen($nameNeedle)>=4&&str_contains($hay, $nameNeedle));
            if (!$areaMatch&&$d > 15)continue;
            $ranked[] =['distance'=>$d, 'row'=>$stored];
        }
        usort($ranked, static fn($a, $b)=>$a['distance']<=>$b['distance']);
        $stateRows = array_map(static fn($item)=>$item['row'], array_slice($ranked, 0, 30));
    }
    if (!$stateRows)return $alerts;
    $byKey =[];
    foreach ($stateRows as $stored) {
        if (!is_array($stored))continue;
        $byKey[(string)($stored['alert_key']??'')] = $stored;
    }
    $relevant =[];
    foreach ((array)($alerts['relevant']??[]) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_enrich_window($row);
        $key = meteonexa_official_semantic_key($row);
        $stored = $byKey[$key]??null;
        if (is_array($stored)) {
            $row['lifecycle'] =['changeType'=>(string)($stored['last_change_type']??'unchanged'), 'changedAt'=>(string)($stored['last_change_at']??''), 'previousSeverity'=>(string)($stored['previous_severity']??''), 'previousEndsAt'=>(string)($stored['previous_ends_at']??''), 'isFreshChange'=>false];
        }
        $relevant[] = $row;
    }
    if ($relevant) {
        $alerts['relevant'] = $relevant;
        $alerts['lifecycleTracked'] = true;
        return $alerts;
    }
    // A fresh provider response with zero point-matched warnings is authoritative.
    // Never resurrect a previous server snapshot in this case: doing so can show
    // an alert that MeteoAlarm has already cleared or that belongs to a nearby
    // region. Snapshot fallback is reserved strictly for provider failure/stale I/O.
    $freshProvider =($alerts['available']??false)===true&&empty($alerts['staleProviderCache'])&&($alerts['providerFresh']??false)===true;
    if ($freshProvider) {
        $alerts['relevant'] =[];
        $alerts['pipelineFallback'] = false;
        $alerts['lifecycleTracked'] = false;
        return $alerts;
    }
    // Provider failure/timeout: serve a recent active server-side public snapshot.
    // Six hours covers a short provider outage while validity/expiry checks below
    // prevent stale warnings from surviving their published window.
    $now = time();
    $fallback =[];
    $latest = null;
    foreach ($stateRows as $stored) {
        if (!is_array($stored)||(string)($stored['last_change_type']??'')==='expired')continue;
        $lastSeen = strtotime((string)($stored['last_seen_at']??'')) ? : 0;
        if ($lastSeen<=0||$lastSeen < $now - 21600)continue;
        $ends = strtotime((string)($stored['ends_at']??'')) ? : 0;
        if ($ends > 0&&$ends < $now - 900)continue;
        $payload = json_decode((string)($stored['payload_json']??'{}'), true);
        if (!is_array($payload))continue;
        $payload = meteonexa_official_enrich_window($payload);
        $payload['severity'] = (string)($stored['severity']??($payload['severity']??'yellow'));
        $payload['lifecycle'] =['changeType'=>(string)($stored['last_change_type']??'unchanged'), 'changedAt'=>(string)($stored['last_change_at']??''), 'previousSeverity'=>(string)($stored['previous_severity']??''), 'previousEndsAt'=>(string)($stored['previous_ends_at']??''), 'isFreshChange'=>false];
        $payload['serverSnapshot'] = true;
        $payload['snapshotAgeSeconds'] = max(0, $now - $lastSeen);
        $fallback[] = $payload;
        if ($latest===null||$lastSeen > (int)($latest['_lastSeen']??0))$latest = $payload +['_lastSeen'=>$lastSeen];
    }
    if ($fallback) {
        $alerts['relevant'] = $fallback;
        $alerts['available'] = true;
        $alerts['pipelineFallback'] = true;
        $alerts['lifecycleTracked'] = true;
        if (is_array($latest)) {
            unset($latest['_lastSeen']);
            $alerts['lifecycle'] = $latest['lifecycle']??null;
        }
    }
    return $alerts;
}
function meteonexa_official_track(PDO $pdo, float $lat, float $lon, string $locationName, array $alerts) : array {
    $alerts = meteonexa_official_normalize($alerts);
    if (!meteonexa_db_table_exists($pdo, 'official_alert_state'))return $alerts;
    $locationKey = number_format($lat, 3, '.', '') . ':' . number_format($lon, 3, '.', '');
    $now = gmdate('c');
    $seen =[];
    $out =[];
    $latest = null;
    foreach ((array)($alerts['relevant']??[]) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_enrich_window($row);
        $alertKey = meteonexa_official_semantic_key($row);
        $seen[$alertKey] = true;
        $severity = strtolower((string)($row['severity']??'yellow'));
        if (!in_array($severity,['yellow', 'orange', 'red'], true))$severity = 'yellow';
        $starts = (string)($row['startsAt']??'');
        $ends = (string)($row['endsAt']??'');
        $hash = hash('sha256', json_encode([$severity, $starts, $ends, $row['title']??'', $row['summary']??''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $st = $pdo->prepare('SELECT * FROM official_alert_state WHERE location_key=:location AND alert_key=:alert LIMIT 1');
        $st->execute([':location'=>$locationKey, ':alert'=>$alertKey]);
        $previous = $st->fetch();
        $previous = is_array($previous) ? $previous : null;
        $previousSeverity = $previous ? (string)$previous['severity'] : '';
        $previousEnds = $previous ? (string)$previous['ends_at'] : '';
        $change = meteonexa_official_change_type($previous, $severity, $ends, $hash);
        $lastType = $change==='unchanged' ? (string)($previous['last_change_type']??'unchanged') : $change;
        $lastAt = $change==='unchanged' ? (string)($previous['last_change_at']??$now) : $now;
        $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ? : '{}';
        $sql = "INSERT INTO official_alert_state(location_key,alert_key,location_name,provider_alert_id,severity,starts_at,ends_at,source_updated_at,content_hash,first_seen_at,last_seen_at,last_change_type,last_change_at,previous_severity,previous_ends_at,payload_json) VALUES(:location,:alert,:name,:provider,:severity,:starts,:ends,:source_updated,:hash,:first,:last,:change,:change_at,:previous_severity,:previous_ends,:payload) ON CONFLICT(location_key,alert_key) DO UPDATE SET location_name=excluded.location_name,provider_alert_id=excluded.provider_alert_id,severity=excluded.severity,starts_at=excluded.starts_at,ends_at=excluded.ends_at,source_updated_at=excluded.source_updated_at,content_hash=excluded.content_hash,last_seen_at=excluded.last_seen_at,last_change_type=excluded.last_change_type,last_change_at=excluded.last_change_at,previous_severity=excluded.previous_severity,previous_ends_at=excluded.previous_ends_at,payload_json=excluded.payload_json";
        $pdo->prepare($sql)->execute([':location'=>$locationKey, ':alert'=>$alertKey, ':name'=>$locationName, ':provider'=>meteonexa_official_db_text($row['id']??'', 255), ':severity'=>$severity, ':starts'=>$starts, ':ends'=>$ends, ':source_updated'=>meteonexa_official_db_text($row['updatedAt']??'', 40), ':hash'=>$hash, ':first'=>$previous ? (string)$previous['first_seen_at'] : $now, ':last'=>$now, ':change'=>$lastType, ':change_at'=>$lastAt, ':previous_severity'=>$change==='unchanged' ? (string)($previous['previous_severity']??'') : $previousSeverity, ':previous_ends'=>$change==='unchanged' ? (string)($previous['previous_ends_at']??'') : $previousEnds, ':payload'=>$payload]);
        if ($change!=='unchanged'&&meteonexa_db_table_exists($pdo, 'official_alert_revisions')) {
            $pdo->prepare('INSERT INTO official_alert_revisions(location_key,alert_key,revision_type,previous_severity,new_severity,previous_ends_at,new_ends_at,provider_alert_id,payload_json,observed_at) VALUES(:location,:alert,:type,:previous_severity,:new_severity,:previous_ends,:new_ends,:provider,:payload,:observed)')->execute([':location'=>$locationKey, ':alert'=>$alertKey, ':type'=>$change, ':previous_severity'=>$previousSeverity, ':new_severity'=>$severity, ':previous_ends'=>$previousEnds, ':new_ends'=>$ends, ':provider'=>meteonexa_official_db_text($row['id']??'', 255), ':payload'=>$payload, ':observed'=>$now]);
        }
        $row['startsAt'] = $starts ? : null;
        $row['endsAt'] = $ends ? : null;
        $row['lifecycle'] =['changeType'=>$lastType, 'changedAt'=>$lastAt, 'previousSeverity'=>$change==='unchanged' ? (string)($previous['previous_severity']??'') : $previousSeverity, 'previousEndsAt'=>$change==='unchanged' ? (string)($previous['previous_ends_at']??'') : $previousEnds, 'isFreshChange'=>$change!=='unchanged'];
        $out[] = $row;
        if ($latest===null||strtotime($lastAt) > strtotime((string)($latest['changedAt']??'')))$latest = $row['lifecycle'] +['severity'=>$severity, 'endsAt'=>$ends ? : null, 'eventKind'=>meteonexa_official_event_kind($row)];
    }
    // Mark alerts no longer present as expired once; this helps worker/diagnostics
    // understand closure without creating a synthetic user-facing warning.
    $active = $pdo->prepare('SELECT alert_key,severity,ends_at,last_change_type FROM official_alert_state WHERE location_key=:location');
    $active->execute([':location'=>$locationKey]);
    foreach ($active->fetchAll() as $old) {
        $key = (string)$old['alert_key'];
        if (isset($seen[$key])||$old['last_change_type']==='expired')continue;
        $pdo->prepare("UPDATE official_alert_state SET last_seen_at=:now,last_change_type='expired',last_change_at=:now WHERE location_key=:location AND alert_key=:alert")->execute([':now'=>$now, ':location'=>$locationKey, ':alert'=>$key]);
        if (meteonexa_db_table_exists($pdo, 'official_alert_revisions'))$pdo->prepare("INSERT INTO official_alert_revisions(location_key,alert_key,revision_type,previous_severity,new_severity,previous_ends_at,new_ends_at,provider_alert_id,payload_json,observed_at) VALUES(:location,:alert,'expired',:severity,'green',:ends,'','', '{}',:now)")->execute([':location'=>$locationKey, ':alert'=>$key, ':severity'=>(string)$old['severity'], ':ends'=>(string)$old['ends_at'], ':now'=>$now]);
    }
    $alerts['relevant'] = $out;
    $alerts['lifecycle'] = $latest;
    $alerts['lifecycleTracked'] = true;
    return $alerts;
}
