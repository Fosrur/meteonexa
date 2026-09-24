<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/api/database/driver.php';
require_once $root . '/api/database/crypto.php';
require_once $root . '/api/database/schema.php';
require_once $root . '/api/database/migrations.php';

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql missing\n");
    exit(2);
}

$settings = [
    'host' => (string)(getenv('MYSQL_HOST') ?: '127.0.0.1'),
    'port' => (int)(getenv('MYSQL_PORT') ?: 3306),
    'database' => (string)(getenv('MYSQL_DATABASE') ?: 'meteonexa'),
    'username' => (string)(getenv('MYSQL_USER') ?: 'meteonexa'),
    'password' => (string)(getenv('MYSQL_PASSWORD') ?: 'meteonexa'),
];
$pdo = new MeteoNexaMySqlPDO($settings);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    if (preg_match('/^[A-Za-z0-9_]+$/', (string)$table) !== 1) continue;
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$executeSqlFile = static function (PDO $connection, string $path): void {
    $sql = (string)file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement !== '') $connection->exec($statement);
    }
};

$executeSqlFile($pdo, $root . '/api/install/mysql-schema.sql');
$executeSqlFile($pdo, $root . '/api/install/mysql-triggers.sql');

$tableCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
if ($tableCount !== 48) {
    fwrite(STDERR, "expected 48 MySQL tables, got {$tableCount}\n");
    exit(1);
}

// Exercise the real historical upgrade path on a current baseline. The DDL is
// intentionally idempotent: a deployment restored from schema 15 must reach 29
// without destructive drops or duplicate-column failures.
$metadata = $pdo->prepare("INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('schema_version',:version,:updated) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)");
$metadata->execute([':version' => '15', ':updated' => gmdate('c')]);
$version = meteonexa_run_mysql_migrations($pdo, 15);
if ($version !== 29) {
    fwrite(STDERR, "explicit MySQL migrations stopped at {$version}, expected 29\n");
    exit(1);
}
if (array_keys(meteonexa_migration_manifest()) !== range(16, 29)) {
    fwrite(STDERR, "migration manifest is not contiguous from 16 to 29\n");
    exit(1);
}
// Runtime capability sync remains idempotent self-heal after the versioned chain.
meteonexa_sync_verification_schema($pdo);
meteonexa_sync_trust_schema($pdo);
meteonexa_sync_product_metrics_schema($pdo);
meteonexa_sync_current_metadata($pdo);

$current = (int)$pdo->query("SELECT meta_value FROM app_metadata WHERE meta_key='schema_version'")->fetchColumn();
if ($current !== 29) {
    fwrite(STDERR, "current MySQL schema metadata is {$current}, expected 29\n");
    exit(1);
}
foreach (['runtime_metrics', 'radar_eta_predictions', 'predictive_alert_opportunities', 'product_metrics_daily', 'ensemble_verification_samples'] as $table) {
    if (!meteonexa_db_table_exists($pdo, $table)) {
        fwrite(STDERR, "missing current capability table {$table}\n");
        exit(1);
    }
}

// Verify DB-side translation revision triggers, not only table presence.
$before = (int)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='translation_revision'),'0')")->fetchColumn() ?: 0);
$write = $pdo->prepare("INSERT INTO translations(locale,text_key,translation,updated_at) VALUES('en','qa.mysql.integration','ok',:updated) ON DUPLICATE KEY UPDATE translation=VALUES(translation),updated_at=VALUES(updated_at)");
$write->execute([':updated' => gmdate('c')]);
$after = (int)($pdo->query("SELECT COALESCE((SELECT meta_value FROM app_metadata WHERE meta_key='translation_revision'),'0')")->fetchColumn() ?: 0);
if ($after <= $before) {
    fwrite(STDERR, "translation revision trigger did not advance\n");
    exit(1);
}
$pdo->prepare("DELETE FROM translations WHERE locale='en' AND text_key='qa.mysql.integration'")->execute();

echo "MySQL full schema/migration integration PASS\n";
