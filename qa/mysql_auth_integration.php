<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$temp=sys_get_temp_dir().'/meteonexa-mysql-auth-'.bin2hex(random_bytes(4));
@mkdir($temp,0700,true);
putenv('METEONEXA_STORAGE_PATH='.$temp);
$_SERVER['DOCUMENT_ROOT']=$root;
require $root.'/api/bootstrap.php';
require $root.'/api/database.php';
require $root.'/api/auth/login_challenge.php';
require $root.'/api/auth_session.php';

$settings=[
    'host'=>(string)(getenv('MYSQL_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('MYSQL_PORT') ?: 3306),
    'database'=>(string)(getenv('MYSQL_DATABASE') ?: 'meteonexa'),
    'username'=>(string)(getenv('MYSQL_USER') ?: 'meteonexa'),
    'password'=>(string)(getenv('MYSQL_PASSWORD') ?: 'meteonexa'),
];
if (!extension_loaded('pdo_mysql')) { fwrite(STDERR,"pdo_mysql missing\n"); exit(2); }
$pdo=new MeteoNexaMySqlPDO($settings);
foreach(['auth_login_challenges','auth_otp','rate_limits'] as $table) { try{$pdo->exec('DROP TABLE IF EXISTS '.$table);}catch(Throwable $e){} }
$pdo->exec("CREATE TABLE rate_limits(scope VARCHAR(80) NOT NULL,key_hash VARCHAR(64) NOT NULL,window_start BIGINT NOT NULL,request_count INT NOT NULL DEFAULT 0,updated_at VARCHAR(40) NOT NULL,PRIMARY KEY(scope,key_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE auth_otp(email_hash VARCHAR(128) PRIMARY KEY,language VARCHAR(8) NOT NULL,code_hash VARCHAR(255) NOT NULL,sent_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,attempts INT NOT NULL DEFAULT 0,ip_hash VARCHAR(128) NOT NULL,updated_at VARCHAR(40) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE auth_login_challenges(challenge_hash VARCHAR(128) PRIMARY KEY,email_hash VARCHAR(128) NOT NULL,device_id VARCHAR(191) NOT NULL,language VARCHAR(8) NOT NULL DEFAULT 'it',code_hash VARCHAR(255) NOT NULL,sent_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,attempts INT NOT NULL DEFAULT 0,ip_hash VARCHAR(128) NOT NULL,updated_at VARCHAR(40) NOT NULL,KEY idx_auth_login_challenges_email(email_hash,sent_at),KEY idx_auth_login_challenges_device(device_id,sent_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$secret=str_repeat('b',64); $config=['auth'=>['app_secret'=>$secret]];
$r1=meteonexa_rate_limit($pdo,'mysql-auth','id',$secret,2,60);
$r2=meteonexa_rate_limit($pdo,'mysql-auth','id',$secret,2,60);
$r3=meteonexa_rate_limit($pdo,'mysql-auth','id',$secret,2,60);
if(!$r1['allowed']||!$r2['allowed']||$r3['allowed']){fwrite(STDERR,"rate limiter failed\n");exit(1);}
$emailHash=hash_hmac('sha256','otp:mysql@example.invalid',$secret);
$issued=meteonexa_issue_login_challenge($pdo,$config,$emailHash,'device-mysql-123456789','it','123456',time(),time()+600,hash('sha256','ip'));
if(($issued['storage']??'')!=='db'){fwrite(STDERR,"DB challenge fallback unexpectedly used\n");exit(1);}
$wrong=meteonexa_consume_login_challenge($pdo,$config,(string)$issued['challengeId'],$emailHash,'device-mysql-123456789','000000',5,time());
if(($wrong['code']??'')!=='INVALID_CODE'){fwrite(STDERR,"wrong-code path failed\n");exit(1);}
$ok=meteonexa_consume_login_challenge($pdo,$config,(string)$issued['challengeId'],$emailHash,'device-mysql-123456789','123456',5,time());
if(($ok['ok']??false)!==true){fwrite(STDERR,"consume path failed\n");exit(1);}
meteonexa_legacy_otp_upsert($pdo,['email'=>$emailHash,'language'=>'it','code'=>password_hash('111111',PASSWORD_DEFAULT),'sent'=>100,'expires'=>700,'ip'=>hash('sha256','ip'),'updated'=>gmdate('c')]);
meteonexa_legacy_otp_upsert($pdo,['email'=>$emailHash,'language'=>'it','code'=>password_hash('222222',PASSWORD_DEFAULT),'sent'=>200,'expires'=>800,'ip'=>hash('sha256','ip'),'updated'=>gmdate('c')]);
$sent=(int)$pdo->query("SELECT sent_at FROM auth_otp WHERE email_hash=".$pdo->quote($emailHash))->fetchColumn();
if($sent!==200){fwrite(STDERR,"legacy portable upsert failed\n");exit(1);}
$scopeA=meteonexa_otp_device_scope_hash($config,'same@example.test','device-a-123456789',str_repeat('A',40));
$scopeB=meteonexa_otp_device_scope_hash($config,'same@example.test','device-b-123456789',str_repeat('B',40));
if($scopeA===$scopeB){fwrite(STDERR,"device OTP scopes collided\n");exit(1);}
meteonexa_legacy_otp_upsert($pdo,['email'=>$scopeA,'language'=>'it','code'=>password_hash('333333',PASSWORD_DEFAULT),'sent'=>300,'expires'=>900,'ip'=>hash('sha256','ip-a'),'updated'=>gmdate('c')]);
meteonexa_legacy_otp_upsert($pdo,['email'=>$scopeB,'language'=>'it','code'=>password_hash('444444',PASSWORD_DEFAULT),'sent'=>301,'expires'=>901,'ip'=>hash('sha256','ip-b'),'updated'=>gmdate('c')]);
$parallel=(int)$pdo->query("SELECT COUNT(*) FROM auth_otp WHERE email_hash IN (".$pdo->quote($scopeA).",".$pdo->quote($scopeB).")")->fetchColumn();
if($parallel!==2){fwrite(STDERR,"parallel device OTP rows failed\n");exit(1);}
echo "MySQL auth integration PASS\n";
