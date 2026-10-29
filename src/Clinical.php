<?php
declare(strict_types=1);
namespace Codivanta;

final class Clinical
{
    public const FY_START = '2026-10-01';
    public const FY_END = '2027-09-30';

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z][0-9][0-9AB](?:\.?[0-9A-Z]{1,4})?$/', $code)) throw new \InvalidArgumentException('Invalid ICD-10-CM code format');
        return $code;
    }

    public static function search(array $state, string $term, int $limit = 30): array
    {
        $term = trim($term);
        if (strlen($term) < 2) return [];
        $matches = [];
        foreach ($state['codes'] as $code => $entry) {
            if (stripos($code, $term) !== false || stripos($entry['description'], $term) !== false) {
                $matches[] = $entry;
                if (count($matches) >= $limit) break;
            }
        }
        return $matches;
    }

    public static function check(array $state, string $code, string $date): array
    {
        $code = self::normalizeCode($code);
        if ($date < self::FY_START || $date > self::FY_END) return ['valid'=>false, 'reason'=>'Encounter date is outside FY2027'];
        if (!in_array(($state['catalog']['status'] ?? ''), ['official_imported','demo_fixture'],true)) return ['valid'=>false,'reason'=>'Official FY2027 catalog not imported'];
        if (($state['catalog']['status'] ?? '') === 'demo_fixture' && getenv('CODIVANTA_DEMO_MODE') === '0') return ['valid'=>false,'reason'=>'Demo catalog cannot be used in production mode'];
        $entry = $state['codes'][$code] ?? null;
        if (!$entry) return ['valid'=>false,'reason'=>'Not found in loaded FY2027 code set'];
        if (!$entry['billable']) return ['valid'=>false,'reason'=>'Category/header code is not billable'];
        return ['valid'=>true, 'entry'=>$entry, 'reason'=>($state['catalog']['status']==='demo_fixture'?'Demo reference only — not full official FY2027 validation':'Verified in imported official FY2027 catalog')];
    }

    /** Conservative, deterministic assistant: NEVER outputs codes absent from catalog. */
    public static function suggest(array $state, string $text, string $date): array
    {
        if (!in_array(($state['catalog']['status'] ?? ''),['official_imported','demo_fixture'],true)) return ['suggestions'=>[], 'warnings'=>['Import official FY2027 codes to enable evidence-linked suggestions.']];
        if ($date < self::FY_START || $date > self::FY_END) return ['suggestions'=>[], 'warnings'=>['Encounter outside FY2027 code effective range.']];
        $normalized = strtolower(preg_replace('/\s+/', ' ', $text));
        $sentences = preg_split('/(?<=[.!?])\s+|\R+/', $text) ?: [];
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, fn($v)=>strlen($v) > 3 && !in_array($v, ['with','from','that','this','patient','history','denies','rule','negative'],true))));
        $results = [];
        foreach ($state['codes'] as $code => $entry) {
            if (!$entry['billable']) continue;
            $description = strtolower($entry['description']);
            $words = preg_split('/[^a-z0-9]+/', $description, -1, PREG_SPLIT_NO_EMPTY);
            $meaningful = array_values(array_filter($words, fn($v)=>strlen($v)>3 && !in_array($v, ['unspecified','other','without','with','initial','encounter'],true)));
            if (!$meaningful) continue;
            $hits = array_values(array_intersect($meaningful, $tokens));
            if (count($hits) < min(2, count($meaningful))) continue;
            $best = '';
            foreach ($sentences as $sentence) {
                $lower = strtolower($sentence);
                if (preg_match('/\b(?:denies|rule out|ruled out|no evidence of|negative for|family history of|possible|suspected|question of)\b/i',$sentence)) continue;
                if (count(array_filter($hits,fn($x)=>str_contains($lower,$x))) >= min(2,count($hits))) { $best = trim($sentence); break; }
            }
            if ($best === '') continue;
            $score = min(0.85, round(count($hits) / max(2,count($meaningful)),2));
            $results[] = ['code'=>$code,'description'=>$entry['description'],'evidence'=>mb_safe_substr($best,0,220),'confidence'=>$score,
                'reason'=>'Keyword overlap with documented affirmative encounter text. Human clinical coding review required.'];
            if (count($results) >= 250) break;
        }
        usort($results, fn($a,$b)=>($b['confidence'] <=> $a['confidence']) ?: strcmp($a['code'],$b['code']));
        return ['suggestions'=>array_slice($results,0,8),'warnings'=>[($state['catalog']['status']==='demo_fixture'?'Sample reference catalog only. Import official FY2027 release for authoritative code membership checks.':'Official release loaded.'),'Heuristic assistance only: does not implement complete FY2027 guidelines, sequencing, exclusions, or clinical inference.']];
    }
}

function mb_safe_substr(string $value,int $start,int $length): string { return function_exists('mb_substr') ? mb_substr($value,$start,$length):substr($value,$start,$length); }
