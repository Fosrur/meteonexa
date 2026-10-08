<?php
declare(strict_types=1);


function meteonexa_ai_v2_tool_contracts(): array
{
    return [
        'current_forecast'=>['type'=>'forecast','version'=>'2.0','freshnessClass'=>'forecast'],
        'model_consensus'=>['type'=>'forecast-consensus','version'=>'1.0','freshnessClass'=>'forecast'],
        'probabilistic_ensemble'=>['type'=>'forecast-ensemble','version'=>'1.0','freshnessClass'=>'forecast'],
        'nowcast'=>['type'=>'nowcast','version'=>'2.0','freshnessClass'=>'nowcast'],
        'convective_risk'=>['type'=>'nowcast-convective','version'=>'1.0','freshnessClass'=>'nowcast'],
        'official_warnings'=>['type'=>'official-warning','version'=>'1.0','freshnessClass'=>'warning'],
        'route'=>['type'=>'route-weather','version'=>'2.0','freshnessClass'=>'forecast'],
        'trust_score'=>['type'=>'model-skill','version'=>'1.0','freshnessClass'=>'verification'],
        'forecast_change'=>['type'=>'change-history','version'=>'1.0','freshnessClass'=>'forecast'],
        'confidence'=>['type'=>'weather-confidence','version'=>'1.0','freshnessClass'=>'forecast'],
        'decision'=>['type'=>'deterministic-decision','version'=>'2.0','freshnessClass'=>'forecast'],
    ];
}

function meteonexa_ai_v2_stable_value(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    $out=[];
    $drop=['generatedAt'=>true,'retrievedAt'=>true,'fetchedAt'=>true,'lastHitAt'=>true,'createdAt'=>true];
    if (array_is_list($value)) {
        foreach ($value as $item) $out[]=meteonexa_ai_v2_stable_value($item);
        return $out;
    }
    $keys=array_keys($value); sort($keys, SORT_STRING);
    foreach ($keys as $key) {
        if (isset($drop[(string)$key])) continue;
        $out[(string)$key]=meteonexa_ai_v2_stable_value($value[$key]);
    }
    return $out;
}

