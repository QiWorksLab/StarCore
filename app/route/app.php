<?php
use think\facade\Route;
Route::get('/',function(){return response(file_get_contents(app()->getRootPath().'public/index.html'),200,['Content-Type'=>'text/html; charset=utf-8']);});
Route::get('api/:action','Api/index')->pattern(['action'=>'[a-z-]+']);
Route::post('api/:action','Api/index')->pattern(['action'=>'[a-z-]+']);
Route::post('hook/:key','Api/hook')->pattern(['key'=>'[a-f0-9]{48}']);
Route::miss(function(){return json(['ok'=>false,'error'=>'页面不存在'],404);});
