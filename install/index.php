<?php
declare(strict_types=1);

@ini_set('display_errors','0');
@ini_set('log_errors','1');
error_reporting(E_ALL);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') header('Strict-Transport-Security: max-age=31536000');

$root = dirname(__DIR__);
require_once $root . '/api/storage_helpers.php';
require_once $root . '/api/database.php';
require_once $root . '/api/error_page.php';
require_once __DIR__ . '/InstallerException.php';

$enableFile = __DIR__ . '/ENABLE_INSTALL';
try { $installLockPath = meteonexa_storage_path() . '/install.lock'; } catch (Throwable $ignored) { $installLockPath = ''; }
if (($installLockPath !== '' && is_file($installLockPath)) || !is_file($enableFile)) { meteonexa_render_error_page(404, ['path'=>'/install/']); }
$installKey = '';
foreach (preg_split('/\R/', (string)@file_get_contents($enableFile)) ?: [] as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $installKey = $line;
    break;
}
if (strlen($installKey) < 24 || str_contains($installKey,'CHANGE_THIS')) { meteonexa_render_error_page(503, ['path'=>'/install/']); }

function install_assert_same_origin(): void {
    $site = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($site !== '' && !in_array($site, ['same-origin','none'], true)) {
        install_fail('INSTALL_ORIGIN_REJECTED');
    }
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($origin !== '' && $host !== '') {
        $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?: ''));
        $originPort = (int)(parse_url($origin, PHP_URL_PORT) ?: 0);
        $hostOnly = strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
        $hostPort = preg_match('/:(\d+)$/', $host, $match) ? (int)$match[1] : 0;
        if ($originHost === '' || !hash_equals($hostOnly, $originHost) || ($originPort > 0 && $hostPort > 0 && $originPort !== $hostPort)) {
            install_fail('INSTALL_ORIGIN_INVALID');
        }
    }
}

