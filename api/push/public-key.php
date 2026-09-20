<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/web_push.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_method('GET');
try { $keys = meteonexa_vapid_keys(); respond(['ok'=>true,'publicKey'=>$keys['public_key']]); }
catch (Throwable $error) { respond(['ok'=>false,'code'=>'VAPID_UNAVAILABLE','message'=>meteonexa_backend_text('api.backend.vapid_unavailable')],503); }
