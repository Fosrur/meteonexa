<?php
declare(strict_types=1);

require_once __DIR__ . '/chat/component-01.php';
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/http_helpers.php';
require_once dirname(__DIR__) . '/public_helpers.php';
require_once __DIR__ . '/orchestrator.php';
require_once __DIR__ . '/meteorologist_v2.php';
require_once dirname(__DIR__) . '/intelligence/verification_helpers.php';



























assert_same_origin();
$input = input_json();
$config = load_config();
$pdo = meteonexa_db($config);
$device = clean_device_id($_SERVER['HTTP_X_METEONEXA_DEVICE_ID'] ?? '');
$session = require_authenticated_device_session($pdo, $config, $device);

$ai = (array)($config['ai'] ?? []);
$provider = meteonexa_ai_provider($ai);
$language = meteonexa_backend_language($input['language'] ?? null);
$requestedMode = strtolower(trim((string)($input['mode'] ?? 'assistant')));
$mode = in_array($requestedMode, ['assistant','briefing','proactive'], true) ? $requestedMode : 'assistant';

$messageRaw = trim((string)($input['message'] ?? ''));
if ($messageRaw === '' || meteonexa_text_length($messageRaw) > 1800) {
    respond(['ok' => false, 'code' => 'AI_MESSAGE_INVALID', 'message' => 'api.ai.message_invalid'], 422);
}
$message = meteonexa_ai_text($messageRaw, 1800);

$secret = auth_secret($config);
$limit = max(5, min(120, (int)($ai['max_requests_per_hour'] ?? 40)));
$deviceRate = meteonexa_rate_limit($pdo, 'ai_device', $device, $secret, $limit, 3600);
$ipRate = meteonexa_rate_limit($pdo, 'ai_ip', client_ip(), $secret, max($limit, 60), 3600);
$globalLimit = max(50, min(10000, (int)($ai['max_requests_global_hour'] ?? 600)));
$globalRate = meteonexa_rate_limit($pdo, 'ai_global', 'deployment', $secret, $globalLimit, 3600);
if (!$deviceRate['allowed'] || !$ipRate['allowed'] || !$globalRate['allowed']) {
    $retryAfter = max((int)$deviceRate['retryAfter'], (int)$ipRate['retryAfter'], (int)$globalRate['retryAfter']);
    header('Retry-After: ' . max(1, $retryAfter));
    respond(['ok' => false, 'code' => 'AI_RATE_LIMIT', 'message' => 'api.ai.rate_limit', 'retryAfter'=>max(1,$retryAfter)], 429);
}

if ($mode === 'proactive') {
    $proactiveRate = meteonexa_rate_limit($pdo, 'ai_proactive_device_day', $device, $secret, 8, 86400);
    if (!$proactiveRate['allowed']) {
        header('Retry-After: ' . max(1, (int)$proactiveRate['retryAfter']));
        respond(['ok'=>false,'code'=>'AI_RATE_LIMIT','message'=>'api.ai.rate_limit','retryAfter'=>max(1,(int)$proactiveRate['retryAfter'])],429);
    }
}

if ($provider['key'] !== '' && $provider['name'] === 'openrouter' && substr((string)$provider['model'], -5) === ':free') {
    $freeDayLimit = max(1, min(50, (int)($ai['max_requests_global_day_free'] ?? 45)));
    $freeDayRate = meteonexa_rate_limit($pdo, 'ai_free_global_day', 'deployment', $secret, $freeDayLimit, 86400);
    if (!$freeDayRate['allowed']) {
        header('Retry-After: ' . max(1, (int)$freeDayRate['retryAfter']));
        respond(['ok' => false, 'code' => 'AI_RATE_LIMIT', 'message' => 'api.ai.rate_limit', 'retryAfter'=>max(1,(int)$freeDayRate['retryAfter'])], 429);
    }
}