function install_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function install_storage_secret(string $sourceDb): string {
    $env = trim((string)(getenv('METEONEXA_APP_SECRET') ?: ''));
    if (strlen($env) >= 32) return $env;
    $storage = meteonexa_storage_path();
    if (!is_dir($storage) && !@mkdir($storage,0770,true) && !is_dir($storage)) install_fail('INSTALL_STORAGE_UNAVAILABLE');
    $path = $storage . '/.app-secret';
    if (is_file($path)) {
        $secret = trim((string)@file_get_contents($path));
        if (strlen($secret) >= 32) { @chmod($path,0600); return $secret; }
    }
    $hasProtected = false;
    if (is_file($sourceDb)) {
        $probe = new PDO('sqlite:' . $sourceDb, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        foreach ([
            "SELECT COUNT(*) FROM smtp_settings WHERE COALESCE(password_encrypted,'')<>''",
            "SELECT COUNT(*) FROM ai_settings WHERE COALESCE(api_key_encrypted,'')<>''",
            "SELECT COUNT(*) FROM netatmo_accounts WHERE COALESCE(access_token_enc,'')<>'' OR COALESCE(refresh_token_enc,'')<>''",
            "SELECT COUNT(*) FROM auth_sessions",
            "SELECT COUNT(*) FROM trusted_devices"
        ] as $query) {
            try { if ((int)$probe->query($query)->fetchColumn() > 0) { $hasProtected = true; break; } } catch (Throwable $ignored) { }
        }
        $probe = null;
    }
    if ($hasProtected) install_fail('INSTALL_APP_SECRET_REQUIRED');
    $secret = bin2hex(random_bytes(32));
    $tmp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
    if (@file_put_contents($tmp,$secret."\n",LOCK_EX)===false || !@rename($tmp,$path)) { @unlink($tmp); install_fail('INSTALL_APP_SECRET_CREATE_FAILED'); }
    @chmod($path,0600);
    return $secret;
}
function install_mysql(array $input): PDO {
    if (!extension_loaded('pdo_mysql')) install_fail('INSTALL_PDO_MYSQL_UNAVAILABLE', 'install.error.requirement');
    $host = trim((string)($input['host'] ?? ''));
    $db = trim((string)($input['database'] ?? ''));
    $user = trim((string)($input['username'] ?? ''));
    $pass = (string)($input['password'] ?? '');
    $port = max(1,min(65535,(int)($input['port'] ?? 3306)));
    if ($host==='' || $db==='' || $user==='') install_fail('INSTALL_MYSQL_CONFIG_INVALID');
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>true,
        PDO::ATTR_TIMEOUT=>10,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    try { $pdo->exec("SET time_zone='+00:00'"); } catch (Throwable $ignored) { }
    return $pdo;
}
function install_exec_sql_file(PDO $pdo, string $path): void {
    $sql = (string)@file_get_contents($path);
    if ($sql==='') install_fail('INSTALL_MYSQL_SCHEMA_MISSING');
    $sql = preg_replace('/^\s*--.*$/m','',$sql) ?? $sql;
    foreach (preg_split('/;\s*(?:\r?\n|$)/',$sql) ?: [] as $statement) {
        $statement = trim($statement);
        if ($statement!=='') $pdo->exec($statement);
    }
}
function install_source_tables(PDO $sqlite): array {
    $rows = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $priority = ['app_metadata','smtp_settings','ai_settings','translations','browser_preferences','app_preferences','ui_visibility','alert_profiles','personal_weather_preferences','device_credentials','auth_otp','auth_sessions','trusted_devices','rate_limits','model_forecast_samples','model_observation_samples','forecast_snapshots','synoptic_snapshots','radar_archive_locations','radar_archive_frames','push_subscriptions','push_notifications','oauth_states','netatmo_accounts'];
    $rows = array_values(array_filter(array_map('strval',$rows)));
    usort($rows, static function(string $a,string $b) use($priority): int {
        $ia=array_search($a,$priority,true); $ib=array_search($b,$priority,true);
        $ia=$ia===false?999:$ia; $ib=$ib===false?999:$ib;
        return $ia<=>$ib ?: strcmp($a,$b);
    });
    return $rows;
}
function install_copy_table(PDO $source, PDO $target, string $table): array {
    if (preg_match('/^[A-Za-z0-9_]+$/',$table)!==1) install_fail('INSTALL_TABLE_NAME_INVALID');
    $sourceColumns = array_map(static fn(array $r): string => (string)$r['name'], $source->query("PRAGMA table_info(`{$table}`)")->fetchAll());
    $targetColumns = array_map(static fn(array $r): string => (string)$r['Field'], $target->query("SHOW COLUMNS FROM `{$table}`")->fetchAll());
    $columns = array_values(array_intersect($sourceColumns,$targetColumns));
    if (!$columns) return ['source'=>0,'target'=>0];
    $quoted = implode(',',array_map(static fn(string $c): string=>'`'.$c.'`',$columns));
    $params = implode(',',array_map(static fn(string $c): string=>':'.$c,$columns));
    $updates = implode(',',array_map(static fn(string $c): string=>'`'.$c.'`=VALUES(`'.$c.'`)',$columns));
    $statement = $target->prepare("INSERT INTO `{$table}` ({$quoted}) VALUES ({$params}) ON DUPLICATE KEY UPDATE {$updates}");
    $select = $source->query("SELECT {$quoted} FROM `{$table}`");
    $count = 0;
    while ($row=$select->fetch(PDO::FETCH_ASSOC)) {
        $args=[]; foreach($columns as $column) $args[':'.$column]=$row[$column] ?? null;
        $statement->execute($args); $count++;
    }
    $targetCount=(int)$target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    return ['source'=>$count,'target'=>$targetCount];
}
function install_configure_secrets(PDO $target, string $secret, array $input): void {
    $config=['auth'=>['app_secret'=>$secret]];
    $smtpPass=(string)($input['smtp_password'] ?? '');
    $smtpHost=trim((string)($input['smtp_host'] ?? ''));
    $smtpUser=trim((string)($input['smtp_username'] ?? ''));
    $smtpFrom=trim((string)($input['smtp_from_email'] ?? ''));
    $smtpIntent=($smtpPass!=='' || $smtpUser!=='' || $smtpFrom!=='');
    if ($smtpIntent) {
        if ($smtpPass==='' || $smtpHost==='' || $smtpUser==='' || filter_var($smtpFrom,FILTER_VALIDATE_EMAIL)===false) install_fail('INSTALL_SMTP_CONFIG_INVALID', 'install.error.smtp');
        $st=$target->prepare("INSERT INTO smtp_settings(id,host,port,encryption,username,password_encrypted,from_email,from_name,timeout_seconds,updated_at) VALUES(1,:host,:port,:enc,:user,:pass,:from,:name,:timeout,:now) ON DUPLICATE KEY UPDATE host=VALUES(host),port=VALUES(port),encryption=VALUES(encryption),username=VALUES(username),password_encrypted=VALUES(password_encrypted),from_email=VALUES(from_email),from_name=VALUES(from_name),timeout_seconds=VALUES(timeout_seconds),updated_at=VALUES(updated_at)");
        $st->execute([':host'=>$smtpHost,':port'=>max(1,min(65535,(int)($input['smtp_port']??587))),':enc'=>in_array(($input['smtp_encryption']??'tls'),['ssl','tls'],true)?$input['smtp_encryption']:'tls',':user'=>$smtpUser,':pass'=>meteonexa_encrypt_value($smtpPass,$config,'smtp'),':from'=>$smtpFrom,':name'=>trim((string)($input['smtp_from_name']??'MeteoNexa'))?:'MeteoNexa',':timeout'=>18,':now'=>gmdate('c')]);
    }
    $qaAdmin=trim((string)($input['qa_admin_email'] ?? ''));
    if ($qaAdmin!=='') {
        $qaAdmin=strtolower($qaAdmin);
        if (filter_var($qaAdmin,FILTER_VALIDATE_EMAIL)===false) install_fail('INSTALL_QA_ADMIN_EMAIL_INVALID', 'install.error.admin_email');
        $qaHash=meteonexa_hmac_identifier('qa-admin-email:'.$qaAdmin,$secret);
        $meta=$target->prepare("INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES('qa_admin_email_hashes',:value,:now) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)");
        $meta->execute([':value'=>$qaHash,':now'=>gmdate('c')]);
    }
    $aiKey=trim((string)($input['ai_key'] ?? ''));
    if ($aiKey!=='') {
        $provider=in_array(($input['ai_provider']??'openrouter'),['openrouter','groq'],true)?$input['ai_provider']:'openrouter';
        $model=$provider==='openrouter' ? 'nvidia/nemotron-3-super-120b-a12b:free' : (trim((string)($input['ai_model']??'')) ?: 'openai/gpt-oss-20b');
        $st=$target->prepare("INSERT INTO ai_settings(id,provider,model,api_key_encrypted,site_url,site_name,updated_at) VALUES(1,:provider,:model,:key,:url,'MeteoNexa',:now) ON DUPLICATE KEY UPDATE provider=VALUES(provider),model=VALUES(model),api_key_encrypted=VALUES(api_key_encrypted),site_url=VALUES(site_url),updated_at=VALUES(updated_at)");
        $st->execute([':provider'=>$provider,':model'=>$model,':key'=>meteonexa_encrypt_value($aiKey,$config,'ai-provider'),':url'=>trim((string)($input['ai_site_url']??'')),':now'=>gmdate('c')]);
    }
}


function install_language(): string {
    $allowed=['it','en','fr','es','de'];
    $requested=strtolower(trim((string)($_GET['lang'] ?? '')));
    if(in_array($requested,$allowed,true)) {
        setcookie('meteonexa_language',$requested,[
            'expires'=>time()+31536000,'path'=>'/','secure'=>(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off'),'httponly'=>false,'samesite'=>'Strict'
        ]);
        return $requested;
    }
    $cookie=strtolower(trim((string)($_COOKIE['meteonexa_language'] ?? '')));
    if(in_array($cookie,$allowed,true)) return $cookie;
    return meteonexa_backend_language($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'it');
}
function install_fallback_catalog(string $language): array {
    static $cache=[];
    if(isset($cache[$language])) return $cache[$language];
    $path=dirname(__DIR__).'/api/install/installer-translations.json';
    $raw=is_file($path)?@file_get_contents($path):false;
    $json=is_string($raw)?json_decode($raw,true):null;
    $rows=is_array($json)&&is_array($json['translations'][$language]??null)?$json['translations'][$language]:[];
    return $cache[$language]=$rows;
}
function install_t(string $key,array $params=[]): string {
    $language=$GLOBALS['installLanguage'] ?? 'it';
    $value=meteonexa_backend_text($key,$params,$language);
    if($value===$key) {
        $catalog=install_fallback_catalog($language);
        $value=(string)($catalog[$key] ?? '');
        if($value==='') $value=(string)(install_fallback_catalog('it')[$key] ?? $key);
        foreach($params as $name=>$replacement) $value=str_replace(['{'.$name.'}','${'.$name.'}'],(string)$replacement,$value);
    }
    return $value;
}
function install_error_key(Throwable $error): string {
    return $error instanceof MeteoNexaInstallerException
        ? $error->translationKey
        : 'install.error.generic';
}
$installLanguage=install_language();
$GLOBALS['installLanguage']=$installLanguage;

$messages=[]; $errorKey=''; $success=false; $counts=[];
$requirements=[
    'PHP >= 8.1'=>version_compare(PHP_VERSION,'8.1.0','>='),
    'PDO'=>extension_loaded('pdo'),
    'pdo_sqlite'=>extension_loaded('pdo_sqlite'),
    'pdo_mysql'=>extension_loaded('pdo_mysql'),
    'OpenSSL'=>extension_loaded('openssl'),
    'Phar'=>class_exists('PharData'),
    'zlib'=>extension_loaded('zlib'),
];
$currentDriver='sqlite';
try { $currentDriver=meteonexa_database_driver(); } catch(Throwable $ignored) { }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        install_assert_same_origin();
        if (!hash_equals($installKey,trim((string)($_POST['install_key']??'')))) install_fail('INSTALL_KEY_INVALID', 'install.error.key');
        foreach($requirements as $name=>$ok) if(!$ok) install_fail('INSTALL_REQUIREMENT_MISSING', 'install.error.requirement', ['requirement' => $name]);
        $sourcePath=meteonexa_runtime_sqlite_path();
        $source=new PDO('sqlite:'.$sourcePath,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $source->exec('PRAGMA query_only=ON');
        $integrity=(string)$source->query('PRAGMA integrity_check')->fetchColumn();
        if(strtolower($integrity)!=='ok') install_fail('INSTALL_SQLITE_INTEGRITY_FAILED');
        $secret=install_storage_secret($sourcePath);
        $target=install_mysql($_POST);
        install_exec_sql_file($target,$root.'/api/install/mysql-schema.sql');
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        $target->beginTransaction();
        try {
            foreach(install_source_tables($source) as $table) {
                try { $counts[$table]=install_copy_table($source,$target,$table); }
                catch(PDOException $tableError) {
                    if (str_contains(strtolower($tableError->getMessage()),"doesn't exist")) continue;
                    throw $tableError;
                }
            }
            install_configure_secrets($target,$secret,$_POST);
            $verifier=hash_hmac('sha256','meteonexa-deployment-secret-v1',$secret);
            $meta=$target->prepare("INSERT INTO app_metadata(meta_key,meta_value,updated_at) VALUES(:key,:value,:now) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)");
            foreach(['schema_version'=>'27','app_version'=>'20.1','translation_seed_version'=>'20.1-semantic-i18n-v1','database_driver'=>'mysql','secret_verifier'=>$verifier] as $key=>$value) $meta->execute([':key'=>$key,':value'=>$value,':now'=>gmdate('c')]);
            $target->exec("DELETE FROM app_metadata WHERE meta_key='deployment_unbound'");
            $target->commit();
        } catch(Throwable $copyError) { if($target->inTransaction())$target->rollBack(); throw $copyError; }
        $target->exec('SET FOREIGN_KEY_CHECKS=1');
        try { install_exec_sql_file($target,$root.'/api/install/mysql-triggers.sql'); }
        catch(Throwable $triggerError) { $messages[]='install.message.trigger_warning'; }
        foreach($counts as $table=>$c) if(($c['target']??0)<($c['source']??0)) install_fail('INSTALL_ROW_COUNT_MISMATCH');
        meteonexa_write_database_settings($_POST);
        $storage=meteonexa_storage_path();
        @file_put_contents($storage.'/install.lock',gmdate('c')." mysql\n",LOCK_EX); @chmod($storage.'/install.lock',0600);
        @unlink($enableFile);
        $success=true;
    } catch(Throwable $e) {
        $errorKey=install_error_key($e);
        error_log('[MeteoNexa installer] '.get_class($e).': '.$e->getMessage());
    }
}
$languages=['it'=>'Italiano','en'=>'English','fr'=>'Français','es'=>'Español','de'=>'Deutsch'];
?><!doctype html>
<html lang="<?= install_h($installLanguage) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="theme-color" content="#071a36">
<title><?= install_h(install_t('install.page.title')) ?> · MeteoNexa</title>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/icons/favicon-32.png">
<link rel="apple-touch-icon" href="../assets/icons/icon-192.png">
<link rel="stylesheet" href="../dist/install/installer.ca06a0c28423.css">
</head>
<body class="install-page" data-msg-required="<?= install_h(install_t('install.validation.required')) ?>" data-msg-email="<?= install_h(install_t('install.validation.email')) ?>" data-msg-smtp="<?= install_h(install_t('install.validation.smtp')) ?>">
<div class="install-bg" aria-hidden="true"></div>
<header class="install-topbar">
  <a class="install-brand" href="../" aria-label="MeteoNexa">
    <img src="../assets/icons/icon-192.png" alt="" width="44" height="44">
    <span><strong>MeteoNexa</strong><small>20.1 · schema 26</small></span>
  </a>
  <div class="install-language" data-custom-select>
    <span class="install-language-label"><?= install_h(install_t('install.language.label')) ?></span>
    <button class="install-language-button" type="button" aria-haspopup="listbox" aria-expanded="false" data-select-button>
      <span><?= install_h($languages[$installLanguage] ?? 'Italiano') ?></span><i aria-hidden="true"></i>
    </button>
    <div class="install-language-menu" role="listbox" hidden data-select-menu>
      <?php foreach($languages as $code=>$label): ?>
      <button type="button" role="option" aria-selected="<?= $code===$installLanguage?'true':'false' ?>" data-language="<?= install_h($code) ?>"><?= install_h($label) ?></button>
      <?php endforeach ?>
    </div>
  </div>
</header>
<main class="install-shell">
<section class="install-hero card">
  <div class="hero-copy"><span class="eyebrow"><?= install_h(install_t('install.kicker')) ?></span><h1><?= install_h(install_t('install.page.title')) ?></h1><p><?= install_h(install_t('install.subtitle')) ?></p></div>
  <div class="hero-state"><span class="state-dot"></span><strong><?= install_h(strtoupper($currentDriver)) ?></strong><small><?= install_h(install_t('install.requirements.driver')) ?></small></div>
</section>

<section class="card requirements-card">
  <div class="section-heading"><div><span class="eyebrow"><?= install_h(install_t('install.requirements.title')) ?></span><h2><?= install_h(install_t('install.requirements.title')) ?></h2></div><p><?= install_h(install_t('install.requirements.copy')) ?></p></div>
  <div class="requirements-grid">
  <?php foreach($requirements as $name=>$ok): ?><div class="requirement <?= $ok?'ok':'ko' ?>"><span class="requirement-icon" aria-hidden="true"><?= $ok?'✓':'×' ?></span><div><strong><?= install_h($name) ?></strong><small><?= install_h(install_t($ok?'install.status.ready':'install.status.missing')) ?></small></div></div><?php endforeach ?>
  <div class="requirement <?= (!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')?'ok':'ko' ?>"><span class="requirement-icon" aria-hidden="true">✓</span><div><strong>HTTPS</strong><small><?= install_h(install_t('install.requirements.https')) ?></small></div></div>
  </div>
</section>

<?php if($errorKey!==''): ?><section class="card install-alert error" role="alert"><span class="alert-icon" aria-hidden="true">!</span><div><strong><?= install_h(install_t('install.error.title')) ?></strong><p><?= install_h(install_t($errorKey)) ?></p></div></section><?php endif ?>
<?php foreach($messages as $messageKey): ?><section class="card install-alert warning"><span class="alert-icon" aria-hidden="true">!</span><div><p><?= install_h(install_t($messageKey)) ?></p></div></section><?php endforeach ?>

<?php if($success): ?>
<section class="card success-card">
  <div class="success-icon" aria-hidden="true">✓</div><span class="eyebrow"><?= install_h(install_t('install.success.title')) ?></span><h2><?= install_h(install_t('install.success.title')) ?></h2><p><?= install_h(install_t('install.success.copy')) ?></p><p class="muted"><?= install_h(install_t('install.success.verify')) ?></p>
  <?php if($counts): ?><div class="table-wrap"><h3><?= install_h(install_t('install.counts.title')) ?></h3><table><thead><tr><th><?= install_h(install_t('install.counts.table')) ?></th><th><?= install_h(install_t('install.counts.sqlite')) ?></th><th><?= install_h(install_t('install.counts.mysql')) ?></th></tr></thead><tbody><?php foreach($counts as $table=>$c): ?><tr><td><?= install_h($table) ?></td><td><?= (int)$c['source'] ?></td><td><?= (int)$c['target'] ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
  <div class="form-actions"><a class="install-button primary" href="../"><?= install_h(install_t('install.back')) ?></a></div>
</section>
<?php else: ?>
<form id="install-form" method="post" class="install-form" autocomplete="off" novalidate>
<section class="card form-section">
  <div class="section-heading"><div><span class="eyebrow">MYSQL</span><h2><?= install_h(install_t('install.mysql.title')) ?></h2></div><p><?= install_h(install_t('install.mysql.copy')) ?></p></div>
  <div class="field-grid">
    <label class="field full"><span><?= install_h(install_t('install.field.key')) ?></span><div class="input-shell"><input type="password" name="install_key" autocomplete="off" data-required><button type="button" class="password-toggle" data-password-toggle aria-label="<?= install_h(install_t('install.action.show_password')) ?>">◉</button></div></label>
    <label class="field"><span><?= install_h(install_t('install.field.host')) ?></span><input name="host" inputmode="url" placeholder="<?= install_h(install_t('install.placeholder.mysql_host')) ?>" data-required></label>
    <label class="field"><span><?= install_h(install_t('install.field.port')) ?></span><input name="port" inputmode="numeric" value="3306" data-required></label>
    <label class="field"><span><?= install_h(install_t('install.field.database')) ?></span><input name="database" data-required></label>
    <label class="field"><span><?= install_h(install_t('install.field.username')) ?></span><input name="username" autocomplete="username" data-required></label>
    <label class="field"><span><?= install_h(install_t('install.field.password')) ?></span><div class="input-shell"><input type="password" name="password" autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle aria-label="<?= install_h(install_t('install.action.show_password')) ?>">◉</button></div></label>
    <label class="field full"><span><?= install_h(install_t('install.field.qa_admin')) ?></span><input name="qa_admin_email" inputmode="email" autocomplete="email" placeholder="<?= install_h(install_t('install.placeholder.qa_email')) ?>"><small><?= install_h(install_t('install.field.qa_admin.help')) ?></small></label>
  </div>
</section>
<section class="card form-section">
  <div class="section-heading"><div><span class="eyebrow">SMTP</span><h2><?= install_h(install_t('install.smtp.title')) ?></h2></div><p><?= install_h(install_t('install.smtp.copy')) ?></p></div>
  <div class="field-grid">
    <label class="field"><span><?= install_h(install_t('install.field.smtp_host')) ?></span><input name="smtp_host" placeholder="smtp.provider.tld"></label>
    <label class="field"><span><?= install_h(install_t('install.field.smtp_port')) ?></span><input name="smtp_port" inputmode="numeric" value="587"></label>
    <div class="field"><span><?= install_h(install_t('install.field.smtp_encryption')) ?></span><div class="custom-choice" data-choice><input type="hidden" name="smtp_encryption" value="tls"><button type="button" class="choice-button" aria-haspopup="listbox" aria-expanded="false" data-choice-button><span data-choice-label><?= install_h(install_t('install.option.starttls')) ?></span><i></i></button><div class="choice-menu" role="listbox" hidden data-choice-menu><button type="button" role="option" data-value="ssl" data-label="<?= install_h(install_t('install.option.ssl')) ?>" aria-selected="false"><?= install_h(install_t('install.option.ssl')) ?></button><button type="button" role="option" data-value="tls" data-label="<?= install_h(install_t('install.option.starttls')) ?>" aria-selected="true"><?= install_h(install_t('install.option.starttls')) ?></button></div></div></div>
    <label class="field"><span><?= install_h(install_t('install.field.smtp_username')) ?></span><input name="smtp_username" autocomplete="username"></label>
    <label class="field"><span><?= install_h(install_t('install.field.smtp_from_email')) ?></span><input name="smtp_from_email" inputmode="email" value="alerts@meteonexa.com"></label>
    <label class="field"><span><?= install_h(install_t('install.field.smtp_from_name')) ?></span><input name="smtp_from_name" value="MeteoNexa"></label>
    <label class="field full"><span><?= install_h(install_t('install.field.smtp_password')) ?></span><div class="input-shell"><input type="password" name="smtp_password" autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle aria-label="<?= install_h(install_t('install.action.show_password')) ?>">◉</button></div></label>
  </div>
</section>
<section class="card form-section">
  <div class="section-heading"><div><span class="eyebrow">AI</span><h2><?= install_h(install_t('install.ai.title')) ?></h2></div><p><?= install_h(install_t('install.ai.copy')) ?></p></div>
  <div class="field-grid">
    <div class="field"><span><?= install_h(install_t('install.field.ai_provider')) ?></span><div class="custom-choice" data-choice><input type="hidden" name="ai_provider" value="openrouter"><button type="button" class="choice-button" aria-haspopup="listbox" aria-expanded="false" data-choice-button><span data-choice-label><?= install_h(install_t('install.option.openrouter')) ?></span><i></i></button><div class="choice-menu" role="listbox" hidden data-choice-menu><button type="button" role="option" data-value="openrouter" data-label="<?= install_h(install_t('install.option.openrouter')) ?>" aria-selected="true"><?= install_h(install_t('install.option.openrouter')) ?></button><button type="button" role="option" data-value="groq" data-label="<?= install_h(install_t('install.option.groq')) ?>"><?= install_h(install_t('install.option.groq')) ?></button></div></div></div>
    <label class="field"><span><?= install_h(install_t('install.field.ai_model')) ?></span><input name="ai_model" placeholder="nvidia/nemotron-3-super-120b-a12b:free"></label>
    <label class="field full"><span><?= install_h(install_t('install.field.ai_key')) ?></span><div class="input-shell"><input type="password" name="ai_key" autocomplete="new-password"><button type="button" class="password-toggle" data-password-toggle aria-label="<?= install_h(install_t('install.action.show_password')) ?>">◉</button></div></label>
    <label class="field full"><span><?= install_h(install_t('install.field.ai_site_url')) ?></span><input name="ai_site_url" inputmode="url" placeholder="<?= install_h(install_t('install.placeholder.site_url')) ?>"></label>
  </div>
</section>
<section class="card security-card"><div class="security-icon" aria-hidden="true">✓</div><div><h2><?= install_h(install_t('install.security.title')) ?></h2><p><?= install_h(install_t('install.security.copy')) ?></p></div></section>
<div class="form-actions"><a class="install-button secondary" href="../"><?= install_h(install_t('install.back')) ?></a><button class="install-button primary" type="submit"><span><?= install_h(install_t('install.action.start')) ?></span></button></div>
</form>
<?php endif ?>
<footer class="install-footer"><?= install_h(install_t('install.footer.security')) ?></footer>
</main>
<div class="install-toast" id="install-toast" role="status" aria-live="polite" hidden></div>
<div class="install-loader" id="install-loader" aria-hidden="true"><div class="loader-card"><span class="spinner"></span><strong><?= install_h(install_t('install.loading.title')) ?></strong><p><?= install_h(install_t('install.loading.copy')) ?></p></div></div>
<script src="../dist/install/installer.727985a7d748.js" defer></script>
</body></html>
