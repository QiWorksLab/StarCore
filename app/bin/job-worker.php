<?php
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/app/service/Core.php';require dirname(__DIR__).'/app/service/Manager.php';
use app\service\Core as C;
use app\service\Manager as M;
date_default_timezone_set('Asia/Shanghai');
$j=C::one("SELECT * FROM jobs WHERE id=? AND state='running'",[(int)$argv[1]]);if(!$j)exit(1);
$v=json_decode($j['payload'],true);$id=(int)$v['id'];$actor=$j['actor_id']?(int)$j['actor_id']:null;
try{
 if($j['type']==='mode'){
  $b=C::bot($id);$t=C::decrypt($b['token']);$m=new M;
  try{$m->setMode($b,$v['config'],$t,$v['drop']);C::sql('UPDATE bots SET config=? WHERE id=?',[C::json($v['config']),$id]);}
  catch(Throwable $e){try{$m->setMode($b,$b['config'],$t,false);}catch(Throwable $r){throw new RuntimeException($e->getMessage().'；旧配置恢复失败：'.$r->getMessage());}throw $e;}
  C::log($id,'切换消息模式',$v['config']['mode'],$actor);try{C::sync($id);}catch(Throwable $e){C::sql('UPDATE bots SET sync_error=? WHERE id=?',[$e->getMessage(),$id]);}
 }elseif($j['type']==='token'){
  $b=C::bot($id);$t=C::decrypt($v['token']);$me=C::telegram($t,'getMe');if((string)$me['id']!==(string)$b['tg_id'])throw new RuntimeException('新 Token 不属于当前机器人');
  (new M)->setMode($b,$b['config'],$t,false);C::sql('UPDATE bots SET token=? WHERE id=?',[$v['token'],$id]);C::log($id,'更换 Token','',$actor);
  try{C::sync($id);}catch(Throwable $e){C::sql('UPDATE bots SET sync_error=? WHERE id=?',[$e->getMessage(),$id]);}
 }else (new M)->command($j);
 C::sql("UPDATE jobs SET state='success',result='完成',payload='{}',finished_at=NOW() WHERE id=?",[$j['id']]);
}catch(Throwable $e){C::sql("UPDATE jobs SET state='failed',result=?,payload='{}',finished_at=NOW() WHERE id=?",[$e->getMessage(),$j['id']]);}
