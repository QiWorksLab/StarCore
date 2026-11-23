<?php
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/service/Core.php';
use app\service\Core as C;
date_default_timezone_set('Asia/Shanghai');posix_setsid();pcntl_async_signals(true);
$id=(int)$argv[1];$running=true;$proc=null;$pipes=[];$redis=C::redis();
pcntl_signal(SIGTERM,function()use(&$running){$running=false;});
pcntl_signal(SIGINT,function()use(&$running){$running=false;});
register_shutdown_function(function()use(&$proc,&$pipes,$redis,$id){
 $redis->del('sc:ready:'.$id,'sc:child:'.$id);
 if(is_resource($proc)){foreach($pipes as $p)if(is_resource($p))fclose($p);proc_terminate($proc);$end=microtime(true)+2;while(proc_get_status($proc)['running']&&microtime(true)<$end)usleep(50000);if(proc_get_status($proc)['running'])proc_terminate($proc,SIGKILL);proc_close($proc);}
});
function outputSafe(string $s,string $token):string{return str_replace($token,'[TOKEN]',$s);}
function recordLog(string $kind,string $text):void {global $id;$path=C::BASE.'/logs/'.$id.'/'.$kind.'.'.date('Y-m-d').'.log';file_put_contents($path,$text,FILE_APPEND|LOCK_EX);}
try {
 $b=C::bot($id);$token=C::decrypt($b['token']);$p=C::project((int)$b['project_id']);$cfg=$p['config'];$dir=C::BASE.'/projects/'.$p['id'].'/versions/'.(C::setting('candidate:'.$p['id'])?:$p['active_version']);
 if(is_file($dir.'/.starcore-runtime.json'))$cfg=json_decode(file_get_contents($dir.'/.starcore-runtime.json'),true);
 $env=getenv();if(is_file($dir.'/.env')){foreach(file($dir.'/.env',FILE_IGNORE_NEW_LINES) as $line){$line=trim($line);if(!$line||str_starts_with($line,'#'))continue;if(preg_match('/^(?:export )?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/',$line,$m))$env[$m[1]]=trim($m[2]," \"'");}}
 $env['BOT_TOKEN']=$token;$env['BOT_ID']=(string)$b['tg_id'];$env['STARCORE_BOT_ID']=(string)$id;$env['STARCORE_TRANSPORT']='stdin';$env['PYTHONUNBUFFERED']='1';
 $cmd=match($cfg['language']){'python'=>[$dir.'/.venv/bin/python','-u',$cfg['entry']],'php'=>[$cfg['runtime'],$cfg['entry']],'go'=>[$dir.'/.starcore-bot']};
 $args=array_map(fn($v)=>str_replace('{BOT_TOKEN}',$token,$v),$cfg['args']??[]);$cmd=array_merge($cmd,$args);$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$dir,$env);if(!is_resource($proc))throw new RuntimeException('业务进程启动失败');
 foreach($pipes as $h)stream_set_blocking($h,false);$child=proc_get_status($proc);$redis->set('sc:child:'.$id,(string)$child['pid']);$redis->set('sc:ready:'.$id,(string)time());$offset=(int)($redis->get('sc:offset:'.$id)?:0);$stdout='';$lastPoll=0;$lastCfg=0;$updates=[];
 $dispatch=function(array $u)use(&$b,$pipes,$redis,$id,$token){
  $b=C::bot($id);$key='sc:dedup:'.$id.':'.$u['update_id'];if($redis->exists($key))return;
  $rule=C::allowed($b['config'],$u,C::setting('exempt',[]));
  if($rule==='maintenance'){
   $m=$u['message']??$u['edited_message']??$u['channel_post']??$u['edited_channel_post']??$u['callback_query']['message']??null;
   $text=$b['config']['maintenance_text']?:C::setting('maintenance_text','维护中...请耐心等待...');
   try{if(isset($u['callback_query']))C::telegram($token,'answerCallbackQuery',['callback_query_id'=>$u['callback_query']['id'],'text'=>mb_substr($text,0,200)]);
   elseif(isset($m['chat']['id']))C::telegram($token,'sendMessage',['chat_id'=>$m['chat']['id'],'text'=>$text]);}catch(Throwable $e){recordLog('stderr',date('c').' 维护提示发送失败：'.outputSafe($e->getMessage(),$token)."\n");}
  }elseif($rule==='business'){
   $line=C::json($u)."\n";$sent=0;$end=microtime(true)+5;while($sent<strlen($line)){$n=fwrite($pipes[0],substr($line,$sent));if($n===false)throw new RuntimeException('业务输入流已关闭');$sent+=$n;if($n===0){if(microtime(true)>$end)throw new RuntimeException('业务进程未读取输入');usleep(10000);}}
  }
  $redis->setex($key,86400,'1');
 };
 while($running){
  $status=proc_get_status($proc);if(!$status['running']){
   foreach([1=>'stdout',2=>'stderr'] as $stream=>$kind){$remaining=stream_get_contents($pipes[$stream]);if($remaining!=='')recordLog($kind,outputSafe($remaining,$token));}
   if($stdout!=='')recordLog('stdout',outputSafe($stdout,$token));
   throw new RuntimeException('业务进程退出，退出码 '.$status['exitcode'].'；具体原因请查看 stderr 日志');
  }
  if(microtime(true)-$lastCfg>=0.5){$b=C::bot($id);$lastCfg=microtime(true);}
  foreach([1,2] as $stream){$chunk=stream_get_contents($pipes[$stream]);if($chunk==='')continue;if($stream===2){recordLog('stderr',outputSafe($chunk,$token));continue;}
   $stdout.=$chunk;while(($pos=strpos($stdout,"\n"))!==false){$line=substr($stdout,0,$pos);$stdout=substr($stdout,$pos+1);$j=json_decode($line,true);
    if(is_array($j)&&isset($j['method'],$j['params'])){try{C::telegram($token,$j['method'],$j['params']);}catch(Throwable $e){recordLog('stderr',date('c').' Telegram 输出失败：'.$e->getMessage()."\n");}}
    else recordLog('stdout',outputSafe($line,$token)."\n");}
   if(strlen($stdout)>2097152){recordLog('stdout',outputSafe($stdout,$token));$stdout='';}
  }
  if($b['config']['mode']==='polling'){
   if(microtime(true)-$lastPoll>=0.5){try{$updates=C::telegram($token,'getUpdates',['offset'=>$offset,'timeout'=>1,'allowed_updates'=>$b['config']['allowed_updates']]);$lastPoll=microtime(true);}catch(Throwable $e){recordLog('stderr',date('c').' '.$e->getMessage()."\n");usleep(500000);continue;}
    foreach($updates as $u){$dispatch($u);$offset=$u['update_id']+1;$redis->set('sc:offset:'.$id,(string)$offset);}
   }
  }else{
   $pending='sc:processing:'.$id;$queue='sc:updates:'.$id;
   $raw=$redis->lIndex($pending,0);if(!$raw){$raw=$redis->lMove($queue,$pending,'LEFT','RIGHT');}
   if($raw){$u=json_decode($raw,true);if(is_array($u))$dispatch($u);$redis->lPop($pending);}
  }
  usleep(50000);
 }
}catch(Throwable $e){recordLog('stderr',date('c').' '.(isset($token)?outputSafe($e->getMessage(),$token):$e->getMessage())."\n");exit(1);}
