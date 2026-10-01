<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/hub_helpers.php';
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
    $terminal =[];
    $active = 0;
    $upcoming = 0;
    $unknown = 0;
    foreach (meteonexa_official_hub_dedupe((array)($alerts['relevant']??[])) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_window_state($row, $now);
        $terminalState = (string)($row['lifecycleStatus']??'');
        if ($terminalState==='cancelled') {
            $terminal[] = $row;
            continue;
        }
        if (in_array((string)$row['windowState'],['expired', 'inactive'], true)) {
            $row['lifecycleStatus'] = 'expired';
            $terminal[] = $row;
            continue;
        }
        if ($row['windowState']==='active')$active++;
        elseif ($row['windowState']==='upcoming')$upcoming++;
        else $unknown++;
        $rows[] = $row;
    }
    usort($rows, static function($a, $b) {
        $stateRank =['active'=>3, 'upcoming'=>2, 'unknown'=>1]; $sev =['red'=>3, 'orange'=>2, 'yellow'=>1, 'green'=>0]; $sa = $stateRank[(string)($a['windowState']??'unknown')]??0; $sb = $stateRank[(string)($b['windowState']??'unknown')]??0; if ($sa!==$sb)return $sb<=>$sa; $va = $sev[(string)($a['severity']??'green')]??0; $vb = $sev[(string)($b['severity']??'green')]??0; if ($va!==$vb)return $vb<=>$va; return(strtotime((string)($a['startsAt']??'')) ? : PHP_INT_MAX)<=>(strtotime((string)($b['startsAt']??'')) ? : PHP_INT_MAX);
    });
    $alerts['relevant'] = $rows;
    $alerts['terminalRevisions'] = $terminal;
    $alerts['activeCount'] = $active;
    $alerts['upcomingCount'] = $upcoming;
    $alerts['unknownTimingCount'] = $unknown;
    $regional =[];
    foreach (meteonexa_official_hub_dedupe((array)($alerts['regionalAdvisories']??[])) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_window_state($row, $now);
        if (in_array((string)$row['windowState'],['expired', 'inactive'], true)||(string)($row['lifecycleStatus']??'')==='cancelled')continue;
        $regional[] = $row;
    }
    $alerts['regionalAdvisories'] = $regional;
    return meteonexa_official_hub_contract($alerts);
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
    $hubKey = trim((string)($row['hubKey']??''));
    if ($hubKey!=='')return substr($hubKey, 0, 64);
    $normalized = meteonexa_official_hub_normalize_row($row);
    if (!empty($normalized['hubKey']))return substr((string)$normalized['hubKey'], 0, 64);
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
            if (!is_array($stored)||in_array((string)($stored['last_change_type']??''),['expired','cancelled'],true))continue;
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
            $row['lifecycle'] =['status'=>(string)($stored['lifecycle_status']??((string)($stored['last_change_type']??'')==='expired'?'expired':'issued')), 'changeType'=>(string)($stored['last_change_type']??'unchanged'), 'changedAt'=>(string)($stored['last_change_at']??''), 'previousSeverity'=>(string)($stored['previous_severity']??''), 'previousEndsAt'=>(string)($stored['previous_ends_at']??''), 'isFreshChange'=>false];
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
        if (!is_array($stored)||in_array((string)($stored['last_change_type']??''),['expired','cancelled'],true))continue;
        $lastSeen = strtotime((string)($stored['last_seen_at']??'')) ? : 0;
        if ($lastSeen<=0||$lastSeen < $now - 21600)continue;
        $ends = strtotime((string)($stored['ends_at']??'')) ? : 0;
        if ($ends > 0&&$ends < $now - 900)continue;
        $payload = json_decode((string)($stored['payload_json']??'{}'), true);
        if (!is_array($payload))continue;
        $payload = meteonexa_official_enrich_window($payload);
        $payload['severity'] = (string)($stored['severity']??($payload['severity']??'yellow'));
        $payload['lifecycle'] =['status'=>(string)($stored['lifecycle_status']??'issued'), 'changeType'=>(string)($stored['last_change_type']??'unchanged'), 'changedAt'=>(string)($stored['last_change_at']??''), 'previousSeverity'=>(string)($stored['previous_severity']??''), 'previousEndsAt'=>(string)($stored['previous_ends_at']??''), 'isFreshChange'=>false];
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
    $driver = meteonexa_pdo_driver($pdo);
    $hubState = meteonexa_db_column_exists($pdo, 'official_alert_state', 'event_id');
    $hubRevision = meteonexa_db_table_exists($pdo, 'official_alert_revisions')&&meteonexa_db_column_exists($pdo, 'official_alert_revisions', 'event_id');

    $writeRevision = static function(array $row, string $alertKey, string $revisionType, string $lifecycleStatus, string $previousSeverity, string $newSeverity, string $previousEnds, string $newEnds, string $payload) use ($pdo, $locationKey, $now, $hubRevision): void {
        if (!meteonexa_db_table_exists($pdo, 'official_alert_revisions'))return;
        $params = [':location'=>$locationKey, ':alert'=>$alertKey, ':type'=>$revisionType, ':previous_severity'=>$previousSeverity, ':new_severity'=>$newSeverity, ':previous_ends'=>$previousEnds, ':new_ends'=>$newEnds, ':provider'=>meteonexa_official_db_text($row['id']??'', 255), ':payload'=>$payload, ':observed'=>$now];
        if ($hubRevision) {
            $sql = 'INSERT INTO official_alert_revisions(location_key,alert_key,revision_type,previous_severity,new_severity,previous_ends_at,new_ends_at,provider_alert_id,payload_json,observed_at,event_id,version_id,area_key,lifecycle_status) VALUES(:location,:alert,:type,:previous_severity,:new_severity,:previous_ends,:new_ends,:provider,:payload,:observed,:event_id,:version_id,:area_key,:lifecycle_status)';
            $params += [':event_id'=>meteonexa_official_db_text($row['eventId']??'', 255), ':version_id'=>meteonexa_official_db_text($row['versionId']??'', 64), ':area_key'=>meteonexa_official_db_text($row['areaKey']??'', 64), ':lifecycle_status'=>$lifecycleStatus];
        } else {
            $sql = 'INSERT INTO official_alert_revisions(location_key,alert_key,revision_type,previous_severity,new_severity,previous_ends_at,new_ends_at,provider_alert_id,payload_json,observed_at) VALUES(:location,:alert,:type,:previous_severity,:new_severity,:previous_ends,:new_ends,:provider,:payload,:observed)';
        }
        $pdo->prepare($sql)->execute($params);
    };

    foreach ((array)($alerts['relevant']??[]) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_hub_normalize_row(meteonexa_official_enrich_window($row), $lat, $lon);
        $alertKey = meteonexa_official_semantic_key($row);
        $seen[$alertKey] = true;
        $severity = meteonexa_official_hub_severity($row['severity']??'yellow');
        if ($severity==='green')$severity = 'yellow';
        $starts = (string)($row['startsAt']??'');
        $ends = (string)($row['endsAt']??'');
        $hash = hash('sha256', json_encode([$row['versionId']??'', $severity, $starts, $ends, $row['title']??'', $row['summary']??'', $row['areaKey']??''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $st = $pdo->prepare('SELECT * FROM official_alert_state WHERE location_key=:location AND alert_key=:alert LIMIT 1');
        $st->execute([':location'=>$locationKey, ':alert'=>$alertKey]);
        $previous = $st->fetch();
        $previous = is_array($previous) ? $previous : null;
        $previousSeverity = $previous ? (string)$previous['severity'] : '';
        $previousEnds = $previous ? (string)$previous['ends_at'] : '';
        $change = meteonexa_official_change_type($previous, $severity, $ends, $hash);
        $lastType = $change==='unchanged' ? (string)($previous['last_change_type']??'unchanged') : $change;
        $lastAt = $change==='unchanged' ? (string)($previous['last_change_at']??$now) : $now;
        $lifecycleStatus = !$previous ? 'issued' : ($change==='unchanged' ? (string)($previous['lifecycle_status']??'issued') : 'updated');
        if (!in_array($lifecycleStatus,['issued','updated'],true))$lifecycleStatus = 'updated';
        $row['lifecycleStatus'] = $lifecycleStatus;
        $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ? : '{}';
        $params = [':location'=>$locationKey, ':alert'=>$alertKey, ':name'=>$locationName, ':provider'=>meteonexa_official_db_text($row['id']??'', 255), ':severity'=>$severity, ':starts'=>$starts, ':ends'=>$ends, ':source_updated'=>meteonexa_official_db_text($row['updatedAt']??'', 40), ':hash'=>$hash, ':first'=>$previous ? (string)$previous['first_seen_at'] : $now, ':last'=>$now, ':change'=>$lastType, ':change_at'=>$lastAt, ':previous_severity'=>$change==='unchanged' ? (string)($previous['previous_severity']??'') : $previousSeverity, ':previous_ends'=>$change==='unchanged' ? (string)($previous['previous_ends_at']??'') : $previousEnds, ':payload'=>$payload];
        if ($hubState) {
            $columns = 'location_key,alert_key,location_name,provider_alert_id,severity,starts_at,ends_at,source_updated_at,content_hash,first_seen_at,last_seen_at,last_change_type,last_change_at,previous_severity,previous_ends_at,payload_json,event_id,version_id,area_key,lifecycle_status,authority_name,sender,message_type,source_name,geometry_json';
            $values = ':location,:alert,:name,:provider,:severity,:starts,:ends,:source_updated,:hash,:first,:last,:change,:change_at,:previous_severity,:previous_ends,:payload,:event_id,:version_id,:area_key,:lifecycle_status,:authority,:sender,:message_type,:source_name,:geometry';
            $params += [':event_id'=>meteonexa_official_db_text($row['eventId']??'',255), ':version_id'=>meteonexa_official_db_text($row['versionId']??'',64), ':area_key'=>meteonexa_official_db_text($row['areaKey']??'',64), ':lifecycle_status'=>$lifecycleStatus, ':authority'=>meteonexa_official_db_text($row['authority']??'',191), ':sender'=>meteonexa_official_db_text($row['sender']??'',255), ':message_type'=>meteonexa_official_db_text($row['messageType']??'',24), ':source_name'=>meteonexa_official_db_text($row['source']??'',96), ':geometry'=>json_encode($row['geometry']??null, JSON_UNESCAPED_SLASHES) ?: 'null'];
            if ($driver==='mysql') {
                $sql = "INSERT INTO official_alert_state($columns) VALUES($values) ON DUPLICATE KEY UPDATE location_name=VALUES(location_name),provider_alert_id=VALUES(provider_alert_id),severity=VALUES(severity),starts_at=VALUES(starts_at),ends_at=VALUES(ends_at),source_updated_at=VALUES(source_updated_at),content_hash=VALUES(content_hash),last_seen_at=VALUES(last_seen_at),last_change_type=VALUES(last_change_type),last_change_at=VALUES(last_change_at),previous_severity=VALUES(previous_severity),previous_ends_at=VALUES(previous_ends_at),payload_json=VALUES(payload_json),event_id=VALUES(event_id),version_id=VALUES(version_id),area_key=VALUES(area_key),lifecycle_status=VALUES(lifecycle_status),authority_name=VALUES(authority_name),sender=VALUES(sender),message_type=VALUES(message_type),source_name=VALUES(source_name),geometry_json=VALUES(geometry_json)";
            } else {
                $sql = "INSERT INTO official_alert_state($columns) VALUES($values) ON CONFLICT(location_key,alert_key) DO UPDATE SET location_name=excluded.location_name,provider_alert_id=excluded.provider_alert_id,severity=excluded.severity,starts_at=excluded.starts_at,ends_at=excluded.ends_at,source_updated_at=excluded.source_updated_at,content_hash=excluded.content_hash,last_seen_at=excluded.last_seen_at,last_change_type=excluded.last_change_type,last_change_at=excluded.last_change_at,previous_severity=excluded.previous_severity,previous_ends_at=excluded.previous_ends_at,payload_json=excluded.payload_json,event_id=excluded.event_id,version_id=excluded.version_id,area_key=excluded.area_key,lifecycle_status=excluded.lifecycle_status,authority_name=excluded.authority_name,sender=excluded.sender,message_type=excluded.message_type,source_name=excluded.source_name,geometry_json=excluded.geometry_json";
            }
        } else {
            $columns = 'location_key,alert_key,location_name,provider_alert_id,severity,starts_at,ends_at,source_updated_at,content_hash,first_seen_at,last_seen_at,last_change_type,last_change_at,previous_severity,previous_ends_at,payload_json';
            $values = ':location,:alert,:name,:provider,:severity,:starts,:ends,:source_updated,:hash,:first,:last,:change,:change_at,:previous_severity,:previous_ends,:payload';
            $sql = $driver==='mysql'
                ? "INSERT INTO official_alert_state($columns) VALUES($values) ON DUPLICATE KEY UPDATE location_name=VALUES(location_name),provider_alert_id=VALUES(provider_alert_id),severity=VALUES(severity),starts_at=VALUES(starts_at),ends_at=VALUES(ends_at),source_updated_at=VALUES(source_updated_at),content_hash=VALUES(content_hash),last_seen_at=VALUES(last_seen_at),last_change_type=VALUES(last_change_type),last_change_at=VALUES(last_change_at),previous_severity=VALUES(previous_severity),previous_ends_at=VALUES(previous_ends_at),payload_json=VALUES(payload_json)"
                : "INSERT INTO official_alert_state($columns) VALUES($values) ON CONFLICT(location_key,alert_key) DO UPDATE SET location_name=excluded.location_name,provider_alert_id=excluded.provider_alert_id,severity=excluded.severity,starts_at=excluded.starts_at,ends_at=excluded.ends_at,source_updated_at=excluded.source_updated_at,content_hash=excluded.content_hash,last_seen_at=excluded.last_seen_at,last_change_type=excluded.last_change_type,last_change_at=excluded.last_change_at,previous_severity=excluded.previous_severity,previous_ends_at=excluded.previous_ends_at,payload_json=excluded.payload_json";
        }
        $pdo->prepare($sql)->execute($params);
        if ($change!=='unchanged')$writeRevision($row,$alertKey,$change,$lifecycleStatus,$previousSeverity,$severity,$previousEnds,$ends,$payload);
        $row['startsAt'] = $starts ? : null;
        $row['endsAt'] = $ends ? : null;
        $row['lifecycle'] =['status'=>$lifecycleStatus, 'changeType'=>$lastType, 'changedAt'=>$lastAt, 'previousSeverity'=>$change==='unchanged' ? (string)($previous['previous_severity']??'') : $previousSeverity, 'previousEndsAt'=>$change==='unchanged' ? (string)($previous['previous_ends_at']??'') : $previousEnds, 'isFreshChange'=>$change!=='unchanged'];
        $out[] = $row;
        if ($latest===null||strtotime($lastAt) > strtotime((string)($latest['changedAt']??'')))$latest = $row['lifecycle'] +['severity'=>$severity, 'endsAt'=>$ends ? : null, 'eventKind'=>meteonexa_official_event_kind($row)];
    }

    // Explicit CAP cancellation/expiry messages are terminal lifecycle revisions
    // and are never rendered as an active MeteoNexa forecast/warning card.
    foreach ((array)($alerts['terminalRevisions']??[]) as $row) {
        if (!is_array($row))continue;
        $row = meteonexa_official_hub_normalize_row($row, $lat, $lon);
        $alertKey = meteonexa_official_semantic_key($row);
        $seen[$alertKey] = true;
        $status = (string)($row['lifecycleStatus']??'expired');
        if (!in_array($status,['cancelled','expired'],true))$status='expired';
        $st = $pdo->prepare('SELECT * FROM official_alert_state WHERE location_key=:location AND alert_key=:alert LIMIT 1');
        $st->execute([':location'=>$locationKey, ':alert'=>$alertKey]);
        $previous = $st->fetch();
        if (!is_array($previous))continue;
        $revisionType = $status==='cancelled' ? 'cancelled' : 'expired';
        if ((string)($previous['last_change_type']??'')===$revisionType)continue;
        $set = "last_seen_at=:now,last_change_type=:revision,last_change_at=:now" . ($hubState ? ',lifecycle_status=:lifecycle_status,version_id=:version_id,provider_alert_id=:provider' : '');
        $params = [':now'=>$now, ':revision'=>$revisionType, ':location'=>$locationKey, ':alert'=>$alertKey];
        if ($hubState)$params += [':lifecycle_status'=>$status, ':version_id'=>meteonexa_official_db_text($row['versionId']??'',64), ':provider'=>meteonexa_official_db_text($row['id']??'',255)];
        $pdo->prepare("UPDATE official_alert_state SET $set WHERE location_key=:location AND alert_key=:alert")->execute($params);
        $payload = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $writeRevision($row,$alertKey,$revisionType,$status,(string)$previous['severity'],'green',(string)$previous['ends_at'],'',$payload);
    }

    // Disappearance from a fresh authoritative provider response means expiry.
    // Never infer expiry while serving stale/provider-fallback data.
    $freshAuthoritative = ($alerts['providerFresh']??false)===true && empty($alerts['staleProviderCache']) && empty($alerts['pipelineFallback']);
    if ($freshAuthoritative) {
        $active = $pdo->prepare('SELECT * FROM official_alert_state WHERE location_key=:location');
        $active->execute([':location'=>$locationKey]);
        foreach ($active->fetchAll() as $old) {
            if (!is_array($old))continue;
            $key = (string)$old['alert_key'];
            if (isset($seen[$key])||in_array((string)$old['last_change_type'],['expired','cancelled'],true))continue;
            $set = "last_seen_at=:now,last_change_type='expired',last_change_at=:now" . ($hubState ? ",lifecycle_status='expired'" : '');
            $pdo->prepare("UPDATE official_alert_state SET $set WHERE location_key=:location AND alert_key=:alert")->execute([':now'=>$now, ':location'=>$locationKey, ':alert'=>$key]);
            $payload = json_decode((string)($old['payload_json']??'{}'), true); if (!is_array($payload))$payload=[];
            $payload['lifecycleStatus']='expired';
            $writeRevision($payload,$key,'expired','expired',(string)$old['severity'],'green',(string)$old['ends_at'],'',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');
        }
    }
    $alerts['relevant'] = $out;
    $alerts['lifecycle'] = $latest;
    $alerts['lifecycleTracked'] = true;
    return meteonexa_official_hub_contract($alerts);
}
