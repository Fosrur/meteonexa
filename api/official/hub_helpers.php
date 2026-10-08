<?php
declare(strict_types=1);








function meteonexa_official_hub_version(): int { return 1; }

function meteonexa_official_hub_iso(mixed $value): ?string {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    $ts = strtotime($raw);
    return $ts === false ? null : gmdate('c', $ts);
}

function meteonexa_official_hub_severity(mixed $value): string {
    $raw = strtolower(trim((string)$value));
    if ($raw === '') return 'yellow';
    if (preg_match('/(^|[^0-9])1(?:[^0-9]|$)/', $raw) === 1 || str_contains($raw, 'green') || str_contains($raw, 'minor')) return 'green';
    if (preg_match('/(^|[^0-9])4(?:[^0-9]|$)/', $raw) === 1 || str_contains($raw, 'red') || str_contains($raw, 'extreme')) return 'red';
    if (preg_match('/(^|[^0-9])3(?:[^0-9]|$)/', $raw) === 1 || str_contains($raw, 'orange') || str_contains($raw, 'severe')) return 'orange';
    return 'yellow';
}

function meteonexa_official_hub_enum(mixed $value, array $allowed, string $fallback): string {
    $raw = strtolower(trim((string)$value));
    foreach ($allowed as $candidate) if ($raw === strtolower((string)$candidate)) return strtolower((string)$candidate);
    return $fallback;
}

function meteonexa_official_hub_ring(array $ring): array {
    $out = [];
    foreach ($ring as $point) {
        if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) continue;
        $lon = max(-180.0, min(180.0, (float)$point[0]));
        $lat = max(-90.0, min(90.0, (float)$point[1]));
        $out[] = [round($lon, 6), round($lat, 6)];
        if (count($out) >= 5000) break;
    }
    if (count($out) < 4) return [];
    if ($out[0] !== $out[count($out) - 1]) $out[] = $out[0];
    return $out;
}

function meteonexa_official_hub_geometry(mixed $geometry): ?array {
    if (!is_array($geometry)) return null;
    $type = (string)($geometry['type'] ?? '');
    $coords = $geometry['coordinates'] ?? null;
    if ($type === 'Polygon' && is_array($coords)) {
        $rings = [];
        foreach ($coords as $ring) {
            $clean = meteonexa_official_hub_ring(is_array($ring) ? $ring : []);
            if ($clean) $rings[] = $clean;
        }
        return $rings ? ['type' => 'Polygon', 'coordinates' => $rings] : null;
    }
    if ($type === 'MultiPolygon' && is_array($coords)) {
        $polygons = [];
        foreach ($coords as $polygon) {
            if (!is_array($polygon)) continue;
            $rings = [];
            foreach ($polygon as $ring) {
                $clean = meteonexa_official_hub_ring(is_array($ring) ? $ring : []);
                if ($clean) $rings[] = $clean;
            }
            if ($rings) $polygons[] = $rings;
        }
        return $polygons ? ['type' => 'MultiPolygon', 'coordinates' => $polygons] : null;
    }
    return null;
}


function meteonexa_official_hub_cap_polygon(mixed $value): ?array {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    $ring = [];
    foreach (preg_split('/\s+/', $raw) ?: [] as $pair) {
        $parts = explode(',', trim($pair));
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) continue;
        $ring[] = [(float)$parts[1], (float)$parts[0]];
    }
    $ring = meteonexa_official_hub_ring($ring);
    return $ring ? ['type' => 'Polygon', 'coordinates' => [$ring]] : null;
}


function meteonexa_official_hub_cap_circle(mixed $value): ?array {
    $raw = trim((string)$value);
    if ($raw === '') return null;
    $parts = preg_split('/\s+/', $raw) ?: [];
    if (count($parts) < 2 || !is_numeric($parts[1])) return null;
    $centre = explode(',', $parts[0]);
    if (count($centre) !== 2 || !is_numeric($centre[0]) || !is_numeric($centre[1])) return null;
    $lat = (float)$centre[0]; $lon = (float)$centre[1]; $radiusKm = max(0.0, min(1000.0, (float)$parts[1]));
    if ($radiusKm <= 0.0) return null;
    $earth = 6371.0088; $angular = $radiusKm / $earth;
    $lat1 = deg2rad($lat); $lon1 = deg2rad($lon); $ring = [];
    for ($i = 0; $i <= 36; $i++) {
        $bearing = deg2rad(($i % 36) * 10.0);
        $lat2 = asin(sin($lat1) * cos($angular) + cos($lat1) * sin($angular) * cos($bearing));
        $lon2 = $lon1 + atan2(sin($bearing) * sin($angular) * cos($lat1), cos($angular) - sin($lat1) * sin($lat2));
        $ring[] = [rad2deg($lon2), rad2deg($lat2)];
    }
    $clean = meteonexa_official_hub_ring($ring);
    return $clean ? ['type' => 'Polygon', 'coordinates' => [$clean]] : null;
}

