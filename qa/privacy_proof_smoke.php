<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/database.php';

$failures = [];
function privacy_check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) echo "[ OK ] {$label}\n";
    else { echo "[FAIL] {$label}\n"; $failures[] = $label; }
}

$config = ['auth' => ['app_secret' => str_repeat('meteonexa-privacy-proof-', 3)]];
$secret = (string)$config['auth']['app_secret'];
$code = '381604';
$email = 'guest@example.test';
$now = time();

$challengePayload = json_encode([
    'purpose' => 'guest-cache-reset-verify-v1',
    'email' => $email,
    'codeHash' => hash_hmac('sha256', $code, $secret),
    'nonce' => bin2hex(random_bytes(16)),
    'issuedAt' => $now,
    'expiresAt' => $now + 600,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$challenge = meteonexa_encrypt_value($challengePayload, $config, 'privacy-cache-challenge');
$decoded = json_decode(meteonexa_decrypt_value($challenge, $config, 'privacy-cache-challenge'), true, 16, JSON_THROW_ON_ERROR);

privacy_check(str_starts_with($challenge, 'enc:v1:'), 'guest challenge is AES-GCM protected');
privacy_check(($decoded['purpose'] ?? '') === 'guest-cache-reset-verify-v1', 'challenge is bound to guest privacy verification purpose');
privacy_check(($decoded['email'] ?? '') === $email, 'verified mailbox is carried only inside protected challenge');
privacy_check(hash_equals((string)$decoded['codeHash'], hash_hmac('sha256', $code, $secret)), 'correct six-digit code verifies');
privacy_check(!hash_equals((string)$decoded['codeHash'], hash_hmac('sha256', '000000', $secret)), 'wrong code does not verify');
privacy_check((int)($decoded['expiresAt'] ?? 0) - (int)($decoded['issuedAt'] ?? 0) === 600, 'challenge lifetime is ten minutes');

$proofPayload = json_encode([
    'purpose' => 'guest-cache-reset-proof-v1',
    'email' => $email,
    'nonce' => bin2hex(random_bytes(16)),
    'verifiedAt' => $now,
    'expiresAt' => $now + 300,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$proof = meteonexa_encrypt_value($proofPayload, $config, 'privacy-cache-proof');
$proofDecoded = json_decode(meteonexa_decrypt_value($proof, $config, 'privacy-cache-proof'), true, 16, JSON_THROW_ON_ERROR);
privacy_check(($proofDecoded['purpose'] ?? '') === 'guest-cache-reset-proof-v1', 'receipt proof is purpose-bound');
privacy_check(($proofDecoded['email'] ?? '') === $email, 'receipt recipient comes from verified protected proof');
privacy_check((int)($proofDecoded['expiresAt'] ?? 0) - (int)($proofDecoded['verifiedAt'] ?? 0) === 300, 'receipt proof lifetime is five minutes');

$wrongContextRejected = false;
try { meteonexa_decrypt_value($proof, $config, 'privacy-cache-challenge'); }
catch (Throwable $error) { $wrongContextRejected = true; }
privacy_check($wrongContextRejected, 'AES-GCM context separation rejects proof as challenge');

if ($failures) {
    fwrite(STDERR, "\nPrivacy proof smoke FAILED: " . count($failures) . "\n");
    exit(1);
}
echo "\nPrivacy proof smoke PASS\n";
