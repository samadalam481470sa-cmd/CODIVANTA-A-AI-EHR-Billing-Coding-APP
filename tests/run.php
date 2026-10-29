<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
use Codivanta\App;
use Codivanta\Store;
use Codivanta\Clinical;
use Codivanta\Audit;
use Codivanta\AIModel;
$tests=0;session_start();
function ok(bool $condition,string $message): void {global $tests;$tests++;if (!$condition) throw new RuntimeException("FAILED: $message");}
function call(App $app,string $method,string $url,array $input=[]): array {try{return $app->dispatch($method,$url,$input);}catch(InvalidArgumentException $e){return ['status'=>422,'body'=>['error'=>$e->getMessage()]];}}
function good(array $r,string $message): array {ok($r['status']>=200 && $r['status']<300,$message.' '.json_encode($r));return $r['body'];}
$dir=sys_get_temp_dir().'/codivanta-tests-'.bin2hex(random_bytes(4));mkdir($dir,0700,true);$path=$dir.'/state.json';
register_shutdown_function(function()use($dir){foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);});
$store=new Store($path);$app=new App($path);
$_SESSION=[];$_SERVER['HTTP_X_CSRF_TOKEN']='';
ok(call($app,'GET','/health')['status']===200,'health public');
ok(call($app,'GET','/api/v1/patients')['status']===401,'auth required');
ok(call($app,'POST','/api/v1/login',['username'=>'admin','password'=>'wrong'])['status']===401,'reject wrong password');
$login=good(call($app,'POST','/api/v1/login',['username'=>'admin','password'=>'DemoAdmin2027!']),'login');
$_SERVER['HTTP_X_CSRF_TOKEN']=$login['csrf'];
good(call($app,'POST','/api/v1/patients',['name'=>'Test Person','dob'=>'1980-01-01']),'patient seed');
// Unlike a GET, every mutation requires session CSRF.
$csrf=$_SERVER['HTTP_X_CSRF_TOKEN'];$_SERVER['HTTP_X_CSRF_TOKEN']='wrong';
ok(call($app,'POST','/api/v1/patients',['name'=>'Test Person','dob'=>'1980-01-01'])['status']===403,'csrf');
$_SERVER['HTTP_X_CSRF_TOKEN']=$csrf;
$p=good(call($app,'POST','/api/v1/patients',['name'=>'Synthetic Example','dob'=>'1985-12-17','insurance_member_id'=>'SYN-TST-1']),'patient creation');
ok(count($store->read()['patients'])===2,'patient count after two writes');
ok(call($app,'POST','/api/v1/patients',['name'=>'A','dob'=>'1980-01-01'])['status']===422,'validate name');
$e=good(call($app,'POST','/api/v1/encounters',['patient_id'=>$p['id'],'date'=>'2026-11-16','type'=>'Office visit']),'encounter creation');
ok(call($app,'POST','/api/v1/encounters',['patient_id'=>'pat_deadbeef1234','date'=>'2026-11-16','type'=>'Office visit'])['status']===422,'invalid patient');
ok(call($app,'POST','/api/v1/encounters/'.$e['id'].'/review')['status']===422,'no premature code approval');
$note='This is synthetic documentation. The patient has a known documented condition, and medication management was discussed. Follow-up instructions recorded.';
good(call($app,'PUT','/api/v1/encounters/'.$e['id'].'/note',['note'=>$note]),'save note');
$res=good(call($app,'POST','/api/v1/encounters/'.$e['id'].'/suggest',[]),'suggestions');
ok(count($res['suggestions'])===0,'no suggestions without official code import');
ok(call($app,'POST','/api/v1/encounters/'.$e['id'].'/diagnoses',['code'=>'A00.0'])['status']===422,'code blocked without catalog');
$c=good(call($app,'POST','/api/v1/claims',['encounter_id'=>$e['id'],'procedure'=>'99213','amount'=>'145.00','units'=>2]),'create claim');
ok($c['lines'][0]['amount_cents']===29000,'currency computed integer cents');
$a=good(call($app,'POST','/api/v1/claims/'.$c['id'].'/audit',[]),'scrub claim');
ok($a['status']==='blocked' && $a['audit']['blockers']>0,'unreviewed coding blocked');
ok(call($app,'POST','/api/v1/claims/'.$c['id'].'/submit',[])['status']===422,'submission blocked');
ok(call($app,'POST','/api/v1/claims/'.$c['id'].'/payment',['amount'=>'20'])['status']===422,'payment before submission blocked');
$state=$store->read();ok(Store::verify($state),'audit integrity');$bad=$state;$bad['audit'][0]['actor']='tampered';ok(!Store::verify($bad),'detect tampering');
ok(Clinical::check($state,'A00.0','2026-10-12')['valid']===false,'validation requires official catalog');
$reference=['suggestions'=>[['code'=>'R51.9','description'=>'Headache, unspecified','evidence'=>'The patient reports headache today.','confidence'=>0.7,'reason'=>'demo']]];
ok(count(AIModel::validate(['suggestions'=>[['code'=>'R51.9','evidence'=>'The patient reports headache today.']]],$reference,'The patient reports headache today.'))===1,'model grounded citation accepted');
ok(count(AIModel::validate(['suggestions'=>[['code'=>'I10','evidence'=>'The patient reports headache today.']]],$reference,'The patient reports headache today.'))===0,'model invented code rejected');
ok(count(AIModel::validate(['suggestions'=>[['code'=>'R51.9','evidence'=>'The patient has a fever today.']]],$reference,'The patient reports headache today.'))===0,'model unsupported evidence rejected');
// Import-like fixture is ONLY for isolated unit tests; never exposed as official FY2027 data.
$store->transaction(function(&$s){$s['catalog']['status']='official_imported';$s['codes']['R51.9']=['code'=>'R51.9','description'=>'Headache, unspecified','billable'=>true,'version'=>'FY2027'];});
good(call($app,'POST','/api/v1/encounters/'.$e['id'].'/diagnoses',['code'=>'R51.9']),'add validated catalog code');
good(call($app,'POST','/api/v1/encounters/'.$e['id'].'/review',[]),'approve coding');
$b=good(call($app,'POST','/api/v1/claims/'.$c['id'].'/audit',[]),'rescrub');
ok($b['status']==='ready','claim ready after review');
good(call($app,'POST','/api/v1/claims/'.$c['id'].'/submit',[]),'submit simulated');
ok(call($app,'POST','/api/v1/claims/'.$c['id'].'/submit',[])['status']===422,'prevent repeated submit');
$denied=good(call($app,'POST','/api/v1/claims/'.$c['id'].'/deny',['reason'=>'Simulated missing coverage']),'deny');
ok($denied['status']==='denied','denied state');
$appealed=good(call($app,'POST','/api/v1/claims/'.$c['id'].'/appeal',['reason'=>'Synthetic documentation supports the coding decision.']),'appeal');
ok($appealed['status']==='appealed','appealed state');
$pay=good(call($app,'POST','/api/v1/claims/'.$c['id'].'/payment',['amount'=>'290.00']),'record payment');
ok($pay['status']==='paid'&&$pay['paid_cents']===29000,'fully paid');
ok(call($app,'POST','/api/v1/claims/'.$c['id'].'/payment',['amount'=>'0.01'])['status']===422,'prevent overpayment');
$fhir=good(call($app,'GET','/api/v1/fhir/Patient/'.$p['id']),'FHIR');ok($fhir['resourceType']==='Patient','FHIR patient');
$stats=good(call($app,'GET','/api/v1/dashboard'),'dashboard');ok($stats['paid_cents']===29000,'dashboard aggregation');
// This specific test intentionally allows only billable in-range codes.
$state=$store->read();ok(Clinical::check($state,'R51.9','2025-10-01')['valid']===false,'date mismatch');
$state['codes']['A00']=['code'=>'A00','description'=>'Cholera','billable'=>false,'version'=>'FY2027'];ok(!Clinical::check($state,'A00','2026-10-12')['valid'],'nonbillable blocked');
$loginCoder=good(call($app,'POST','/api/v1/login',['username'=>'coder','password'=>'DemoCoder2027!']),'coder login');$_SERVER['HTTP_X_CSRF_TOKEN']=$loginCoder['csrf'];
ok(call($app,'POST','/api/v1/claims',['encounter_id'=>$e['id'],'procedure'=>'99213','amount'=>'50.00'])['status']===403,'coder cannot create claims');
$loginBiller=good(call($app,'POST','/api/v1/login',['username'=>'biller','password'=>'DemoBiller2027!']),'biller login');$_SERVER['HTTP_X_CSRF_TOKEN']=$loginBiller['csrf'];
ok(call($app,'POST','/api/v1/patients',['name'=>'Another Sample','dob'=>'2000-01-01'])['status']===403,'biller cannot create patient');
ok(Store::verify($store->read()),'final audit integrity');
echo "\n$tests assertions passed.\n";
