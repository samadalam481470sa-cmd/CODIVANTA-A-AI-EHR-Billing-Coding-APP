<?php
declare(strict_types=1);
namespace Codivanta;

final class Audit
{
    public static function scrub(array $state, array $claim): array
    {
        $findings=[];
        if (($state['catalog']['status'] ?? '') === 'demo_fixture') self::add($findings,'WARN','DEMO_CATALOG','Sample codes only — no authoritative full FY2027 catalog imported');
        $encounter=$state['encounters'][$claim['encounter_id']] ?? null;
        $patient=$encounter ? ($state['patients'][$encounter['patient_id']] ?? null) : null;
        if (!$encounter) self::add($findings,'BLOCK','MISSING_ENCOUNTER','Encounter does not exist');
        if (!$patient) self::add($findings,'BLOCK','MISSING_PATIENT','Patient record is unavailable');
        if ($patient && !($patient['insurance_member_id'] ?? '')) self::add($findings,'WARN','INSURANCE_MISSING','No synthetic insurance member ID recorded');
        if ($encounter) {
            if (strlen(trim($encounter['note'] ?? '')) < 35) self::add($findings,'WARN','DOCUMENTATION_INCOMPLETE','Clinical documentation is too short for meaningful coding review');
            if (!$encounter['diagnoses']) self::add($findings,'BLOCK','NO_DIAGNOSIS','At least one reviewed diagnosis is required');
            foreach ($encounter['diagnoses'] as $code) {
                try { $v=Clinical::check($state,$code,$encounter['date']); }
                catch (\InvalidArgumentException $e) { $v=['valid'=>false,'reason'=>$e->getMessage()]; }
                if (!$v['valid']) self::add($findings,'BLOCK','INVALID_ICD','Diagnosis ' . $code . ': '.$v['reason']);
            }
            if (!$encounter['reviewed']) self::add($findings,'BLOCK','CODER_REVIEW_REQUIRED','Clinical coding has not been approved by a reviewer');
        }
        if (!count($claim['lines'])) self::add($findings,'BLOCK','NO_CHARGES','Claim has no charge lines');
        $seen=[];
        foreach ($claim['lines'] as $line) {
            $key = ($line['procedure']??'').'|'.($line['modifier']??'');
            if (isset($seen[$key])) self::add($findings,'WARN','DUPLICATE_CHARGE','Possible repeated procedure/modifier '.$key);
            $seen[$key]=true;
            if (!preg_match('/^[A-Z0-9]{5}$/',$line['procedure'] ?? '')) self::add($findings,'BLOCK','PROCEDURE_FORMAT','Procedure code must have five letters/digits');
            if (($line['units']??0)<1 || ($line['units']??0)>100) self::add($findings,'BLOCK','UNITS_RANGE','Units outside supported range 1–100');
            if (($line['amount_cents']??0)<=0) self::add($findings,'BLOCK','AMOUNT_INVALID','Charge must be positive');
        }
        foreach ($state['claims'] as $other) {
            if ($other['id'] !== $claim['id'] && $other['encounter_id']===$claim['encounter_id'] && !in_array($other['status'],['void'],true)) {
                self::add($findings,'WARN','DUPLICATE_CLAIM','Another claim exists for this encounter'); break;
            }
        }
        $blocks=count(array_filter($findings,fn($f)=>$f['severity']==='BLOCK'));
        $warnings=count($findings)-$blocks;
        return ['outcome'=>$blocks?'BLOCKED':($warnings?'REVIEW':'PASS'),'blockers'=>$blocks,'warnings'=>$warnings,'findings'=>$findings,
            'checked_at'=>gmdate(DATE_ATOM),'ruleset'=>'codivanta-demo-2027-v1','disclaimer'=>'Not payer-specific adjudication or regulatory coding certification.'];
    }
    private static function add(array &$findings,string $severity,string $rule,string $message): void
    { $findings[]=['severity'=>$severity,'rule'=>$rule,'message'=>$message]; }
}
