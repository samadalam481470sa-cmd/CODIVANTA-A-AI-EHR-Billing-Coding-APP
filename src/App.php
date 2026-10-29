<?php
declare(strict_types=1);
namespace Codivanta;

require_once __DIR__.'/Store.php';
require_once __DIR__.'/Clinical.php';
require_once __DIR__.'/Audit.php';
require_once __DIR__.'/AIModel.php';

final class App
{
    private Store $store;
    public function __construct(?string $path=null)
    {
        $this->store=new Store($path ?? (getenv('CODIVANTA_DATA') ?: dirname(__DIR__).'/data/state.json'));
    }

    public function dispatch(string $method,string $route,array $input): array
    {
        if ($route==='/health') return ['status'=>200,'body'=>['status'=>'ok','demo'=>true,'service'=>'codivanta-api']];
        if ($route==='/api/v1/session') return $this->response(['authenticated'=>!empty($_SESSION['user']), 'user'=>$_SESSION['user']??null, 'csrf'=>$_SESSION['csrf']??null, 'demo'=>true]);
        if ($route==='/api/v1/login' && $method==='POST') return $this->login($input);
        if (empty($_SESSION['user'])) return $this->failure(401,'Authentication required');
        $actor=$_SESSION['user']['username'];
        $role=$_SESSION['user']['role'];
        if ($method!=='GET') {
            if (!hash_equals($_SESSION['csrf']??'', $_SERVER['HTTP_X_CSRF_TOKEN']??'') || empty($_SESSION['csrf'])) return $this->failure(403,'Missing or invalid CSRF token');
        }
        if ($route==='/api/v1/logout' && $method==='POST') { $_SESSION=[];session_destroy();return $this->response(['ok'=>true]); }
        if ($route==='/api/v1/dashboard' && $method==='GET') return $this->dashboard();
        if ($route==='/api/v1/patients' && $method==='GET') return $this->response(array_values($this->store->read()['patients']));
        if ($route==='/api/v1/patients' && $method==='POST') return $this->patient($input,$actor,$role);
        if ($route==='/api/v1/encounters' && $method==='GET') return $this->response(array_values($this->store->read()['encounters']));
        if ($route==='/api/v1/encounters' && $method==='POST') return $this->encounter($input,$actor,$role);
        if (preg_match('#^/api/v1/encounters/(enc_[a-f0-9]+)/note$#',$route,$m) && $method==='PUT') return $this->note($m[1],$input,$actor,$role);
        if (preg_match('#^/api/v1/encounters/(enc_[a-f0-9]+)/diagnoses$#',$route,$m) && $method==='POST') return $this->diagnosis($m[1],$input,$actor,$role);
        if (preg_match('#^/api/v1/encounters/(enc_[a-f0-9]+)/review$#',$route,$m) && $method==='POST') return $this->review($m[1],$actor,$role);
        if (preg_match('#^/api/v1/encounters/(enc_[a-f0-9]+)/suggest$#',$route,$m) && $method==='POST') return $this->suggest($m[1],$actor,$role);
        if ($route==='/api/v1/codes' && $method==='GET') { $s=$this->store->read();return $this->response(['catalog'=>$s['catalog'],'results'=>Clinical::search($s,(string)($_GET['q']??''))]); }
        if ($route==='/api/v1/claims' && $method==='GET') return $this->response(array_values($this->store->read()['claims']));
        if ($route==='/api/v1/claims' && $method==='POST') return $this->claim($input,$actor,$role);
        if (preg_match('#^/api/v1/claims/(clm_[a-f0-9]+)/audit$#',$route,$m) && $method==='POST') return $this->scrub($m[1],$actor,$role);
        if (preg_match('#^/api/v1/claims/(clm_[a-f0-9]+)/submit$#',$route,$m) && $method==='POST') return $this->submit($m[1],$actor,$role);
        if (preg_match('#^/api/v1/claims/(clm_[a-f0-9]+)/payment$#',$route,$m) && $method==='POST') return $this->payment($m[1],$input,$actor,$role);
        if (preg_match('#^/api/v1/claims/(clm_[a-f0-9]+)/deny$#',$route,$m) && $method==='POST') return $this->deny($m[1],$input,$actor,$role);
        if (preg_match('#^/api/v1/claims/(clm_[a-f0-9]+)/appeal$#',$route,$m) && $method==='POST') return $this->appeal($m[1],$input,$actor,$role);
        if ($route==='/api/v1/audit-log' && $method==='GET') { $s=$this->store->read();return $this->response(['valid'=>Store::verify($s),'events'=>array_reverse(array_slice($s['audit'],-150))]); }
        if (preg_match('#^/api/v1/fhir/Patient/(pat_[a-f0-9]+)$#',$route,$m) && $method==='GET') return $this->fhirPatient($m[1]);
        return $this->failure(404,'Unknown endpoint');
    }

