<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
use Codivanta\Store;
if (PHP_SAPI!=='cli') exit(1);
$path=getenv('CODIVANTA_DATA') ?: dirname(__DIR__).'/data/state.json';
$store=new Store($path);
$store->transaction(function(&$s){
    if (count($s['patients'])) return;
    // Deliberately tiny sample/reference catalog; not misrepresented as full CDC release.
    if (($s['catalog']['status'] ?? '') === 'not_imported') {
        $examples = ['R51.9'=>'Headache, unspecified','I10'=>'Essential (primary) hypertension','E11.9'=>'Type 2 diabetes mellitus without complications','R53.83'=>'Other fatigue','Z00.00'=>'Encounter for general adult medical examination without abnormal findings','J06.9'=>'Acute upper respiratory infection, unspecified'];
        foreach ($examples as $code=>$description) $s['codes'][$code]=['code'=>$code,'description'=>$description,'billable'=>true,'version'=>'FY2027'];
        $s['catalog']=['version'=>'FY2027','status'=>'demo_fixture','count'=>count($examples),'sha256'=>null,'source'=>'Six illustrative reference codes; not the complete official FY2027 catalog','imported_at'=>gmdate(DATE_ATOM)];
    }
    $i=0;

    foreach ([['Avery Sample','1988-04-19','SYN-AX-1002'],['Jordan Example','1976-11-04','SYN-BX-1008'],['Taylor Fiction','1995-06-13','SYN-CX-1011'],['Morgan Testcase','1964-02-22','SYN-DX-1014']] as $row) {
        $p=Store::id('pat');$s['patients'][$p]=['id'=>$p,'name'=>$row[0],'dob'=>$row[1],'insurance_member_id'=>$row[2],'created_at'=>gmdate(DATE_ATOM)];
        $e=Store::id('enc');
        $dx=['R51.9','I10','E11.9','R53.83'][$i];
        $description=$s['codes'][$dx]['description']??'Symptom documented';
        $s['encounters'][$e]=['id'=>$e,'patient_id'=>$p,'date'=>'2026-10-18','type'=>'Office visit',
            'note'=>'Synthetic documentation for portfolio demonstration. Assessment: '.$description.'. Provider reviewed symptoms and follow-up instructions. This is not a real patient.',
            'diagnoses'=>[$dx],'reviewed'=>true,'reviewed_by'=>'cli:synthetic_coder','created_at'=>gmdate(DATE_ATOM)];
        if ($i<3) {
            $c=Store::id('clm');$charge=[14500,22000,8500][$i];$status=['paid','denied','draft'][$i];
            $row=['id'=>$c,'encounter_id'=>$e,'status'=>$status,'lines'=>[['procedure'=>'99213','units'=>1,'modifier'=>'','amount_cents'=>$charge]],
                'paid_cents'=>$i===0?$charge:0,'audit'=>null,'denial_reason'=>$i===1?'Synthetic policy mismatch':null,
                'appeal'=>null,'created_at'=>gmdate(DATE_ATOM),'submitted_at'=>$i===2?null:gmdate(DATE_ATOM)];
            $s['claims'][$c]=$row;
        }
        $i++;
    }
    Store::event($s,'cli:seed','demo.seed','synthetic_records',['patients'=>4,'encounters'=>4,'claims'=>3,'code_catalog'=>'demo_fixture']);
});
echo "Synthetic demo records available.\n";