function meteonexa_official_hub_point_in_ring(float $lon, float $lat, array $ring): bool {
    $inside = false; $count = count($ring);
    if ($count < 4) return false;
    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $a = $ring[$i] ?? null; $b = $ring[$j] ?? null;
        if (!is_array($a) || !is_array($b) || count($a) < 2 || count($b) < 2) continue;
        $xi = (float)$a[0]; $yi = (float)$a[1]; $xj = (float)$b[0]; $yj = (float)$b[1];
        $intersects = (($yi > $lat) !== ($yj > $lat)) && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
        if ($intersects) $inside = !$inside;
    }
    return $inside;
}

function meteonexa_official_hub_geometry_contains(array $geometry, float $lat, float $lon): bool {
    $clean = meteonexa_official_hub_geometry($geometry);
    if (!$clean) return false;
    $insidePolygon = static function(array $rings) use ($lat, $lon): bool {
        if (!$rings || !meteonexa_official_hub_point_in_ring($lon, $lat, (array)$rings[0])) return false;
        for ($i = 1; $i < count($rings); $i++) if (meteonexa_official_hub_point_in_ring($lon, $lat, (array)$rings[$i])) return false;
        return true;
    };
    if ($clean['type'] === 'Polygon') return $insidePolygon((array)$clean['coordinates']);
    foreach ((array)$clean['coordinates'] as $polygon) if ($insidePolygon((array)$polygon)) return true;
    return false;
}

