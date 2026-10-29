<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/App.php';
use Codivanta\App;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; connect-src \'self\'; base-uri \'none\'; form-action \'self\'');
header('Cache-Control: no-store');
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure','1');
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
$path=parse_url($_SERVER['REQUEST_URI']??'/', PHP_URL_PATH) ?: '/';
if ($path==='/' || $path==='/index.html') {
    header('Content-Type: text/html; charset=utf-8');readfile(__DIR__.'/app.html');return;
}
if (str_starts_with($path,'/assets/')) {
    $filename=basename($path);
    if (!in_array($filename,['app.js','styles.css','logo.svg'],true)) {http_response_code(404);return;}
    $file=__DIR__.'/assets/'.$filename;
    header('Content-Type: '.(str_ends_with($filename,'.js')?'text/javascript':(str_ends_with($filename,'.css')?'text/css':'image/svg+xml')));
    readfile($file);return;
}
if (!str_starts_with($path,'/api/') && $path!=='/health') {http_response_code(404);echo 'Not found';return;}
header('Content-Type: application/json; charset=utf-8');
$method=$_SERVER['REQUEST_METHOD']??'GET';
$raw=file_get_contents('php://input');
if (strlen($raw)>30000) {http_response_code(413);echo json_encode(['error'=>'Request too large']);return;}
try {
    $input=$raw===''?[]:json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('JSON object required');
    $response=(new App())->dispatch($method,$path,$input);
    http_response_code($response['status']);echo json_encode($response['body'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (JsonException|InvalidArgumentException $e) {
    http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('Codivanta request failed: '.$e->getMessage());
    http_response_code(500);echo json_encode(['error'=>'Internal error (see server log)']);
}