    private function login(array $input): array
    {
        $username=trim((string)($input['username']??''));
        $password=(string)($input['password']??'');
        $users=['admin'=>['password'=>'DemoAdmin2027!','role'=>'admin'], 'coder'=>['password'=>'DemoCoder2027!','role'=>'coder'], 'biller'=>['password'=>'DemoBiller2027!','role'=>'biller']];
        // Demo-only identities. This app rejects production mode to prevent accidental PHI deployment.
        if (getenv('CODIVANTA_DEMO_MODE')==='0') return $this->failure(503,'Production authentication provider is not configured. Demo disabled.');
        if (!isset($users[$username]) || !hash_equals($users[$username]['password'],$password)) return $this->failure(401,'Invalid demo credentials');
        session_regenerate_id(true);
        $_SESSION['user']=['username'=>$username,'role'=>$users[$username]['role']];
        $_SESSION['csrf']=bin2hex(random_bytes(32));
        return $this->response(['user'=>$_SESSION['user'],'csrf'=>$_SESSION['csrf'],'demo'=>true]);
    }

    private function dashboard(): array
    {
        $s=$this->store->read();$claims=array_values($s['claims']);
        $billed=0;$paid=0;$open=0;$blocked=0;$denied=0;
        foreach ($claims as $claim) {
            $amount=array_sum(array_column($claim['lines'],'amount_cents'));$billed+=$amount;$paid+=$claim['paid_cents'];
            if ($claim['status']==='denied') $denied++;
            if ($claim['status']==='blocked') $blocked++;
            if (!in_array($claim['status'],['paid','void'],true)) $open++;
        }
        return $this->response(['patients'=>count($s['patients']),'encounters'=>count($s['encounters']),
            'claims'=>count($claims),'open_claims'=>$open,'blocked_claims'=>$blocked,'denials'=>$denied,
            'billed_cents'=>$billed,'paid_cents'=>$paid,'outstanding_cents'=>max(0,$billed-$paid),
            'catalog'=>$s['catalog'],'recent_claims'=>array_slice(array_reverse($claims),0,7),'audit_verified'=>Store::verify($s)]);
    }

    private function patient(array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','coder'])) return $this->failure(403,'Insufficient role');
        $name=$this->required($v,'name',3,90);$dob=$this->date($this->required($v,'dob',10,10));
        $member=trim((string)($v['insurance_member_id']??''));
        if (strlen($member)>40) throw new \InvalidArgumentException('Member ID too long');
        return $this->response($this->store->transaction(function(&$s)use($name,$dob,$member,$actor){
            $id=Store::id('pat');$row=['id'=>$id,'name'=>$name,'dob'=>$dob,'insurance_member_id'=>$member,'created_at'=>gmdate(DATE_ATOM)];
            $s['patients'][$id]=$row;Store::event($s,$actor,'patient.create',$id);return $row;
        }),201);
    }

