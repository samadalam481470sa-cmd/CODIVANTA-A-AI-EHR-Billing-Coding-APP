<?php
declare(strict_types=1);
namespace Codivanta;

/** Optional OpenAI-compatible model adapter. Disabled unless explicitly opted in.
 * The deployment must contain synthetic data ONLY; this is not a BAA-ready integration.
 * Model output never reaches claims without deterministic catalog checks + human review.
 */
final class AIModel
{
    public static function enabled(): bool
    {
        return getenv('CODIVANTA_ALLOW_REMOTE_AI') === '1'
            && getenv('CODIVANTA_DEMO_MODE') !== '0'
            && (bool)getenv('CODIVANTA_OPENAI_API_KEY');
    }

    public static function review(array $state,string $note,string $date,array $heuristic): array
    {
        if (!self::enabled()) return $heuristic;
        $candidates=array_map(static fn($x)=>['code'=>$x['code'],'description'=>$x['description']],$heuristic['suggestions']);
        if (!$candidates) return $heuristic;
        $model=getenv('CODIVANTA_OPENAI_MODEL') ?: 'gpt-4.1-mini';
        $prompt="You are a clinical coding research demo assistant. All inputs are synthetic. "
            ."Select only candidate ICD-10-CM diagnosis codes DIRECTLY supported by affirmative statements. "
            ."Never diagnose, infer undocumented conditions, use negated/suspected conditions, or choose new codes. "
            ."Return JSON object {\"suggestions\":[{\"code\":\"...\",\"evidence\":\"exact sentence copied from note\",\"reason\":\"...\"}]}. "
            ."If unsure, return empty suggestions. Only use code strings from the candidates.";
        $payload=['model'=>$model,'temperature'=>0,'response_format'=>['type'=>'json_object'],
            'messages'=>[['role'=>'system','content'=>$prompt],['role'=>'user','content'=>json_encode([
                'encounter_date'=>$date,'synthetic_note'=>mb_safe_substr($note,0,5000),'candidate_codes'=>$candidates
            ],JSON_THROW_ON_ERROR)]]];
        $key=(string)getenv('CODIVANTA_OPENAI_API_KEY');
        $context=stream_context_create(['http'=>[
            'method'=>'POST','header'=>"Content-Type: application/json\r\nAuthorization: Bearer ".$key."\r\n",
            'content'=>json_encode($payload,JSON_THROW_ON_ERROR),'timeout'=>12,'ignore_errors'=>true,
            'follow_location'=>0,'max_redirects'=>0],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        // Fixed trusted endpoint: no user-supplied URL; no redirects or arbitrary SSRF.
        $response=@file_get_contents('https://api.openai.com/v1/chat/completions',false,$context,0,750000);
        if ($response===false || strlen($response)>749999) return self::fallback($heuristic);
        try {
            $json=json_decode($response,true,32,JSON_THROW_ON_ERROR);
            $body=json_decode($json['choices'][0]['message']['content']??'',true,16,JSON_THROW_ON_ERROR);
            if (!is_array($body)) return self::fallback($heuristic);
            $validated=self::validate($body,$heuristic,$note);
            if (!$validated) return self::fallback($heuristic);
            return ['suggestions'=>$validated,'warnings'=>array_merge($heuristic['warnings'],[
                'Remote AI was used only to rank catalog-grounded candidates. Not a diagnosis or clinically certified coding engine.',
                'Model: '.$model.'; the provider received synthetic note text. Human review is required.'
            ])];
        } catch (\Throwable $e) { return self::fallback($heuristic); }
    }

    public static function validate(array $response,array $heuristic,string $note): array
    {
        $allowed=[];
        foreach ($heuristic['suggestions'] as $item) $allowed[$item['code']]=$item;
        $result=[];
        foreach (array_slice($response['suggestions']??[],0,12) as $item) {
            if (!is_array($item)) continue;
            $code=(string)($item['code']??'');
            $evidence=trim((string)($item['evidence']??''));
            if (!isset($allowed[$code]) || strlen($evidence)<8 || strlen($evidence)>350 || !str_contains($note,$evidence)) continue;
            if (preg_match('/\b(?:denies|rule out|ruled out|no evidence of|negative for|family history of|possible|suspected)\b/i',$evidence)) continue;
            if (isset($result[$code])) continue;
            $base=$allowed[$code];
            $base['evidence']=$evidence;$base['reason']='Model-ranked evidence, verified against a deterministic candidate set. Human coding review required.';
            $result[$code]=$base;
        }
        return array_values($result);
    }
    private static function fallback(array $heuristic): array
    {
        $heuristic['warnings'][]='Remote AI unavailable or returned unverified evidence; deterministic suggestions were retained.';
        return $heuristic;
    }
}
