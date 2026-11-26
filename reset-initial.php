<?php
declare(strict_types=1);
// Only --yes executes restoration. --check does not stop services or clear data.
const ROOT='/www/wwwroot/StarCore';
const RECOVERY='/www/backup/StarCore-initial';
function command(array $args):string {
 $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($p))throw new RuntimeException('无法执行系统命令');
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 $status=proc_close($p);if($status!==0)throw new RuntimeException('命令执行失败：'.basename($args[0]).' '.trim($err));
 return trim($out);
}
function status(array $args):string {
 try{return command($args);}catch(Throwable $e){return 'inactive / unavailable';}
}
function removeInside(string $path):void {
 if(!str_starts_with($path,ROOT.'/'))throw new RuntimeException('拒绝删除站点外路径');
 if(is_link($path)||is_file($path)){if(!unlink($path))throw new RuntimeException('文件删除失败：'.$path);return;}
 if(!is_dir($path))return;
 foreach(scandir($path) as $n)if($n!=='.'&&$n!=='..')removeInside($path.'/'.$n);
 if(!rmdir($path))throw new RuntimeException('目录删除失败：'.$path);
}
function saveJson(string $p,array $v,int $mode=0640):void{
 if(file_put_contents($p,json_encode($v,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('配置写入失败');
 chmod($p,$mode);
}
function clearRedis(Redis $redis):void {
 $cursor=null;
 do{
  $keys=$redis->scan($cursor,'sc:*',500);
  if($keys)$redis->del(...$keys);
 }while($cursor!==0);
}
$mode=$argv[1]??'--help';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!in_array($mode,['--yes','--check'],true)){
 echo "StarCore 恢复初始版\n";
 echo "检查，不清空：sudo ".ROOT."/reset-initial.sh --check\n";
 echo "一键执行恢复：sudo ".ROOT."/reset-initial.sh --yes\n";
 echo "执行后：恢复首版代码，永久清空站点内项目/上传/日志、全部专用数据库对象与数据、现有 Git 历史、本站 Redis 键。\n";
 echo "自增编号重新初始化；统一 utf8mb4 / utf8mb4_unicode_ci；生成新 admin 密码；旧登录失效。\n";
 echo "保留连接凭据、CLI 配置、宝塔根目录 .user.ini 和恢复工具；不删除站点外业务原目录，也不撤销 Telegram 官方 Token。\n";
 exit;
}
$started=false;
try{
 if(posix_geteuid()!==0)throw new RuntimeException('必须以 root 或 sudo 执行');
 if(realpath(ROOT)!==ROOT||is_link(ROOT))throw new RuntimeException('站点根目录不符合恢复目标');
 $manifest=json_decode(file_get_contents(RECOVERY.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 $archive=RECOVERY.'/initial-source.tar.gz';
 if(($manifest['project_root']??'')!==ROOT||!hash_equals($manifest['sha256'],hash_file('sha256',$archive)))throw new RuntimeException('恢复基线目标或校验和不正确');
 foreach(explode("\n",command(['/bin/tar','-tzf',$archive])) as $name){
  if(str_starts_with($name,'/')||in_array('..',explode('/',$name),true))throw new RuntimeException('恢复包包含不安全路径');
  if(preg_match('#^(data|logs|projects|\.git)(/|$)#',$name))throw new RuntimeException('恢复包不应包含运行数据或 Git 历史');
 }
 $cfg=json_decode(file_get_contents(ROOT.'/data/config.json'),true,512,JSON_THROW_ON_ERROR);
 if($cfg['db_name']!==$manifest['database']||$cfg['db_name']!=='StarCore')throw new RuntimeException('数据库与恢复基线不一致，拒绝清空');
 $cli=file_get_contents(ROOT.'/data/cli.ini');
 $pdo=new PDO('mysql:host='.$cfg['db_host'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $grants=$pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
 $canReset=false;$quoted=chr(96).'StarCore'.chr(96).'.*';
 foreach($grants as $g)if(str_contains($g,'ALL PRIVILEGES')&&(str_contains($g,'*.*')||str_contains($g,$quoted)||str_contains($g,'StarCore.*')))$canReset=true;
 if(!$canReset)throw new RuntimeException('数据库账号缺少完整重建权限');
 $redis=new Redis();$redis->connect('127.0.0.1',(int)(getenv('REDIS_PORT') ?: 6380),3);if($cfg['redis_password'])$redis->auth($cfg['redis_password']);$redis->select(7);$redis->ping();
 $vhosts=[];
 foreach(glob('/www/server/panel/vhost/nginx/*.conf') as $file){
  if(preg_match('#^\s*root\s+/www/wwwroot/StarCore(?:/app/public)?;#m',file_get_contents($file)))$vhosts[]=$file;
 }
 if(count($vhosts)!==1)throw new RuntimeException('无法唯一确定本站 Nginx 配置');
 $vhost=$vhosts[0];$nginx='/www/server/nginx/sbin/nginx';command([$nginx,'-t']);
 echo "目标目录：".ROOT."\n目标数据库：".$cfg['db_name']."\n";
 echo "恢复源码提交：".$manifest['git_commit']."，ThinkPHP ".$manifest['framework']."\n";
 echo "排序规则：utf8mb4_unicode_ci；数据及自增编号重新初始化。\n";
 echo "当前 StarCore 服务：".status(['systemctl','is-active','starcore.service'])."\n";
 echo "旧守护服务：".status(['systemctl','is-active','starcore-bot-worker.service'])."\n";
 if($mode==='--check'){echo "检查通过。没有停止服务、修改现有程序或清空任何数据。\n";exit;}
 $lock=fopen(RECOVERY.'/reset.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('已有恢复任务运行');
 $gate=RECOVERY.'/resetting';$text=file_get_contents($vhost);$tag='# STARCORE_RESET_GATE';
 $gateBlock="\n    ".$tag."\n    if (-f ".RECOVERY."/resetting) { return 503; }\n";
 if(!str_contains($text,$tag)){
  $gateBlock="\n    ".$tag."\n    if (-f ".RECOVERY."/resetting) { return 503; }\n";
  $text=preg_replace('/(server\s*\{)/','$1'.$gateBlock,$text,1,$count);if($count!==1)throw new RuntimeException('不能设置本站维护入口');
  if(file_put_contents($vhost,$text)===false)throw new RuntimeException('不能写入本站 Nginx 配置');
 }
 file_put_contents($gate,"resetting\n");chmod(RECOVERY,0711);chmod($gate,0644);
 command([$nginx,'-t']);command([$nginx,'-s','reload']);$started=true;
 foreach(['starcore.service','starcore-bot-worker.service'] as $unit){
  if(!str_contains(status(['systemctl','show',$unit,'--property=ExecStart','--value']),ROOT.'/'))continue;
  command(['systemctl','stop',$unit]);
  if($unit==='starcore-bot-worker.service')command(['systemctl','disable',$unit]);
 }
 // Stop only standalone PHP CLI processes whose arguments reference this site.
 foreach(glob('/proc/[0-9]*/cmdline') as $file){
  $pid=(int)basename(dirname($file));if($pid===getmypid())continue;
  $raw=@file_get_contents($file);if(!$raw)continue;$args=explode("\0",$raw);
  if(!str_starts_with(basename($args[0]??''),'php'))continue;
  foreach(array_slice($args,1) as $arg)if(str_starts_with($arg,ROOT.'/')){
   @posix_kill($pid,SIGTERM);$until=microtime(true)+3;
   while(is_dir('/proc/'.$pid)&&microtime(true)<$until)usleep(50000);
   if(is_dir('/proc/'.$pid))@posix_kill($pid,SIGKILL);
   break;
  }
 }
 // Close existing connections owned by the dedicated application account.
 $selfConnection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
 foreach($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $connection){
  if((int)$connection['Id']!==$selfConnection&&$connection['User']===$cfg['db_user']){
   try{$pdo->exec('KILL CONNECTION '.(int)$connection['Id']);}catch(PDOException $e){}
  }
 }
 echo "已暂停本站 HTTP 访问并停止机器人守护。\n";
 foreach(scandir(ROOT) as $name){
  if(in_array($name,['.','..','.user.ini','reset-initial.sh','reset-initial.php','reset-admin-password.php'],true))continue;
  removeInside(ROOT.'/'.$name);
 }
 mkdir(ROOT.'/data',0750,true);$cfg['key']=bin2hex(random_bytes(32));$cfg['base_url']='';
 saveJson(ROOT.'/data/config.json',$cfg);file_put_contents(ROOT.'/data/cli.ini',$cli);chmod(ROOT.'/data/cli.ini',0640);touch(ROOT.'/data/db.ready');
 command(['/bin/tar','-xzf',$archive,'-C',ROOT,'--no-same-owner']);
 foreach(['projects','logs','data/home','data/go-cache','data/go-mod','app/runtime'] as $d)if(!is_dir(ROOT.'/'.$d))mkdir(ROOT.'/'.$d,0750,true);
 // DROP/CREATE removes tables, views, triggers, procedures, events and sequences.
 $pdo->exec('DROP DATABASE IF EXISTS `StarCore`');
 $pdo->exec('CREATE DATABASE `StarCore` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 clearRedis($redis);
 command(['/www/server/php/84/bin/php','-c',ROOT.'/data/cli.ini',ROOT.'/app/bin/init.php']);
 $db=new PDO('mysql:host='.$cfg['db_host'].';dbname=StarCore;charset=utf8mb4',$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 // Empty business tables start again at 1; the initial admin keeps its seeded ID.
 foreach(['projects','bots','jobs','releases','operations'] as $table){
  $quoted=chr(96).$table.chr(96);
  if((int)$db->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn()!==0)throw new RuntimeException('初始业务表非空：'.$table);
  $db->exec('ALTER TABLE '.$quoted.' AUTO_INCREMENT=1');
 }
 $next=$db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA='StarCore' AND TABLE_NAME='bots'")->fetchColumn();
 if((int)$next!==1)throw new RuntimeException('机器人自增编号重置失败');
 echo "业务数据已清空；机器人、项目、任务、发布、操作记录编号从 1 重新开始。\n";
 $q=$db->prepare('UPDATE users SET session_version=?');$q->execute([random_int(100000,2147483647)]);
 $n=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='StarCore' AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'")->fetchColumn();
 if($n)throw new RuntimeException('库表排序规则核验失败');
 foreach(['data','logs','projects','app/runtime','app/public'] as $d)command(['chown','-R','www:www',ROOT.'/'.$d]);
 command(['chown','root:root',ROOT.'/data/initial-login.txt']);chmod(ROOT.'/data/initial-login.txt',0600);
 command(['chown','root:www',ROOT.'/data/cli.ini']);chmod(ROOT.'/data/cli.ini',0640);
 command(['systemctl','start','starcore.service']);
 if(command(['systemctl','is-active','starcore.service'])!=='active')throw new RuntimeException('守护服务未恢复');
 // Remove the temporary Nginx gate before restricting the recovery directory again.
 $clean=str_replace($gateBlock,'',file_get_contents($vhost));file_put_contents($vhost,$clean);
 command([$nginx,'-t']);command([$nginx,'-s','reload']);
 if(!unlink($gate))throw new RuntimeException('不能解除本站维护入口');
 chmod(RECOVERY,0700);
 echo "恢复完成。站点已重新开放；旧账号、Token、项目、发布、日志、Redis 缓存和旧登录均已清空或失效。\n";
 echo "新管理员凭据：\n".file_get_contents(ROOT.'/data/initial-login.txt');
 echo "第一次登录会重新识别平台域名。\n";
}catch(Throwable $e){
 fwrite(STDERR,"恢复工具失败：".$e->getMessage()."\n");
 if($started)fwrite(STDERR,"本站保持维护状态，未自动开放部分恢复的数据。修复原因后可重新执行 --yes。\n");
 exit(1);
}
