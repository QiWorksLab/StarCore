<?php
namespace app\service;
use PDO;
use RuntimeException;
class Core {
 const BASE='/www/wwwroot/StarCore';
 private static ?PDO $db=null;
 static function conf():array {return json_decode(file_get_contents(self::BASE.'/data/config.json'),true);}
 static function db():PDO {
  if(!self::$db){$c=self::conf();self::$db=new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
  return self::$db;
 }
 static function sql(string $s,array $p=[]){$q=self::db()->prepare($s);$q->execute($p);return $q;}
 static function rows(string $s,array $p=[]):array{return self::sql($s,$p)->fetchAll();}
 static function one(string $s,array $p=[]):array{return self::sql($s,$p)->fetch()?:[];}
 static function setting(string $k,$default=null){$r=self::one('SELECT value FROM settings WHERE name=?',[$k]);return $r?json_decode($r['value'],true):$default;}
 static function set(string $k,$v):void{self::sql('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$k,json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}
 static function json($v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
 static function crypt(string $v):string{$iv=random_bytes(12);$ct=openssl_encrypt($v,'aes-256-gcm',hex2bin(self::conf()['key']),OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$ct);}
 static function decrypt(string $v):string{$v=base64_decode($v,true);$r=openssl_decrypt(substr($v,28),'aes-256-gcm',hex2bin(self::conf()['key']),OPENSSL_RAW_DATA,substr($v,0,12),substr($v,12,16));if($r===false)throw new RuntimeException('凭据解密失败');return $r;}
 static function redis():\Redis{$r=new \Redis();$r->connect('127.0.0.1',6379,2);$p=self::conf()['redis_password'];if($p)$r->auth($p);$r->select(1);return $r;}
 static function bot(int $id):array{$b=self::one('SELECT * FROM bots WHERE id=?',[$id]);if(!$b)throw new RuntimeException('机器人不存在');$b['config']=json_decode($b['config'],true);$b['meta']=json_decode($b['meta'],true);return $b;}
 static function project(int $id):array{$p=self::one('SELECT * FROM projects WHERE id=?',[$id]);if(!$p)throw new RuntimeException('项目不存在');$p['config']=json_decode($p['config'],true);return $p;}
 static function publicBot(array $b):array{unset($b['token'],$b['hook_key']);$b['config']=is_array($b['config'])?$b['config']:json_decode($b['config'],true);$b['meta']=is_array($b['meta'])?$b['meta']:json_decode($b['meta'],true);unset($b['config']['secret_token']);return $b;}
 static function telegram(string $token,string $method,array $args=[]){
  $h=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
  curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>self::json($args),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>($args['timeout']??0)+20]);
  $raw=curl_exec($h);$err=curl_error($h);curl_close($h);
  if($raw===false)throw new RuntimeException('Telegram 连接失败：'.str_replace($token,'[TOKEN]',$err));
  $j=json_decode($raw,true);
  if(!($j['ok']??false)){
   $code=(int)($j['error_code']??0);
   if($code===401)throw new RuntimeException('Telegram 拒绝此 Bot Token（401 Unauthorized）：Token 无效或已失效，请从 BotFather 重新复制完整 Token；若曾重置，请使用最新 Token。');
   throw new RuntimeException('Telegram：'.($j['description']??'响应无效').(isset($j['parameters']['retry_after'])?'，请在 '.$j['parameters']['retry_after'].' 秒后重试':''));
  }
  return $j['result'];
 }
 static function sync(int $id):array{
  $b=self::bot($id);$t=self::decrypt($b['token']);$meta=self::telegram($t,'getMe');$meta['commands']=self::telegram($t,'getMyCommands');$meta['menu_button']=self::telegram($t,'getChatMenuButton');$meta['webhook']=self::telegram($t,'getWebhookInfo');
  foreach(['getMyDescription'=>'description','getMyShortDescription'=>'short_description'] as $method=>$key){try{$meta[$key]=self::telegram($t,$method);}catch(\Throwable $e){$meta[$key]=['error'=>$e->getMessage()];}}
  try{$photos=self::telegram($t,'getUserProfilePhotos',['user_id'=>$meta['id'],'limit'=>1]);$meta['avatar_file_id']=$photos['photos'][0][0]['file_id']??null;}catch(\Throwable $e){}
  self::sql('UPDATE bots SET meta=?,synced_at=NOW(),sync_error=NULL WHERE id=?',[self::json($meta),$id]);return self::publicBot(self::bot($id));
 }
 static function log(int $id,string $event,string $detail='',?int $actor=null):void{self::sql('INSERT INTO operations(bot_id,event,detail,actor_id,created_at) VALUES(?,?,?,?,NOW())',[$id?:null,$event,$detail,$actor]);}
 static function enqueue(string $type,array $payload,?int $actor=null):int{self::sql('INSERT INTO jobs(type,payload,state,actor_id,created_at) VALUES(?,?,?, ?,NOW())',[$type,self::json($payload),'pending',$actor]);return (int)self::db()->lastInsertId();}
 static function safePath(string $path,bool $exists=true):string{
  if(str_contains($path,"\0")||!str_starts_with($path,'/www/wwwroot/'))throw new RuntimeException('路径必须位于 /www/wwwroot/');
  $parts=explode('/',$path);if(in_array('..',$parts,true))throw new RuntimeException('禁止路径穿越');
  $root='/www/wwwroot';$cursor=$root;
  foreach(array_slice($parts,3) as $part){if($part===''||$part==='.')continue;$cursor.='/'.$part;if(is_link($cursor))throw new RuntimeException('禁止通过符号链接操作文件');}
  $real=realpath($path);
  if($exists&&!$real)throw new RuntimeException('路径不存在');
  if(!$real){$parent=realpath(dirname($path));if(!$parent)throw new RuntimeException('父目录不存在');$real=$parent.'/'.basename($path);}
  if(!str_starts_with($real,$root.'/'))throw new RuntimeException('路径越界');
  if($real===self::BASE||str_starts_with($real,self::BASE.'/'))throw new RuntimeException('平台安装目录受保护');
  return $real;
 }
 static function workPath(int $id,string $rel=''):string{
  $root=self::BASE.'/projects/'.$id.'/draft';if(str_contains($rel,"\0")||str_starts_with($rel,'/')||in_array('..',explode('/',$rel),true))throw new RuntimeException('文件路径无效');
  $p=$root.($rel!==''?'/'.$rel:'');$cur=$root;foreach(explode('/',$rel) as $s){if(!$s)continue;$cur.='/'.$s;if(is_link($cur))throw new RuntimeException('项目符号链接不允许编辑');}
  $r=realpath($p);if($r&&!($r===$root||str_starts_with($r,$root.'/')))throw new RuntimeException('文件越界');return $p;
 }
 static function copyTree(string $src,string $dst):void{
  if(is_link($src))throw new RuntimeException('不接受符号链接');
  if(is_file($src)){if(!copy($src,$dst))throw new RuntimeException('复制文件失败');return;}
  if(!is_dir($dst)&&!mkdir($dst,0750,true))throw new RuntimeException('创建目录失败');
  foreach(scandir($src) as $n){if(in_array($n,['.','..','.git','venv','.venv','vendor','__pycache__','.starcore-runtime.json','.starcore-deps.json','.starcore-bot'],true))continue;self::copyTree($src.'/'.$n,$dst.'/'.$n);}
 }
 static function sourceFiles(string $root,string $prefix=''):array{ $out=[];foreach(scandir($root) as $n){if(in_array($n,['.','..','.git','venv','.venv','vendor','__pycache__','.starcore-runtime.json','.starcore-deps.json','.starcore-bot'],true))continue;$p=$root.'/'.$n;if(is_link($p))throw new RuntimeException('原目录包含符号链接，不能同步');if(is_dir($p))$out=array_merge($out,self::sourceFiles($p,$prefix.$n.'/'));else $out[]=$prefix.$n;}return $out;}
 static function mirrorSource(string $from,string $to):void{ $src=self::sourceFiles($from);$dst=self::sourceFiles($to);self::copyTree($from,$to);foreach(array_diff($dst,$src) as $rel){$p=$to.'/'.$rel;if(is_file($p)&&!unlink($p))throw new RuntimeException('无法同步删除文件：'.$rel);}}
 static function removeTree(string $p):void{
  if(is_link($p)||is_file($p)){if(!unlink($p))throw new RuntimeException('删除失败');return;}
  if(!is_dir($p))return;foreach(scandir($p) as $n){if($n==='.'||$n==='..')continue;self::removeTree($p.'/'.$n);}if(!rmdir($p))throw new RuntimeException('删除目录失败');
 }
 static function lockProject(int $id,callable $f){$h=fopen(self::BASE.'/projects/'.$id.'/.lock','c');if(!flock($h,LOCK_EX|LOCK_NB)){fclose($h);throw new RuntimeException('项目正在操作，请稍后再试');}try{return $f();}finally{flock($h,LOCK_UN);fclose($h);}}
 static function listIds($v):array{if(is_string($v))$v=preg_split('/[\s,，]+/',trim($v),-1,PREG_SPLIT_NO_EMPTY);if(!is_array($v))throw new RuntimeException('名单格式无效');foreach($v as &$n){$n=(string)$n;if(!preg_match('/^-?\d+$/',$n))throw new RuntimeException('ID 必须为整数');}return array_values(array_unique($v));}
 static function defaults():array{return ['mode'=>'polling','scopes'=>['private','group','supergroup','channel'],'allow'=>[],'deny'=>[],'maintenance'=>false,'maintenance_text'=>'','exempt'=>[],'auto_restart'=>true,'auto_start'=>true,'allowed_updates'=>['message','edited_message','channel_post','edited_channel_post','callback_query'],'max_connections'=>40,'webhook_url'=>'','secret_token'=>bin2hex(random_bytes(24)),'maintenance_on'=>null,'maintenance_off'=>null];}
 static function allowed(array $cfg,array $u,array $global):string{
  $m=$u['message']??$u['edited_message']??$u['channel_post']??$u['edited_channel_post']??$u['callback_query']['message']??$u['my_chat_member']??$u['chat_member']??$u['chat_join_request']??null;
  $uid=(string)($u['callback_query']['from']['id']??$m['from']['id']??'');$chat=$m['chat']??null;
  if($chat){if(!in_array($chat['type']??'', $cfg['scopes'],true))return 'deny';$id=(string)$chat['id'];if(in_array($id,$cfg['deny'],true))return 'deny';if($cfg['allow']&&!in_array($id,$cfg['allow'],true))return 'deny';}
  if($cfg['maintenance']&&!in_array($uid,array_merge($global,$cfg['exempt']),true))return 'maintenance';
  return 'business';
 }
 static function runtimeOptions():array{
  if(PHP_SAPI!=='cli')return self::setting('runtimes',['php'=>[],'python'=>[],'go'=>[]]);
  $r=['php'=>[],'python'=>[],'go'=>[]];foreach(glob('/www/server/php/*/bin/php') as $x)$r['php'][]=$x;
  foreach(['/usr/bin/php','/usr/bin/python3','/usr/local/bin/python3','/usr/local/go/bin/go','/usr/bin/go'] as $x)if(is_executable($x)){foreach(['php','python','go'] as $l)if(str_contains(basename($x),$l))$r[$l][]=$x;}
  return $r;
 }
}
