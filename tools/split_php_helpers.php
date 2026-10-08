<?php
declare(strict_types=1);
$root = realpath($argv[1] ?? '.');
if ($root === false) {
    fwrite(STDERR, "Invalid root\n");
    exit(2);
}
$targets = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/api', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname());
    if (!is_string($source) || substr_count($source, "\n") + 1 <= 500) {
        continue;
    }
    $targets[] = $file->getPathname();
}
function functionRanges(string $source): array
{
    $tokens = token_get_all($source);
    $offset = 0;
    $depth = 0;
    $ranges = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;
        $start = $offset;
        $offset += strlen($text);
        if ($text === '{') {
            $depth++;
            continue;
        }
        if ($text === '}') {
            $depth--;
            continue;
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION || $depth !== 0) {
            continue;
        }
        $named = false;
        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];
            $nextText = is_array($next) ? $next[1] : $next;
            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                continue;
            }
            if ($nextText === '&') {
                continue;
            }
            $named = is_array($next) && $next[0] === T_STRING;
            break;
        }
        if (!$named) {
            continue;
        }
        $scanOffset = $offset;
        $braceDepth = 0;
        $foundBrace = false;
        $end = null;
        for ($j = $i + 1; $j < $count; $j++) {
            $next = $tokens[$j];
            $nextText = is_array($next) ? $next[1] : $next;
            $nextStart = $scanOffset;
            $scanOffset += strlen($nextText);
            if ($nextText === '{') {
                $foundBrace = true;
                $braceDepth++;
            } elseif ($nextText === '}' && $foundBrace) {
                $braceDepth--;
                if ($braceDepth === 0) {
                    $end = $scanOffset;
                    break;
                }
            }
        }
        if ($end !== null) {
            $ranges[] = [$start, $end];
        }
    }
    return $ranges;
}
foreach ($targets as $path) {
    $source = file_get_contents($path);
    $ranges = functionRanges($source);
    if (!$ranges) {
        fwrite(STDERR, "No splittable functions: {$path}\n");
        exit(3);
    }
    $relative = substr($path, strlen($root) + 1);
    $base = pathinfo($path, PATHINFO_FILENAME);
    $componentDir = dirname($path) . '/' . $base;
    if (is_dir($componentDir)) {
        $old = glob($componentDir . '/*.php') ?: [];
        foreach ($old as $candidate) {
            unlink($candidate);
        }
    } else {
        mkdir($componentDir, 0777, true);
    }
    $groups = [];
    $current = [];
    $currentLines = 2;
    foreach ($ranges as [$start, $end]) {
        $body = trim(substr($source, $start, $end - $start));
        $bodyLines = substr_count($body, "\n") + 1;
        if ($bodyLines > 450) {
            fwrite(STDERR, "Single function exceeds 450 lines in {$relative}\n");
            exit(4);
        }
        if ($current && $currentLines + $bodyLines + 2 > 450) {
            $groups[] = $current;
            $current = [];
            $currentLines = 2;
        }
        $current[] = $body;
        $currentLines += $bodyLines + 2;
    }
    if ($current) {
        $groups[] = $current;
    }
    $requires = [];
    foreach ($groups as $index => $functions) {
        $filename = sprintf('component-%02d.php', $index + 1);
        $componentPath = $componentDir . '/' . $filename;
        $content = "<?php\ndeclare(strict_types=1);\n" . implode("\n\n", $functions) . "\n";
        file_put_contents($componentPath, $content);
        $requires[] = "require_once __DIR__ . '/{$base}/{$filename}';";
    }
    $pieces = [];
    $cursor = 0;
    foreach ($ranges as [$start, $end]) {
        $pieces[] = substr($source, $cursor, $start - $cursor);
        $cursor = $end;
    }
    $pieces[] = substr($source, $cursor);
    $loader = trim(implode('', $pieces));
    if (!str_starts_with($loader, '<?php')) {
        fwrite(STDERR, "Invalid loader after split: {$relative}\n");
        exit(5);
    }
    $requireBlock = implode("\n", $requires) . "\n";
    if (preg_match('/^<\?php\s*declare\s*\(strict_types\s*=\s*1\s*\)\s*;\s*/', $loader, $match) === 1) {
        $insertAt = strlen($match[0]);
        $loader = substr($loader, 0, $insertAt) . $requireBlock . substr($loader, $insertAt);
    } else {
        $insertAt = strlen('<?php');
        $loader = substr($loader, 0, $insertAt) . "\n" . $requireBlock . substr($loader, $insertAt);
    }
    file_put_contents($path, rtrim($loader) . "\n");
    $loaderLines = substr_count($loader, "\n") + 1;
    if ($loaderLines > 500) {
        fwrite(STDERR, "Loader still exceeds 500 lines: {$relative} ({$loaderLines})\n");
        exit(6);
    }
    echo "Split {$relative} into " . count($groups) . " components\n";
}
