<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/meteonexa-auth-fallback-' . bin2hex(random_bytes(5));
@mkdir($temp, 0700, true);
putenv('METEONEXA_STORAGE_PATH=' . $temp);
$_SERVER['DOCUMENT_ROOT'] = $root;
require $root . '/api/bootstrap.php';
require $root . '/api/database.php';
require $root . '/api/auth/login_challenge.php';

final class MeteoNexaFailingPDOForSmoke extends PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { throw new RuntimeException('SMOKE_NO_DRIVER'); }
    public function prepare(string $query, array $options = []): PDOStatement|false { return false; }
    public function exec(string $statement): int|false { throw new RuntimeException('SMOKE_NO_DB'); }
}

$pdo = new MeteoNexaFailingPDOForSmoke();
$config = ['auth'=>['app_secret'=>str_repeat('a', 64)]];
$emailHash = hash_hmac('sha256', 'otp:test@example.invalid', str_repeat('a', 64));
$device = 'device-smoke-123456789';
$code = '482913';
$issued = meteonexa_issue_login_challenge($pdo, $config, $emailHash, $device, 'it', $code, time(), time()+600, hash('sha256','ip'));
if (($issued['storage'] ?? '') !== 'file') { fwrite(STDERR, "FAIL storage\n"); exit(1); }
$path = meteonexa_challenge_fallback_path((string)$issued['challengeId'], $config);
if (!is_file($path)) { fwrite(STDERR, "FAIL file missing\n"); exit(1); }
$raw = (string)file_get_contents($path);
if (str_contains($raw, $code) || str_contains($raw, 'test@example.invalid')) { fwrite(STDERR, "FAIL plaintext\n"); exit(1); }
$wrong = meteonexa_consume_login_challenge($pdo, $config, (string)$issued['challengeId'], $emailHash, $device, '000000', 5, time());
if (($wrong['code'] ?? '') !== 'INVALID_CODE' || (int)($wrong['remaining'] ?? -1) !== 4) { fwrite(STDERR, "FAIL wrong code\n"); exit(1); }
$ok = meteonexa_consume_login_challenge($pdo, $config, (string)$issued['challengeId'], $emailHash, $device, $code, 5, time());
if (($ok['ok'] ?? false) !== true || is_file($path)) { fwrite(STDERR, "FAIL consume\n"); exit(1); }
$replay = meteonexa_consume_login_challenge($pdo, $config, (string)$issued['challengeId'], $emailHash, $device, $code, 5, time());
if (($replay['code'] ?? '') !== 'CODE_NOT_FOUND') { fwrite(STDERR, "FAIL replay\n"); exit(1); }
$rate1 = meteonexa_auth_file_rate_limit('smoke', 'id', str_repeat('a',64), 1, 60);
$rate2 = meteonexa_auth_file_rate_limit('smoke', 'id', str_repeat('a',64), 1, 60);
if (($rate1['allowed'] ?? false) !== true || ($rate2['allowed'] ?? true) !== false) { fwrite(STDERR, "FAIL rate fallback\n"); exit(1); }

function rrmdir(string $dir): void { if (!is_dir($dir)) return; foreach (array_diff(scandir($dir) ?: [], ['.','..']) as $name) { $p=$dir.DIRECTORY_SEPARATOR.$name; is_dir($p)?rrmdir($p):@unlink($p); } @rmdir($dir); }
rrmdir($temp);
echo "Auth challenge fallback smoke PASS\n";