    private function encounter(array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','coder'])) return $this->failure(403,'Insufficient role');
        $patient=$this->required($v,'patient_id',8,25);$date=$this->date($this->required($v,'date',10,10));
        $type=$this->required($v,'type',3,80);
        return $this->response($this->store->transaction(function(&$s)use($patient,$date,$type,$actor){
            if (!isset($s['patients'][$patient])) throw new \InvalidArgumentException('Patient not found');
            $id=Store::id('enc');$row=['id'=>$id,'patient_id'=>$patient,'date'=>$date,'type'=>$type,'note'=>'','diagnoses'=>[],
                'reviewed'=>false,'reviewed_by'=>null,'created_at'=>gmdate(DATE_ATOM)];
            $s['encounters'][$id]=$row;Store::event($s,$actor,'encounter.create',$id);return $row;
        }),201);
    }

    private function note(string $id,array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','coder'])) return $this->failure(403,'Insufficient role');
        $note=$this->required($v,'note',1,10000);
        return $this->response($this->store->transaction(function(&$s)use($id,$note,$actor){
            if (!isset($s['encounters'][$id])) throw new \InvalidArgumentException('Encounter not found');
            $s['encounters'][$id]['note']=$note;$s['encounters'][$id]['reviewed']=false;$s['encounters'][$id]['reviewed_by']=null;
            Store::event($s,$actor,'encounter.note.update',$id,['length'=>strlen($note)]);
            return $s['encounters'][$id];
        }));
    }

    private function diagnosis(string $id,array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','coder'])) return $this->failure(403,'Insufficient role');
        $code=Clinical::normalizeCode($this->required($v,'code',3,10));
        return $this->response($this->store->transaction(function(&$s)use($id,$code,$actor){
            $enc=$s['encounters'][$id]??null;
            if (!$enc) throw new \InvalidArgumentException('Encounter not found');
            $check=Clinical::check($s,$code,$enc['date']);
            if (!$check['valid']) throw new \InvalidArgumentException($check['reason']);
            if (!in_array($code,$s['encounters'][$id]['diagnoses'],true)) $s['encounters'][$id]['diagnoses'][]=$code;
            $s['encounters'][$id]['reviewed']=false;$s['encounters'][$id]['reviewed_by']=null;
            Store::event($s,$actor,'diagnosis.add',$id,['code'=>$code]);return $s['encounters'][$id];
        }));
    }

    private function review(string $id,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','coder'])) return $this->failure(403,'Insufficient role');
        return $this->response($this->store->transaction(function(&$s)use($id,$actor){
            $e=$s['encounters'][$id]??null;if (!$e) throw new \InvalidArgumentException('Encounter not found');
            if (!$e['diagnoses']||strlen(trim($e['note']))<35) throw new \InvalidArgumentException('Document note and diagnosis before review');
            foreach ($e['diagnoses'] as $code) {
                $check=Clinical::check($s,$code,$e['date']);
                if (!$check['valid']) throw new \InvalidArgumentException($code.': '.$check['reason']);
            }
            $s['encounters'][$id]['reviewed']=true;$s['encounters'][$id]['reviewed_by']=$actor;
            Store::event($s,$actor,'encounter.coding.review',$id,['codes'=>$e['diagnoses']]);return $s['encounters'][$id];
        }));
    }

    private function suggest(string $id,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','coder'])) return $this->failure(403,'Insufficient role');
        $s=$this->store->read();$e=$s['encounters'][$id]??null;
        if (!$e) throw new \InvalidArgumentException('Encounter not found');
        $result=Clinical::suggest($s,$e['note'],$e['date']);
        $result=AIModel::review($s,$e['note'],$e['date'],$result);
        $this->store->transaction(function(&$state)use($actor,$id){Store::event($state,$actor,'ai.coding.suggest',$id);});
        return $this->response($result);
    }

