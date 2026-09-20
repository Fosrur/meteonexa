<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/database.php';
$fail=[];
function expect_sql(string $input, string $needle, string $label): void { global $fail; $out=meteonexa_mysql_rewrite_sql($input); if(str_contains($out,$needle)) echo "[ OK ] {$label}\n"; else {echo "[FAIL] {$label}\n  {$out}\n";$fail[]=$label;} }
expect_sql('BEGIN IMMEDIATE','START TRANSACTION','BEGIN IMMEDIATE -> START TRANSACTION');
expect_sql('INSERT OR IGNORE INTO t(a) VALUES(1)','INSERT IGNORE INTO','INSERT OR IGNORE -> INSERT IGNORE');
expect_sql('INSERT INTO t(a,b) VALUES(:a,:b) ON CONFLICT(a) DO UPDATE SET b=excluded.b','ON DUPLICATE KEY UPDATE b=VALUES(b)','UPSERT -> ON DUPLICATE KEY UPDATE');
expect_sql('SELECT CAST(a AS INTEGER) FROM t','CAST(a AS SIGNED)','INTEGER cast -> SIGNED');
if($fail){fwrite(STDERR,"SQL rewrite smoke FAILED\n");exit(1);}echo "SQL rewrite smoke PASS\n";
