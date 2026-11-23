<?php
namespace app;
use think\exception\Handle;
use think\Response;
use Throwable;
class ExceptionHandle extends Handle {
 public function render($request,Throwable $e):Response {
  return json(['ok'=>false,'error'=>'服务器内部错误，请查看服务日志'],500);
 }
}
