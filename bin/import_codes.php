<?php
declare(strict_types=1);
/** Import the official CDC FY2027 ICD-10-CM *order* file (not arbitrary crowdsourced lists).
 * Source: https://ftp.cdc.gov/pub/Health_Statistics/NCHS/Publications/ICD10CM/2027/
 * File usually named icd10cm-order-2027.txt inside icd10cm-code-descriptions-2027.zip
 */
require_once dirname(__DIR__).'/src/App.php';
use Codivanta\Store;

if (PHP_SAPI!=='cli') {http_response_code(403);exit(1);}
$path=$argv[1]??'';
if (!$path || !is_file($path)) {fwrite(STDERR,"Usage: php bin/import_codes.php /path/to/icd10cm-order-2027.txt\n");exit(2);}
$filename=strtolower(basename($path));
if (!str_contains($filename,'2027') || !str_contains($filename,'order')) {fwrite(STDERR,"Input filename must identify official FY2027 order file.\n");exit(2);}
$handle=fopen($path,'rb');if (!$handle) {fwrite(STDERR,"Cannot open file\n");exit(2);}
$codes=[];$count=0;$invalid=0;
while (($line=fgets($handle))!==false) {
    // Official "order" format: 5-digit ordinal, 3–7-character code, billable flag 0/1,
    // short description (fixed width), long description.
    if (!preg_match('/^\s*\d{5}\s+([A-Z][0-9][0-9AB][A-Z0-9]{0,4})\s+([01])\s+(.+?)\s*$/',$line,$m)) { $invalid++;continue; }
    $raw=strtoupper($m[1]);$code=strlen($raw)>3?substr($raw,0,3).'.'.substr($raw,3):$raw;
    $rest=trim($m[3]);
    // The order file contains a short title followed by a long title; prefer the long one.
    $parts=preg_split('/\s{2,}/',$rest,2);
    $desc=trim($parts[1]??$parts[0]);
    if ($desc==='') {$invalid++;continue;}
    $codes[$code]=['code'=>$code,'description'=>$desc,'billable'=>$m[2]==='1','version'=>'FY2027'];$count++;
}
fclose($handle);
if ($count < 50000) {fwrite(STDERR,"Rejected: only $count codes parsed (expected a complete official release). Invalid rows: $invalid\n");exit(3);}
$sha=hash_file('sha256',$path);if (!$sha) exit(3);
$store=new Store(getenv('CODIVANTA_DATA') ?: dirname(__DIR__).'/data/state.json');
$store->transaction(function(&$state)use($codes,$count,$sha,$filename){
    $state['codes']=$codes;
    $state['catalog']=['version'=>'FY2027','status'=>'official_imported','count'=>$count,'sha256'=>$sha,
        'source'=>'CDC NCHS FY2027 official order file: '.$filename,'imported_at'=>gmdate(DATE_ATOM)];
    Store::event($state,'cli:authorized_importer','catalog.official_import','FY2027',['count'=>$count,'sha256'=>$sha]);
});
echo "Imported $count official FY2027 ICD-10-CM entries. SHA-256: $sha\n";