function meteonexa_official_hub_area_key(?array $geometry, string $areaText = '', array $geocodes = []): string {
    $basis = '';
    if ($geometry) $basis = 'geo:' . json_encode($geometry, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    elseif ($geocodes) {
        ksort($geocodes, SORT_STRING);
        $basis = 'codes:' . json_encode($geocodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else $basis = 'text:' . strtolower(trim($areaText));
    return substr(hash('sha256', $basis), 0, 40);
}

function meteonexa_official_hub_provider_key(string $source, string $authority, string $sender): string {
    $sourceKey = strtolower(trim($source));
    $authorityKey = strtolower(trim($authority));
    $senderKey = strtolower(trim($sender));
    
    
    if (str_contains($sourceKey, 'meteoalarm') || str_contains($authorityKey, 'meteoalarm')) return 'meteoalarm';
    if ($senderKey !== '') return $senderKey;
    if ($authorityKey !== '') return $authorityKey;
    return $sourceKey !== '' ? $sourceKey : 'official-warning';
}

function meteonexa_official_hub_reference_root(mixed $references, string $fallback): string {
    $items = is_array($references) ? $references : preg_split('/\s+/', trim((string)$references));
    foreach ($items ?: [] as $item) {
        if (is_array($item)) {
            $id = trim((string)($item['identifier'] ?? $item['id'] ?? ''));
            if ($id !== '') return $id;
            continue;
        }
        $parts = explode(',', trim((string)$item));
        if (count($parts) >= 2 && trim($parts[1]) !== '') return trim($parts[1]);
    }
    return $fallback;
}

function meteonexa_official_hub_normalize_row(array $row, ?float $lat = null, ?float $lon = null): array {
    $geometry = meteonexa_official_hub_geometry($row['geometry'] ?? null);
    if (!$geometry && !empty($row['polygons']) && is_array($row['polygons'])) {
        $parts = [];
        foreach ($row['polygons'] as $polygon) { $parsed = meteonexa_official_hub_cap_polygon($polygon); if ($parsed) $parts[] = $parsed['coordinates']; }
        if ($parts) $geometry = count($parts) === 1 ? ['type'=>'Polygon','coordinates'=>$parts[0]] : ['type'=>'MultiPolygon','coordinates'=>$parts];
    }
    if (!$geometry && !empty($row['polygon'])) $geometry = meteonexa_official_hub_cap_polygon($row['polygon']);
    if (!$geometry && !empty($row['circles']) && is_array($row['circles'])) {
        $parts = [];
        foreach ($row['circles'] as $circle) { $parsed = meteonexa_official_hub_cap_circle($circle); if ($parsed) $parts[] = $parsed['coordinates']; }
        if ($parts) $geometry = count($parts) === 1 ? ['type'=>'Polygon','coordinates'=>$parts[0]] : ['type'=>'MultiPolygon','coordinates'=>$parts];
    }
    if (!$geometry && !empty($row['circle'])) $geometry = meteonexa_official_hub_cap_circle($row['circle']);
    $area = trim((string)($row['area'] ?? $row['areaDesc'] ?? ''));
    $geocodes = is_array($row['geocodes'] ?? null) ? (array)$row['geocodes'] : [];
    $areaKey = meteonexa_official_hub_area_key($geometry, $area, $geocodes);
    $messageId = trim((string)($row['identifier'] ?? $row['id'] ?? $row['providerAlertId'] ?? ''));
    if ($messageId === '') $messageId = substr(hash('sha256', json_encode([$row['source'] ?? '', $row['title'] ?? '', $row['updatedAt'] ?? '', $areaKey])), 0, 48);
    $eventId = meteonexa_official_hub_reference_root($row['references'] ?? [], $messageId);
    $sentAt = meteonexa_official_hub_iso($row['sentAt'] ?? $row['sent'] ?? $row['updatedAt'] ?? null);
    $updatedAt = meteonexa_official_hub_iso($row['updatedAt'] ?? $row['sentAt'] ?? $row['sent'] ?? null);
    $startsAt = meteonexa_official_hub_iso($row['startsAt'] ?? $row['onset'] ?? $row['effective'] ?? null);
    $endsAt = meteonexa_official_hub_iso($row['endsAt'] ?? $row['expires'] ?? null);
    $msgType = meteonexa_official_hub_enum($row['messageType'] ?? $row['msgType'] ?? 'alert', ['alert','update','cancel','ack','error'], 'alert');
    $capStatus = meteonexa_official_hub_enum($row['status'] ?? 'actual', ['actual','exercise','system','test','draft'], 'actual');
    $severity = meteonexa_official_hub_severity($row['severity'] ?? $row['awarenessLevel'] ?? 'yellow');
    $certainty = meteonexa_official_hub_enum($row['certainty'] ?? 'unknown', ['observed','likely','possible','unlikely','unknown'], 'unknown');
    $urgency = meteonexa_official_hub_enum($row['urgency'] ?? 'unknown', ['immediate','expected','future','past','unknown'], 'unknown');
    $source = trim((string)($row['source'] ?? 'Official warning')) ?: 'Official warning';
    $authority = trim((string)($row['authority'] ?? $row['senderName'] ?? $row['sender'] ?? $source)) ?: $source;
    $sender = trim((string)($row['sender'] ?? ''));
    $event = trim((string)($row['event'] ?? $row['eventCode'] ?? $row['title'] ?? 'weather'));
    $title = trim((string)($row['title'] ?? $row['headline'] ?? $event));
    $summary = trim((string)($row['summary'] ?? $row['description'] ?? ''));
    $instruction = trim((string)($row['instruction'] ?? ''));
    $geospatialMatch = $geometry !== null && $lat !== null && $lon !== null ? meteonexa_official_hub_geometry_contains($geometry, $lat, $lon) : (bool)($row['geospatialMatch'] ?? false);
    $lifecycleStatus = $msgType === 'cancel' ? 'cancelled' : 'issued';
    if ($msgType === 'update') $lifecycleStatus = 'updated';
    if ($endsAt !== null && strtotime($endsAt) < time() - 60) $lifecycleStatus = 'expired';
    $versionBasis = [$messageId, $sentAt, $updatedAt, $severity, $startsAt, $endsAt, $title, $summary, $areaKey, $msgType];
    $versionId = substr(hash('sha256', json_encode($versionBasis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 40);
    $providerKey = meteonexa_official_hub_provider_key($source, $authority, $sender);
    $hubKey = substr(hash('sha256', $providerKey . '|' . $eventId . '|' . $areaKey), 0, 64);
    return array_replace($row, [
        'id' => $messageId,
        'identifier' => $messageId,
        'eventId' => $eventId,
        'versionId' => $versionId,
        'hubKey' => $hubKey,
        'hubVersion' => meteonexa_official_hub_version(),
        'providerKey' => $providerKey,
        'official' => true,
        'origin' => 'official-warning-authority',
        'source' => $source,
        'authority' => $authority,
        'sender' => $sender,
        'messageType' => $msgType,
        'capStatus' => $capStatus,
        'event' => $event,
        'title' => $title,
        'summary' => $summary,
        'instruction' => $instruction,
        'severity' => $severity,
        'certainty' => $certainty,
        'urgency' => $urgency,
        'sentAt' => $sentAt,
        'updatedAt' => $updatedAt,
        'startsAt' => $startsAt,
        'endsAt' => $endsAt,
        'area' => $area,
        'areaKey' => $areaKey,
        'geometry' => $geometry,
        'geocodes' => $geocodes,
        'geospatialMatch' => $geospatialMatch,
        'lifecycleStatus' => $lifecycleStatus,
        'forecastAuthoritySeparated' => true,
    ]);
}


function meteonexa_official_hub_dedupe(array $rows, ?float $lat = null, ?float $lon = null): array {
    $latest = [];
    foreach ($rows as $raw) {
        if (!is_array($raw)) continue;
        $row = meteonexa_official_hub_normalize_row($raw, $lat, $lon);
        $key = (string)$row['hubKey'];
        $stamp = strtotime((string)($row['updatedAt'] ?? $row['sentAt'] ?? '')) ?: 0;
        $current = $latest[$key] ?? null;
        $currentStamp = is_array($current) ? (strtotime((string)($current['updatedAt'] ?? $current['sentAt'] ?? '')) ?: 0) : -1;
        if (!is_array($current) || $stamp >= $currentStamp) $latest[$key] = $row;
    }
    return array_values($latest);
}

function meteonexa_official_hub_contract(array $alerts): array {
    $alerts['hub'] = [
        'version' => meteonexa_official_hub_version(),
        'officialOnly' => true,
        'forecastAuthoritySeparated' => true,
        'dedupe' => 'event+area/latest-version',
        'geofencing' => !empty($alerts['geospatial']) ? 'polygon' : 'provider-fallback',
        'lifecycle' => ['issued','updated','cancelled','expired'],
    ];
    return $alerts;
}