function meteonexa_ai_v2_decision_id(array $decision, array $toolRun=[]): string
{
    $stable=['decision'=>meteonexa_ai_v2_stable_value($decision),'tools'=>array_values((array)($toolRun['executed']??[]))];
    return substr(hash('sha256', json_encode($stable, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}'), 0, 32);
}

function meteonexa_ai_v2_contract(array $orchestration, string $language='it', string $mode='assistant'): array
{
    $contracts=meteonexa_ai_v2_tool_contracts();
    $typed=[];
    foreach ((array)($orchestration['tools']??[]) as $name=>$payload) {
        if (!isset($contracts[$name])) continue;
        $typed[$name]=[
            'contract'=>$contracts[$name],
            'payload'=>is_array($payload)?$payload:[],
        ];
    }
    $decision=is_array($orchestration['decision']??null)?$orchestration['decision']:[];
    $toolRun=is_array($orchestration['toolRun']??null)?$orchestration['toolRun']:[];
    $asOf=(string)($decision['generatedAt']??$toolRun['generatedAt']??gmdate('c'));
    $confidence=is_numeric($decision['confidence']??null)?max(0,min(100,(int)$decision['confidence'])):0;
    $sources=[];
    foreach (array_slice((array)($orchestration['sources']??[]),0,16) as $source) {
        if (!is_array($source)) continue;
        $id=trim((string)($source['id']??''));
        if ($id==='') continue;
        $sources[]=[
            'id'=>substr($id,0,80),
            'type'=>substr((string)($source['type']??'weather'),0,48),
            'available'=>($source['available']??true)!==false,
            'asOf'=>$source['retrievedAt']??$source['generatedAt']??null,
        ];
    }
    $limitations=['llm-explanation-cannot-change-deterministic-decision','official-warnings-remain-separate-authority'];
    if (!$typed) $limitations[]='authoritative-tools-unavailable';
    if ($confidence<50) $limitations[]='low-weather-confidence';
    if (isset($typed['official_warnings']) && empty($typed['official_warnings']['payload']['available'])) $limitations[]='no-active-official-warning-in-tool-context';
    $decisionId=meteonexa_ai_v2_decision_id($decision,$toolRun);
    return [
        'contractVersion'=>'ai-meteorologist-2.0',
        'asOf'=>$asOf,
        'language'=>$language,
        'mode'=>$mode,
        'decisionId'=>$decisionId,
        'decision'=>$decision,
        'confidence'=>$confidence,
        'sources'=>$sources,
        'limitations'=>array_values(array_unique($limitations)),
        'tools'=>$typed,
        'policy'=>[
            'deterministicDecisionAuthoritative'=>true,
            'llmMayChangeSeverity'=>false,
            'llmMayInventNumbers'=>false,
            'memoryScope'=>'explicit-weather-preferences-only',
            'preciseCoordinatesStoredInAiCache'=>false,
        ],
    ];
}

function meteonexa_ai_v2_context_hash(array $contract, string $message): string
{
    $intent=function_exists('mb_strtolower')?mb_strtolower(trim($message),'UTF-8'):strtolower(trim($message));
    $intent=preg_replace('/\s+/u',' ',$intent)??$intent;
    
    
    $stable=[
        'intent'=>$intent,
        'language'=>$contract['language']??'it',
        'mode'=>$contract['mode']??'assistant',
        'decisionId'=>$contract['decisionId']??'',
        'tools'=>meteonexa_ai_v2_stable_value($contract['tools']??[]),
    ];
    return hash('sha256',json_encode($stable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');
}

function meteonexa_ai_v2_cache_key(array $contract,string $message): string
{
    return hash('sha256','ai-v2|'.meteonexa_ai_v2_context_hash($contract,$message));
}

function meteonexa_ai_v2_cache_get(PDO $pdo,array $contract,string $message): ?array
{
    if (!function_exists('meteonexa_db_table_exists')||!meteonexa_db_table_exists($pdo,'ai_semantic_cache')) return null;
    try {
        $key=meteonexa_ai_v2_cache_key($contract,$message);
        $st=$pdo->prepare('SELECT answer,sources_json,confidence,limitations_json,expires_at FROM ai_semantic_cache WHERE cache_key=:key AND decision_id=:decision AND language=:language AND mode=:mode LIMIT 1');
        $st->execute([':key'=>$key,':decision'=>$contract['decisionId']??'',':language'=>$contract['language']??'it',':mode'=>$contract['mode']??'assistant']);
        $row=$st->fetch();
        if (!is_array($row) || (strtotime((string)$row['expires_at'])?:0)<=time()) return null;
        $pdo->prepare('UPDATE ai_semantic_cache SET last_hit_at=:now,hit_count=hit_count+1 WHERE cache_key=:key')->execute([':now'=>gmdate('c'),':key'=>$key]);
        return [
            'answer'=>(string)$row['answer'],
            'sources'=>json_decode((string)$row['sources_json'],true)?:[],
            'confidence'=>(int)$row['confidence'],
            'limitations'=>json_decode((string)$row['limitations_json'],true)?:[],
            'cacheKey'=>$key,
        ];
    } catch (Throwable $ignored) { return null; }
}

function meteonexa_ai_v2_cache_put(PDO $pdo,array $contract,string $message,string $answer,int $ttlSeconds=180): void
{
    if (!function_exists('meteonexa_db_table_exists')||!meteonexa_db_table_exists($pdo,'ai_semantic_cache')) return;
    try {
        $ttl=max(30,min(600,$ttlSeconds));
        $key=meteonexa_ai_v2_cache_key($contract,$message);
        $params=[
            ':key'=>$key,':language'=>(string)($contract['language']??'it'),':mode'=>(string)($contract['mode']??'assistant'),
            ':decision'=>(string)($contract['decisionId']??''),':context'=>meteonexa_ai_v2_context_hash($contract,$message),':answer'=>$answer,
            ':sources'=>json_encode($contract['sources']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'[]',
            ':confidence'=>(int)($contract['confidence']??0),':limitations'=>json_encode($contract['limitations']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'[]',
            ':expires'=>gmdate('c',time()+$ttl),':created'=>gmdate('c'),
        ];
        if (meteonexa_pdo_driver($pdo)==='mysql') {
            $sql='INSERT INTO ai_semantic_cache(cache_key,language,mode,decision_id,context_hash,answer,sources_json,confidence,limitations_json,expires_at,created_at,last_hit_at,hit_count) VALUES(:key,:language,:mode,:decision,:context,:answer,:sources,:confidence,:limitations,:expires,:created,\'\',0) ON DUPLICATE KEY UPDATE decision_id=VALUES(decision_id),context_hash=VALUES(context_hash),answer=VALUES(answer),sources_json=VALUES(sources_json),confidence=VALUES(confidence),limitations_json=VALUES(limitations_json),expires_at=VALUES(expires_at),created_at=VALUES(created_at)';
        } else {
            $sql='INSERT INTO ai_semantic_cache(cache_key,language,mode,decision_id,context_hash,answer,sources_json,confidence,limitations_json,expires_at,created_at,last_hit_at,hit_count) VALUES(:key,:language,:mode,:decision,:context,:answer,:sources,:confidence,:limitations,:expires,:created,\'\',0) ON CONFLICT(cache_key) DO UPDATE SET decision_id=excluded.decision_id,context_hash=excluded.context_hash,answer=excluded.answer,sources_json=excluded.sources_json,confidence=excluded.confidence,limitations_json=excluded.limitations_json,expires_at=excluded.expires_at,created_at=excluded.created_at';
        }
        $pdo->prepare($sql)->execute($params);
        $pdo->prepare('DELETE FROM ai_semantic_cache WHERE expires_at<:cut')->execute([':cut'=>gmdate('c',time()-3600)]);
    } catch (Throwable $ignored) {}
}

function meteonexa_ai_v2_numeric_facts(array $contract): array
{
    $values=[];
    $walk=static function(mixed $value) use (&$walk,&$values): void {
        if (is_numeric($value)) {
            $n=(float)$value;
            foreach ([0,1,2] as $precision) $values[number_format($n,$precision,'.','')]=true;
            return;
        }
        if (is_string($value)) {
            preg_match_all('/-?\d+(?:[\.,]\d+)?/u',$value,$parts);
            foreach ($parts[0]??[] as $raw) if (is_numeric(str_replace(',','.',(string)$raw))) {
                $n=(float)str_replace(',','.',(string)$raw);
                foreach ([0,1,2] as $precision) $values[number_format($n,$precision,'.','')]=true;
            }
            return;
        }
        if (is_array($value)) foreach ($value as $item) $walk($item);
    };
    $walk($contract['tools']??[]); $walk($contract['decision']??[]); $walk($contract['confidence']??0);
    return $values;
}

function meteonexa_ai_v2_grounding_check(string $answer,array $contract): array
{
    $answer=trim($answer);
    if ($answer==='') return ['ok'=>false,'reason'=>'empty'];
    $allowed=meteonexa_ai_v2_numeric_facts($contract);
    preg_match_all('/(?<![\p{L}\p{N}])-?\d+(?:[\.,]\d+)?/u',$answer,$matches);
    foreach ($matches[0]??[] as $raw) {
        $token=str_replace(',','.',(string)$raw);
        if (!is_numeric($token)) continue;
        $number=(float)$token; $matched=false;
        foreach ([0,1,2] as $precision) if (isset($allowed[number_format($number,$precision,'.','')])) {$matched=true;break;}
        if (!$matched) return ['ok'=>false,'reason'=>'numeric_claim_not_grounded','claim'=>$raw];
    }
    $status=strtolower((string)($contract['decision']['status']??''));
    $low=function_exists('mb_strtolower')?mb_strtolower($answer,'UTF-8'):strtolower($answer);
    $contradictions=[
        'avoid'=>['nessun rischio','senza rischi','safe to proceed','no risk','sin riesgo','sans risque','kein risiko'],
        'good'=>['da evitare','avoid the activity','evita la actividad','à éviter','vermeiden'],
    ];
    foreach ($contradictions[$status]??[] as $needle) if (str_contains($low,$needle)) return ['ok'=>false,'reason'=>'decision_severity_conflict'];
    return ['ok'=>true,'reason'=>'grounded'];
}

function meteonexa_ai_v2_fallback(array $contract,string $language='it'): string
{
    $decision=(array)($contract['decision']??[]);
    $status=(string)($decision['status']??'learning');
    $confidence=(int)($contract['confidence']??0);
    $risk=(string)($decision['dominantRisk']??'none');
    $eta=$decision['nowcast']['etaMinutes']??null;
    $dict=[
        'it'=>['head'=>'Valutazione','confidence'=>'confidenza','risk'=>'rischio principale','eta'=>'arrivo stimato','minutes'=>'minuti','limit'=>'Spiegazione deterministica: i warning ufficiali restano separati.'],
        'en'=>['head'=>'Assessment','confidence'=>'confidence','risk'=>'main risk','eta'=>'estimated arrival','minutes'=>'minutes','limit'=>'Deterministic fallback: official warnings remain separate.'],
        'es'=>['head'=>'Evaluación','confidence'=>'confianza','risk'=>'riesgo principal','eta'=>'llegada estimada','minutes'=>'minutos','limit'=>'Respuesta determinista: los avisos oficiales permanecen separados.'],
        'fr'=>['head'=>'Évaluation','confidence'=>'confiance','risk'=>'risque principal','eta'=>'arrivée estimée','minutes'=>'minutes','limit'=>'Réponse déterministe : les alertes officielles restent séparées.'],
        'de'=>['head'=>'Bewertung','confidence'=>'Vertrauen','risk'=>'Hauptrisiko','eta'=>'geschätzte Ankunft','minutes'=>'Minuten','limit'=>'Deterministische Antwort: amtliche Warnungen bleiben getrennt.'],
    ];
    $d=$dict[$language]??$dict['it'];
    $parts=["{$d['head']}: {$status}; {$d['confidence']} {$confidence}%"];
    if ($risk!==''&&$risk!=='none') $parts[]="{$d['risk']}: {$risk}";
    if (is_numeric($eta)) $parts[]="{$d['eta']}: ".(int)$eta." {$d['minutes']}";
    $parts[]=$d['limit'];
    return implode('. ',$parts);
}
