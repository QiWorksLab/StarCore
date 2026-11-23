<?php
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/service/Core.php';
use app\service\Core as C;
$id=(int)$argv[1];
try{C::sync($id);}catch(Throwable $e){try{C::sql('UPDATE bots SET sync_error=? WHERE id=?',[$e->getMessage(),$id]);}catch(Throwable $ignore){}}
