<?php
declare(strict_types=1);
function meteonexa_radar_signal_grid(string $path, int $size = 32) : ? array {
    if (!function_exists('imagecreatefromstring')||!is_file($path)||(int)@filesize($path)<=0)return null;
    $bytes = @file_get_contents($path);
    if (!is_string($bytes)||$bytes==='')return null;
    $image = @imagecreatefromstring($bytes);
    if (!$image)return null;
    $w = imagesx($image);
    $h = imagesy($image);
    if ($w < 8||$h < 8) {
        imagedestroy($image);
        return null;
    }
    $grid =[];
    $sum = 0.0;
    $cx = 0.0;
    $cy = 0.0;
    for ($gy = 0; $gy < $size; $gy++) {
        $row =[];
        $py = (int)floor(($gy + 0.5) * $h / $size);
        $py = max(0, min($h - 1, $py));
        for ($gx = 0; $gx < $size; $gx++) {
            $px = (int)floor(($gx + 0.5) * $w / $size);
            $px = max(0, min($w - 1, $px));
            $rgba = imagecolorat($image, $px, $py);
            $a =($rgba>>24)&0x7F;
            $r =($rgba>>16)&0xFF;
            $g =($rgba>>8)&0xFF;
            $b = $rgba&0xFF;
            $opacity =(127 - $a) / 127;
            $max = max($r, $g, $b);
            $min = min($r, $g, $b);
            $sat = $max > 0 ?($max - $min) / $max : 0;
            
            
            $v = meteonexa_intel_clamp($opacity * max(0.0,($sat - .08) / .72), 0, 1);
            $row[] = $v;
            $sum+=$v;
            $cx+=$gx * $v;
            $cy+=$gy * $v;
        }
        $grid[] = $row;
    }
    imagedestroy($image);
    return['grid'=>$grid, 'sum'=>$sum, 'cx'=>$sum > 0 ? $cx / $sum : null, 'cy'=>$sum > 0 ? $cy / $sum : null, 'size'=>$size];
}
function meteonexa_radar_pair_motion(array $old, array $new) : array {
    $size = (int)$new['size'];
    $bestScore = - INF;
    $second = - INF;
    $bestDx = 0;
    $bestDy = 0;
    for ($dy = - 5; $dy<=5; $dy++) for ($dx = - 5; $dx<=5; $dx++) {
        $score = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($y = 5; $y < $size - 5; $y++) for ($x = 5; $x < $size - 5; $x++) {
            $xx = $x - $dx;
            $yy = $y - $dy;
            if ($xx < 0||$yy < 0||$xx>=$size||$yy>=$size)continue;
            $a = $old['grid'][$yy][$xx];
            $b = $new['grid'][$y][$x];
            $score+=$a * $b;
            $normA+=$a * $a;
            $normB+=$b * $b;
        }
        $corr =($normA > 0&&$normB > 0) ? $score / sqrt($normA * $normB) : 0;
        if ($corr > $bestScore) {
            $second = $bestScore;
            $bestScore = $corr;
            $bestDx = $dx;
            $bestDy = $dy;
        } elseif ($corr > $second) {
            $second = $corr;
        }
    }
    $confidence = (int)round(meteonexa_intel_clamp(($bestScore - .15) * 120 + max(0, $bestScore - $second) * 180, 8, 95));
    return['dx'=>$bestDx, 'dy'=>$bestDy, 'correlation'=>$bestScore, 'confidence'=>$confidence];
}
function meteonexa_radar_components(array $signal, float $threshold = .14, int $minPixels = 3) : array {
    $grid = (array)($signal['grid']??[]);
    $n = (int)($signal['size']??count($grid));
    if ($n < 2)return[];
    $seen =[];
    $out =[];
    for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
        $key = $y . ':' . $x;
        if (isset($seen[$key])||(float)($grid[$y][$x]??0) < $threshold)continue;
        $stack =[[$x, $y]];
        $seen[$key] = 1;
        $count = 0;
        $sum = 0.0;
        $cx = 0.0;
        $cy = 0.0;
        $peak = 0.0;
        $minX = $x;
        $maxX = $x;
        $minY = $y;
        $maxY = $y;
        while ($stack) {
            [$xx, $yy] = array_pop($stack);
            $v = (float)($grid[$yy][$xx]??0);
            $count++;
            $sum+=$v;
            $cx+=$xx * $v;
            $cy+=$yy * $v;
            $peak = max($peak, $v);
            $minX = min($minX, $xx);
            $maxX = max($maxX, $xx);
            $minY = min($minY, $yy);
            $maxY = max($maxY, $yy);
            foreach ([[1, 0],[ - 1, 0],[0, 1],[0, - 1]] as[$dx, $dy]) {
                $nx = $xx + $dx;
                $ny = $yy + $dy;
                if ($nx < 0||$ny < 0||$nx>=$n||$ny>=$n)continue;
                $nk = $ny . ':' . $nx;
                if (isset($seen[$nk])||(float)($grid[$ny][$nx]??0) < $threshold)continue;
                $seen[$nk] = 1;
                $stack[] =[$nx, $ny];
            }
        }
        if ($count < $minPixels||$sum<=0)continue;
        $out[] =['pixels'=>$count, 'energy'=>round($sum, 3), 'peak'=>round($peak, 3), 'cx'=>round($cx / $sum, 2), 'cy'=>round($cy / $sum, 2), 'bbox'=>['minX'=>$minX, 'minY'=>$minY, 'maxX'=>$maxX, 'maxY'=>$maxY], 'width'=>$maxX - $minX + 1, 'height'=>$maxY - $minY + 1];
    }
    usort($out, static fn($a, $b)=>($b['energy']<=>$a['energy']));
    return array_slice($out, 0, 8);
}
function meteonexa_radar_multicell_tracks(array $old, array $new, float $minutes) : array {
    $before = meteonexa_radar_components($old);
    $after = meteonexa_radar_components($new);
    $tracks =[];
    $used =[];
    $minutes = max(1, $minutes);
    foreach ($after as $i=>$cell) {
        $best = null;
        $bestD = INF;
        foreach ($before as $j=>$prev) {
            $d = hypot((float)$cell['cx'] - (float)$prev['cx'], (float)$cell['cy'] - (float)$prev['cy']);
            if ($d < $bestD&&$d<=8) {
                $bestD = $d;
                $best = $j;
            }
        }
        $prev = $best===null ? null : $before[$best];
        if ($best!==null)$used[$best] =($used[$best]??0) + 1;
        $growth = $prev&&($prev['energy']??0) > 0 ? 100 *((float)$cell['energy'] - (float)$prev['energy']) / (float)$prev['energy'] : null;
        $vx = $prev ?((float)$cell['cx'] - (float)$prev['cx']) / $minutes : 0.0;
        $vy = $prev ?((float)$cell['cy'] - (float)$prev['cy']) / $minutes : 0.0;
        $angle = atan2($vx, - $vy) * 180 / M_PI;
        if ($angle < 0)$angle+=360;
        $dirs =['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        $direction = $prev ? $dirs[((int)round($angle / 45)) % 8] : null;
        $stage = $growth===null ? 'new' :($growth > 20 ? 'growing' :($growth < - 20 ? 'decaying' : 'stable'));
        $tracks[] =['id'=>'cell-' . substr(hash('sha256', round((float)$cell['cx'], 1) . '|' . round((float)$cell['cy'], 1)), 0, 8), 'centroid'=>['x'=>$cell['cx'], 'y'=>$cell['cy']], 'energy'=>$cell['energy'], 'peak'=>$cell['peak'], 'growthPct'=>$growth===null ? null : round($growth, 1), 'stage'=>$stage, 'direction'=>$direction, 'vxCellsMin'=>round($vx, 3), 'vyCellsMin'=>round($vy, 3), 'matched'=>$prev!==null, 'splitCandidate'=>$best!==null&&($used[$best]??0) > 1, 'trajectoryCone'=>array_map(static fn($m)=>['minute'=>$m, 'x'=>round((float)$cell['cx'] + $vx * $m, 2), 'y'=>round((float)$cell['cy'] + $vy * $m, 2), 'radiusCells'=>round(1.2 + $m * .035, 2)],[15, 30, 45, 60, 90])];
    }
    $merge = false;
    if (count($before) > count($after)&&count($after) > 0)$merge = true;
    return['available'=>$tracks!==[], 'cells'=>$tracks, 'cellCount'=>count($tracks), 'splitDetected'=>count(array_filter($tracks, static fn($c)=>!empty($c['splitCandidate']))) > 0, 'mergeDetected'=>$merge, 'method'=>'connected-components-centroid-tracking'];
}