$browserContext = meteonexa_ai_sanitize_context(is_array($input['context'] ?? null) ? $input['context'] : []);
$toolRun=['toolRun'=>['selected'=>[],'executed'=>[],'serverGenerated'=>false,'deterministicDecision'=>false,'generatedAt'=>gmdate('c')],'tools'=>[],'decision'=>[],'sources'=>[]];
$lat=is_numeric($input['latitude']??null)?(float)$input['latitude']:999.0;$lon=is_numeric($input['longitude']??null)?(float)$input['longitude']:999.0;$locationName=clean_text($input['locationName']??'',120,'');
$routePlan=is_array($input['routePlan']??null)?$input['routePlan']:[];
if(abs($lat)<=90&&abs($lon)<=180){try{$toolRun=meteonexa_copilot_orchestrate($pdo,$config,$device,$session,$message,$lat,$lon,$locationName,$routePlan);}catch(Throwable $ignored){}}
$hasAuthoritativeTools = !empty($toolRun['tools']);
$aiContract = meteonexa_ai_v2_contract($toolRun, $language, $mode);
$contractMeta = [
    'contractVersion'=>$aiContract['contractVersion'],
    'asOf'=>$aiContract['asOf'],
    'decisionId'=>$aiContract['decisionId'],
    'confidence'=>$aiContract['confidence'],
    'sources'=>$aiContract['sources'],
    'limitations'=>$aiContract['limitations'],
    'policy'=>$aiContract['policy'],
    'toolContracts'=>array_map(static fn($row)=>$row['contract']??[], (array)$aiContract['tools']),
];
$toolRun['decision']['decisionId']=$aiContract['decisionId'];
$toolRun['toolRun']['contractVersion']=$aiContract['contractVersion'];
$toolRun['toolRun']['decisionId']=$aiContract['decisionId'];
$context=[
    'authoritativeTools'=>$toolRun['tools'],
    'deterministicDecision'=>$toolRun['decision'],
    'toolSources'=>$toolRun['sources'],
    'responseContract'=>$contractMeta,
    
    
    'browserFallback'=>$hasAuthoritativeTools ? [] : $browserContext,
    'toolRun'=>$toolRun['toolRun'],
];
$contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($contextJson) || strlen($contextJson) > 72000) {
    $contextJson = json_encode([
        'authoritativeTools'=>$toolRun['tools'],
        'deterministicDecision'=>$toolRun['decision'],
        'toolSources'=>array_slice($toolRun['sources'],0,12),
        'toolRun'=>$toolRun['toolRun'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
if (!is_string($contextJson) || strlen($contextJson) > 72000) {
    $contextJson = '{}';
}

$cacheTtl=max(30,min(600,(int)($ai['semantic_cache_ttl_seconds']??180)));
if ($hasAuthoritativeTools) {
    $cached=meteonexa_ai_v2_cache_get($pdo,$aiContract,$message);
    if (is_array($cached)) {
        meteonexa_record_runtime_metric($pdo,'ai','semantic-cache','hit',1,['mode'=>$mode,'decisionId'=>$aiContract['decisionId']]);
        meteonexa_record_runtime_metric($pdo,'slo','ai-explanation','ok',null,['provider'=>'cache','mode'=>$mode],0);
        respond([
            'ok'=>true,'answer'=>$cached['answer'],'model'=>'deterministic-context-cache','provider'=>'cache',
            'toolRun'=>$toolRun['toolRun'],'sources'=>$aiContract['sources'],'decision'=>$toolRun['decision'],
            'asOf'=>$aiContract['asOf'],'confidence'=>$aiContract['confidence'],'limitations'=>$aiContract['limitations'],
            'decisionId'=>$aiContract['decisionId'],'fallbackUsed'=>false,'cacheHit'=>true,'generatedAt'=>gmdate('c'),
        ]);
    }
}
if ($provider['key']==='' && $hasAuthoritativeTools) {
    $fallback=meteonexa_ai_v2_fallback($aiContract,$language);
    meteonexa_record_runtime_metric($pdo,'ai','deterministic-fallback','ok',null,['reason'=>'provider_not_configured','mode'=>$mode]);
    meteonexa_record_runtime_metric($pdo,'slo','ai-explanation','fallback',null,['provider'=>'deterministic','reason'=>'provider_not_configured'],0);
    respond([
        'ok'=>true,'answer'=>$fallback,'model'=>'deterministic-template-v2','provider'=>'deterministic',
        'toolRun'=>$toolRun['toolRun'],'sources'=>$aiContract['sources'],'decision'=>$toolRun['decision'],
        'asOf'=>$aiContract['asOf'],'confidence'=>$aiContract['confidence'],'limitations'=>$aiContract['limitations'],
        'decisionId'=>$aiContract['decisionId'],'fallbackUsed'=>true,'cacheHit'=>false,'generatedAt'=>gmdate('c'),
    ]);
}
if ($provider['key']==='') {
    respond(['ok'=>false,'code'=>'AI_NOT_CONFIGURED','message'=>'api.ai.not_configured'],503);
}

$answerLanguage = meteonexa_backend_text('language.' . $language, [], $language);
$systemParts = [
    meteonexa_backend_text('ai.system.identity', ['language' => $answerLanguage], $language),
    meteonexa_backend_text('ai.system.context_first', [], $language),
    meteonexa_backend_text('ai.system.general_scope', [], $language),
    meteonexa_backend_text('ai.system.conversation', [], $language),
    meteonexa_backend_text('ai.system.answer_exact', [], $language),
    meteonexa_backend_text('ai.system.no_repeat', [], $language),
    meteonexa_backend_text('ai.system.time_reasoning', [], $language),
    meteonexa_backend_text('ai.system.distinguish', [], $language),
    meteonexa_backend_text('ai.system.style', [], $language),
    meteonexa_backend_text('ai.system.no_markdown', [], $language),
    meteonexa_backend_text('ai.system.emergencies', [], $language),
    meteonexa_backend_text('ai.system.no_fake_live', [], $language),
    meteonexa_backend_text('ai.system.intelligence_quality', [], $language),
    meteonexa_backend_text('ai.system.tool_orchestration', [], $language),
];
if ($mode === 'briefing') {
    $systemParts[] = meteonexa_backend_text('briefing.ai.system', [], $language);
} elseif ($mode === 'proactive') {
    $systemParts[] = meteonexa_backend_text('proactive.ai.system', [], $language);
}
$system = implode(' ', array_filter($systemParts, static fn($value): bool => trim((string)$value) !== ''))
    . ' The deterministic decision object is authoritative: never change its severity/status or invent numeric weather values. Every numeric claim must be present in the structured evidence.'
    . "\n\n" . meteonexa_backend_text('ai.system.context_heading', [], $language) . "\n" . $contextJson;

$messages = [['role' => 'system', 'content' => $system]];
$history = is_array($input['messages'] ?? null) ? array_slice($input['messages'], -14) : [];
$previousBriefing = '';
if ($mode === 'briefing') {
    foreach (array_reverse($history) as $row) {
        if (!is_array($row) || ($row['role'] ?? '') !== 'assistant') continue;
        $candidate = meteonexa_ai_text($row['content'] ?? '', 1800);
        if ($candidate !== '') {
            $previousBriefing = $candidate;
            break;
        }
    }
}
foreach ($history as $row) {
    if (!is_array($row)) continue;
    $role = ($row['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim((string)($row['content'] ?? ''));
    if ($content === '') continue;
    $messages[] = ['role' => $role, 'content' => meteonexa_ai_text($content, 1800)];
}
$last = end($messages);
if (!is_array($last) || ($last['role'] ?? '') !== 'user' || trim((string)($last['content'] ?? '')) !== $message) {
    $messages[] = ['role' => 'user', 'content' => $message];
}

$baseUrl = trim((string)($provider['site_url'] ?? ''));
if ($baseUrl === '') $baseUrl = trim((string)($config['app']['base_url'] ?? ''));
$baseParts = parse_url($baseUrl);
$validBaseUrl = is_array($baseParts)
    && strtolower((string)($baseParts['scheme'] ?? '')) === 'https'
    && preg_match('/^[A-Za-z0-9.-]+$/', (string)($baseParts['host'] ?? '')) === 1
    && !isset($baseParts['user']) && !isset($baseParts['pass']);
if (!$validBaseUrl) $baseUrl = '';
$siteName = trim((string)($provider['site_name'] ?? 'MeteoNexa')) ?: 'MeteoNexa';
$siteName = preg_replace('/[^\p{L}\p{N} ._\-]/u', '', $siteName) ?: 'MeteoNexa';
if (function_exists('mb_substr')) $siteName = mb_substr($siteName, 0, 80, 'UTF-8');
else $siteName = substr($siteName, 0, 80);

$headers = [
    'Authorization: Bearer ' . $provider['key'],
    'Content-Type: application/json',
    'Accept: application/json',
];
if ($provider['name'] === 'openrouter') {
    if ($baseUrl !== '') $headers[] = 'HTTP-Referer: ' . $baseUrl;
    $headers[] = 'X-OpenRouter-Title: ' . $siteName;
}

$providerTimeout = max(5, min(60, (int)($ai['timeout_seconds'] ?? 30)));
$latencyBudgetMs=max(3000,min(60000,(int)($ai['max_latency_ms']??35000)));
$providerStarted=microtime(true);
$lastProviderError = null;
for ($attempt = 0; $attempt < 2; $attempt++) {
    if ((microtime(true)-$providerStarted)*1000 >= $latencyBudgetMs) { $lastProviderError='latency_budget'; break; }
    $attemptMessages = $messages;
    if ($attempt > 0) {
        $attemptMessages[] = [
            'role' => 'user',
            'content' => $mode === 'briefing'
                ? meteonexa_backend_text('briefing.ai.retry', [], $language)
                : ($mode === 'proactive'
                    ? meteonexa_backend_text('proactive.ai.retry', [], $language)
                    : meteonexa_backend_text('ai.system.final_answer_only', [], $language)),
        ];
    }
    $payload = json_encode([
        'model' => $provider['model'],
        'messages' => $attemptMessages,
        'temperature' => $mode === 'briefing' ? 0.52 : ($mode === 'proactive' ? 0.32 : 0.38),
        'max_tokens' => $mode === 'briefing' ? 720 : ($mode === 'proactive' ? 520 : 850),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        respond(['ok' => false, 'code' => 'AI_PROVIDER_ERROR', 'message' => 'api.ai.provider_error'], 502);
    }

    try {
        $response = meteonexa_http_request($provider['url'], [
            'method' => 'POST',
            'timeout' => $providerTimeout,
            'headers' => $headers,
            'body' => $payload,
            'max_bytes' => 1048576,
        ]);
        $data = json_decode((string)$response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            $lastProviderError = 'provider_status';
            continue;
        }
        $answer = meteonexa_ai_plain_output(meteonexa_ai_extract_answer($data['choices'][0]['message']['content'] ?? ''));
        $briefingTooShort = $mode === 'briefing' && meteonexa_text_length($answer) < 90;
        if ($answer === '' || $briefingTooShort || meteonexa_ai_answer_is_internal_marker($answer)) {
            $lastProviderError = 'invalid_answer';
            continue;
        }
        $grounding=meteonexa_ai_v2_grounding_check($answer,$aiContract);
        if (empty($grounding['ok'])) {
            $lastProviderError = 'grounding_guard_' . (string)($grounding['reason']??'failed');
            meteonexa_record_runtime_metric($pdo,'ai','grounding-guard','rejected',null,['reason'=>$grounding['reason']??'unknown','mode'=>$mode]);
            continue;
        }
        if ($mode === 'briefing' && $attempt === 0 && $previousBriefing !== ''
            && meteonexa_ai_briefing_similarity($previousBriefing, $answer) >= 0.68) {
            $lastProviderError = 'repetitive_answer';
            continue;
        }
        $answer=meteonexa_text_substr($answer,0,$mode==='briefing'?4500:6000);
        $elapsedMs=(int)round((microtime(true)-$providerStarted)*1000);
        meteonexa_record_runtime_metric($pdo,'ai',$provider['name'],'ok',$elapsedMs,['model'=>$provider['model'],'mode'=>$mode,'decisionId'=>$aiContract['decisionId']],$elapsedMs);
        meteonexa_record_runtime_metric($pdo,'slo','ai-explanation','ok',null,['provider'=>$provider['name'],'mode'=>$mode],$elapsedMs);
        if ($hasAuthoritativeTools) meteonexa_ai_v2_cache_put($pdo,$aiContract,$message,$answer,$cacheTtl);
        respond([
            'ok'=>true,'answer'=>$answer,
            'model'=>meteonexa_ai_text($data['model']??$provider['model'],120),'provider'=>$provider['name'],
            'toolRun'=>$toolRun['toolRun'],'sources'=>$aiContract['sources'],'decision'=>$toolRun['decision'],
            'asOf'=>$aiContract['asOf'],'confidence'=>$aiContract['confidence'],'limitations'=>$aiContract['limitations'],
            'decisionId'=>$aiContract['decisionId'],'fallbackUsed'=>false,'cacheHit'=>false,'latencyMs'=>$elapsedMs,'generatedAt'=>gmdate('c'),
        ]);
    } catch (Throwable $error) {
        $lastProviderError = 'transport';
        meteonexa_record_runtime_metric($pdo,'ai',$provider['name'],'error',null,['mode'=>$mode,'class'=>get_class($error)]);
        meteonexa_observability_event('ai',$provider['name'],'error',['mode'=>$mode,'class'=>get_class($error)]);
        if ($attempt === 0) continue;
    }
}

meteonexa_observability_event('ai',$provider['name'],$lastProviderError?:'unavailable',['mode'=>$mode]);
if ($hasAuthoritativeTools) {
    $fallback=meteonexa_ai_v2_fallback($aiContract,$language);
    meteonexa_record_runtime_metric($pdo,'ai','deterministic-fallback','ok',null,['reason'=>$lastProviderError?:'unavailable','mode'=>$mode]);
    meteonexa_record_runtime_metric($pdo,'slo','ai-explanation','fallback',null,['provider'=>'deterministic','reason'=>$lastProviderError?:'unavailable'],null);
    respond([
        'ok'=>true,'answer'=>$fallback,'model'=>'deterministic-template-v2','provider'=>'deterministic',
        'toolRun'=>$toolRun['toolRun'],'sources'=>$aiContract['sources'],'decision'=>$toolRun['decision'],
        'asOf'=>$aiContract['asOf'],'confidence'=>$aiContract['confidence'],'limitations'=>$aiContract['limitations'],
        'decisionId'=>$aiContract['decisionId'],'fallbackUsed'=>true,'cacheHit'=>false,'providerFailure'=>$lastProviderError?:'unavailable','generatedAt'=>gmdate('c'),
    ]);
}
if ($lastProviderError === 'invalid_answer') respond(['ok'=>false,'code'=>'AI_EMPTY_RESPONSE','message'=>'api.ai.empty_response'],502);
respond(['ok'=>false,'code'=>'AI_UNAVAILABLE','message'=>'api.ai.unavailable'],502);