    private function claim(array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','biller'])) return $this->failure(403,'Insufficient role');
        $id=$this->required($v,'encounter_id',8,25);
        $proc=strtoupper($this->required($v,'procedure',5,5));
        if (!preg_match('/^[A-Z0-9]{5}$/',$proc)) throw new \InvalidArgumentException('Five-character procedure code required');
        $amount=$this->money($v['amount']??null);
        $units=filter_var($v['units']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($units===false) throw new \InvalidArgumentException('Units must be 1 to 100');
        $modifier=strtoupper(trim((string)($v['modifier']??'')));
        if ($modifier!==''&&!preg_match('/^[A-Z0-9]{2}$/',$modifier)) throw new \InvalidArgumentException('Modifier must have two characters');
        return $this->response($this->store->transaction(function(&$s)use($id,$proc,$amount,$units,$modifier,$actor){
            if (!isset($s['encounters'][$id])) throw new \InvalidArgumentException('Encounter not found');
            $claimId=Store::id('clm');
            $row=['id'=>$claimId,'encounter_id'=>$id,'status'=>'draft','lines'=>[['procedure'=>$proc,'units'=>$units,'modifier'=>$modifier,'amount_cents'=>$amount*$units]],
                'paid_cents'=>0,'audit'=>null,'denial_reason'=>null,'appeal'=>null,'created_at'=>gmdate(DATE_ATOM),'submitted_at'=>null];
            $s['claims'][$claimId]=$row;Store::event($s,$actor,'claim.create',$claimId);return $row;
        }),201);
    }

    private function scrub(string $id,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','biller','coder'])) return $this->failure(403,'Insufficient role');
        return $this->response($this->store->transaction(function(&$s)use($id,$actor){
            $c=$s['claims'][$id]??null;if (!$c) throw new \InvalidArgumentException('Claim not found');
            if (!in_array($c['status'],['draft','blocked','review','ready'],true)) throw new \InvalidArgumentException('Claim cannot be rescrubbed in its current state');
            $result=Audit::scrub($s,$c);$s['claims'][$id]['audit']=$result;
            $s['claims'][$id]['status']=match($result['outcome']){'BLOCKED'=>'blocked','REVIEW'=>'review',default=>'ready'};
            Store::event($s,$actor,'claim.audit',$id,['result'=>$result['outcome'],'blockers'=>$result['blockers']]);return $s['claims'][$id];
        }));
    }

    private function submit(string $id,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','biller'])) return $this->failure(403,'Insufficient role');
        return $this->response($this->store->transaction(function(&$s)use($id,$actor){
            $c=$s['claims'][$id]??null;if (!$c) throw new \InvalidArgumentException('Claim not found');
            if ($c['status']!=='ready' && $c['status']!=='review') throw new \InvalidArgumentException('Claim must pass scrub before simulation');
            $fresh=Audit::scrub($s,$c);
            if ($fresh['blockers']>0) throw new \InvalidArgumentException('Unresolved blocking findings');
            $s['claims'][$id]['audit']=$fresh;$s['claims'][$id]['status']='submitted_simulated';$s['claims'][$id]['submitted_at']=gmdate(DATE_ATOM);
            Store::event($s,$actor,'claim.submit.simulated',$id,['external_transmission'=>false]);return $s['claims'][$id];
        }));
    }

    private function payment(string $id,array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','biller'])) return $this->failure(403,'Insufficient role');
        $amount=$this->money($v['amount']??null);
        return $this->response($this->store->transaction(function(&$s)use($id,$actor,$amount){
            $c=$s['claims'][$id]??null;if (!$c) throw new \InvalidArgumentException('Claim not found');
            if (!in_array($c['status'],['submitted_simulated','partial_paid','denied','appealed'],true)) throw new \InvalidArgumentException('Claim must be submitted before recording payment');
            $due=array_sum(array_column($c['lines'],'amount_cents'))-$c['paid_cents'];
            if ($amount>$due) throw new \InvalidArgumentException('Payment exceeds remaining balance');
            $s['claims'][$id]['paid_cents']+=$amount;
            $s['claims'][$id]['status']=$amount===$due?'paid':'partial_paid';
            Store::event($s,$actor,'claim.payment.simulated',$id,['amount_cents'=>$amount]);return $s['claims'][$id];
        }));
    }

    private function deny(string $id,array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','biller'])) return $this->failure(403,'Insufficient role');
        $reason=$this->required($v,'reason',5,400);
        return $this->response($this->store->transaction(function(&$s)use($id,$actor,$reason){
            $c=$s['claims'][$id]??null;if (!$c) throw new \InvalidArgumentException('Claim not found');
            if (!in_array($c['status'],['submitted_simulated','partial_paid'],true)) throw new \InvalidArgumentException('Denial requires simulated submitted claim');
            $s['claims'][$id]['status']='denied';$s['claims'][$id]['denial_reason']=$reason;
            Store::event($s,$actor,'claim.denial.simulated',$id,['reason'=>$reason]);return $s['claims'][$id];
        }));
    }

    private function appeal(string $id,array $v,string $actor,string $role): array
    {
        if (!$this->allowed($role,['admin','biller'])) return $this->failure(403,'Insufficient role');
        $reason=$this->required($v,'reason',15,1200);
        return $this->response($this->store->transaction(function(&$s)use($id,$actor,$reason){
            $c=$s['claims'][$id]??null;if (!$c) throw new \InvalidArgumentException('Claim not found');
            if ($c['status']!=='denied') throw new \InvalidArgumentException('Only denied claims may be appealed');
            $s['claims'][$id]['status']='appealed';$s['claims'][$id]['appeal']=['reason'=>$reason,'at'=>gmdate(DATE_ATOM),'simulated'=>true];
            Store::event($s,$actor,'claim.appeal.simulated',$id);return $s['claims'][$id];
        }));
    }

    private function fhirPatient(string $id): array
    {
        $patient=$this->store->read()['patients'][$id]??null;
        if (!$patient) return $this->failure(404,'Patient not found');
        $parts=explode(' ',$patient['name']);$family=array_pop($parts);
        return $this->response(['resourceType'=>'Patient','id'=>$id,'meta'=>['profile'=>['http://hl7.org/fhir/StructureDefinition/Patient']],
            'name'=>[['use'=>'official','family'=>$family,'given'=>$parts]],'birthDate'=>$patient['dob'],
            'text'=>['status'=>'generated','div'=>'<div xmlns="http://www.w3.org/1999/xhtml">Synthetic demo resource</div>']]);
    }

    private function date(string $date): string
    {
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$d || $d->format('Y-m-d')!==$date) throw new \InvalidArgumentException('Invalid date; expected YYYY-MM-DD');
        return $date;
    }
    private function required(array $v,string $key,int $min,int $max): string
    {
        $value=trim((string)($v[$key]??''));$size=strlen($value);
        if ($size<$min || $size>$max) throw new \InvalidArgumentException("{$key} must be {$min}–{$max} characters");
        return $value;
    }
    private function money(mixed $value): int
    {
        $str=trim((string)$value);
        if (!preg_match('/^(?:[1-9][0-9]{0,5}|0)(?:\.[0-9]{1,2})?$/',$str)) throw new \InvalidArgumentException('Use a positive amount up to 999999.99');
        $parts=explode('.',$str);$amount=((int)$parts[0])*100+(int)str_pad($parts[1]??'',2,'0');
        if ($amount<=0) throw new \InvalidArgumentException('Amount must be positive');
        return $amount;
    }
    private function allowed(string $role,array $roles): bool {return in_array($role,$roles,true);}
    private function response(mixed $value,int $status=200): array{return ['status'=>$status,'body'=>$value];}
    private function failure(int $status,string $message): array{return ['status'=>$status,'body'=>['error'=>$message]];}
}
