<?php
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/app/service/Core.php';
use app\service\Core as C;
$id=(int)($argv[2]??0);
switch($argv[1]){
case 'add':
 $cfg=C::defaults();$cfg['mode']='webhook';$tg=900000000000+random_int(1000,999999);
 C::sql('INSERT INTO bots(tg_id,token,hook_key,config,meta,created_at) VALUES(?,?,?,?,?,NOW())',[$tg,C::crypt('999999999999:STARCORE_TEST_NO_ACCESS'),bin2hex(random_bytes(24)),C::json($cfg),C::json(['id'=>$tg,'first_name'=>'验收临时机器人','username'=>'starcore_fixture'])]);
 $id=(int)C::db()->lastInsertId();echo C::json(['id'=>$id,'hook_key'=>C::bot($id)['hook_key'],'secret'=>$cfg['secret_token']]);break;
case 'get':$b=C::bot($id);echo C::json(['id'=>$id,'state'=>$b['state'],'desired'=>$b['desired'],'failures'=>$b['failures'],'pid'=>$b['pid'],'config'=>$b['config']]);break;
case 'rules':
 $c=C::defaults();
 $u=['message'=>['chat'=>['id'=>-1,'type'=>'group'],'from'=>['id'=>42],'text'=>'x']];
 assert(C::allowed($c,$u,[])==='business');$c['deny']=['-1'];assert(C::allowed($c,$u,[])==='deny');$c['deny']=[];$c['allow']=['-2'];assert(C::allowed($c,$u,[])==='deny');$c['allow']=[];$c['scopes']=['private'];assert(C::allowed($c,$u,[])==='deny');$c['scopes']=['group'];$c['maintenance']=true;assert(C::allowed($c,$u,[])==='maintenance');assert(C::allowed($c,$u,['42'])==='business');$c['exempt']=['42'];assert(C::allowed($c,$u,[])==='business');echo "routing checks passed";break;
}
