<?php
namespace app\service;
use RuntimeException;
class Manager {
 private array $processes=[];
 private array $retry=[];
 private array $sync=[];
 private array $tasks=[];
 private bool $alive=true;
 function writeLog(int $id,string $s):void{$d=Core::BASE.'/logs/'.$id;if(!is_dir($d))mkdir($d,0750,true);file_put_contents($d.'/system.'.date('Y-m-d').'.log',date('c').' '.$s."\n",FILE_APPEND|LOCK_EX);}
 function start(int $id,bool $reset=true):void{
  $b=Core::bot($id);if(!$b['project_id'])throw new RuntimeException('请先绑定项目');$p=Core::project((int)$b['project_id']);if(!$p['active_version']&&!Core::setting('candidate:'.$p['id']))throw new RuntimeException('项目尚未发布，请先发布代码');
  if(isset($this->processes[$id]))return;
  if($b['config']['mode']==='polling'&&!empty($b['meta']['webhook']['url']))$this->setMode($b,$b['config'],Core::decrypt($b['token']),false);
  if($reset)Core::sql('UPDATE bots SET failures=0 WHERE id=?',[$id]);
  $d=Core::BASE.'/logs/'.$id;if(!is_dir($d))mkdir($d,0750,true);
  Core::redis()->del('sc:ready:'.$id,'sc:child:'.$id);
  $proc=proc_open(['/www/server/php/84/bin/php','-c',Core::BASE.'/data/cli.ini','-d','display_errors=0',Core::BASE.'/app/bin/runner.php',(string)$id],[0=>['file','/dev/null','r'],1=>['file',$d.'/stdout.log','a'],2=>['file',$d.'/stderr.log','a']],$pipes,Core::BASE.'/app');
  if(!is_resource($proc))throw new RuntimeException('启动进程失败');$status=proc_get_status($proc);$this->processes[$id]=['proc'=>$proc,'pid'=>$status['pid'],'start'=>time(),'healthy'=>false];
  Core::sql("UPDATE bots SET state='starting',pid=?,started_at=NOW(),last_error=NULL WHERE id=?",[$status['pid'],$id]);$this->writeLog($id,'启动 PID '.$status['pid']);
 }
 function stop(int $id,string $state='stopped'):void{
  if(isset($this->processes[$id])){$x=$this->processes[$id];posix_kill($x['pid'],SIGTERM);$until=microtime(true)+3;while(proc_get_status($x['proc'])['running']&&microtime(true)<$until)usleep(50000);
   if(proc_get_status($x['proc'])['running']){posix_kill(-$x['pid'],SIGKILL);proc_terminate($x['proc'],SIGKILL);}proc_close($x['proc']);unset($this->processes[$id]);}
  unset($this->retry[$id]);Core::redis()->del('sc:ready:'.$id,'sc:child:'.$id);Core::sql('UPDATE bots SET state=?,pid=NULL WHERE id=?',[$state,$id]);$this->writeLog($id,'停止进程');
 }
 function command(array $job):void{
  $v=json_decode($job['payload'],true);$id=(int)($v['id']??0);$actor=$job['actor_id']?(int)$job['actor_id']:null;
  switch($job['type']){
   case 'start':Core::sql("UPDATE bots SET desired='running' WHERE id=?",[$id]);$this->start($id);Core::log($id,'启动','',$actor);break;
   case 'stop':Core::sql("UPDATE bots SET desired='stopped' WHERE id=?",[$id]);$this->stop($id);Core::log($id,'停止','',$actor);break;
   case 'restart':$this->stop($id);Core::sql("UPDATE bots SET desired='running' WHERE id=?",[$id]);$this->start($id);Core::log($id,'重启','',$actor);break;
   case 'bind':$b=Core::bot($id);$this->stop($id,$v['project_id']?'stopped':'unbound');Core::sql("UPDATE bots SET project_id=?,desired='stopped' WHERE id=?",[$v['project_id'],$id]);Core::log($id,$v['project_id']?'绑定项目':'解绑项目','',$actor);break;
   case 'delete':$this->stop($id);Core::log($id,'删除机器人','',$actor);Core::redis()->del('sc:updates:'.$id,'sc:offset:'.$id);Core::sql('DELETE FROM bots WHERE id=?',[$id]);break;
   case 'sync':Core::sync($id);break;
   case 'profile':$b=Core::bot($id);Core::telegram(Core::decrypt($b['token']),$v['method'],$v['args']);Core::sync($id);Core::log($id,'修改资料',$v['method'],$actor);break;
   case 'photo':
    $b=Core::bot($id);$t=Core::decrypt($b['token']);$h=curl_init('https://api.telegram.org/bot'.$t.'/setMyProfilePhoto');$media=['type'=>'static','photo'=>'attach://avatar'];
    curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>40,CURLOPT_POSTFIELDS=>['photo'=>Core::json($media),'avatar'=>new \CURLFile($v['path'],$v['mime'],'avatar')]]);$raw=curl_exec($h);curl_close($h);unlink($v['path']);$res=json_decode($raw,true);if(!($res['ok']??false))throw new RuntimeException($res['description']??'头像修改失败');Core::sync($id);Core::log($id,'修改头像','',$actor);break;
   case 'mode':
    $b=Core::bot($id);$t=Core::decrypt($b['token']);$old=$b['config'];$this->stop($id);
    try{$this->setMode($b,$v['config'],$t,$v['drop']);Core::sql('UPDATE bots SET config=? WHERE id=?',[Core::json($v['config']),$id]);}
    catch(\Throwable $e){try{$this->setMode($b,$old,$t,false);}catch(\Throwable $restore){Core::sql('UPDATE bots SET last_error=? WHERE id=?',['旧 Webhook 恢复失败：'.$restore->getMessage(),$id]);throw new RuntimeException($e->getMessage().'；旧配置恢复失败，请人工检查');}if($b['desired']==='running')$this->start($id);throw $e;}
    if($b['desired']==='running')$this->start($id);Core::sync($id);Core::log($id,'切换消息模式',$v['config']['mode'],$actor);break;
   case 'token':
    $b=Core::bot($id);$new=Core::decrypt($v['token']);Core::telegram($new,'getMe');$this->setMode($b,$b['config'],$new,false);$this->stop($id);Core::sql('UPDATE bots SET token=? WHERE id=?',[$v['token'],$id]);if($b['desired']==='running')$this->start($id);Core::sync($id);Core::log($id,'更换 Token','',$actor);break;
   case 'import':
    $p=Core::project($id);$src=Core::safePath($p['source_path']);Core::lockProject($id,function()use($src,$id){Core::copyTree($src,Core::BASE.'/projects/'.$id.'/draft');});
    $this->detectEntry($id);break;
   case 'extract':
    Core::lockProject($id,function()use($id,$v){$p=Core::workPath($id,$v['path']);$z=new \ZipArchive;if($z->open($p)!==true)throw new RuntimeException('ZIP 无法打开');$total=0;
     for($i=0;$i<$z->numFiles;$i++){$info=$z->statIndex($i);$total+=$info['size'];if($total>536870912)throw new RuntimeException('解压内容超过 512MB');$n=$info['name'];if(str_contains($n,'\\')||str_starts_with($n,'/')||in_array('..',explode('/',$n),true))throw new RuntimeException('ZIP 包含越界路径');$opsys=0;$attr=0;$z->getExternalAttributesIndex($i,$opsys,$attr);if(($attr>>16&0170000)===0120000)throw new RuntimeException('ZIP 不允许符号链接');}
     for($i=0;$i<$z->numFiles;$i++){$n=$z->getNameIndex($i);$dst=Core::workPath($id,rtrim($n,'/'));if(str_ends_with($n,'/')){if(!is_dir($dst))mkdir($dst,0750,true);}else{if(!is_dir(dirname($dst)))mkdir(dirname($dst),0750,true);$h=$z->getStream($n);$out=fopen($dst,'w');stream_copy_to_stream($h,$out);fclose($out);fclose($h);}}$z->close();unlink($p);});$this->detectEntry($id);break;
   case 'project_delete':
    $p=Core::project($id);foreach(Core::rows('SELECT id FROM bots WHERE project_id=?',[$id]) as $b){$this->stop((int)$b['id'],'unbound');Core::sql("UPDATE bots SET project_id=NULL,desired='stopped' WHERE id=?",[$b['id']]);}
    if($v['delete_source']&&$p['source_kind']==='direct')Core::removeTree(Core::safePath($p['source_path']));
    Core::sql('DELETE FROM projects WHERE id=?',[$id]);Core::removeTree(Core::BASE.'/projects/'.$id);break;
   default:throw new RuntimeException('未知任务');
  }
 }
 function detectEntry(int $id):void{$p=Core::project($id);$root=Core::BASE.'/projects/'.$id.'/draft';$choices=[];foreach(['bot.py'=>'python','main.py'=>'python','bot.php'=>'php','index.php'=>'php','main.go'=>'go'] as $n=>$lang)if(is_file($root.'/'.$n))$choices[]=[$n,$lang];
  if(count($choices)===1){[$n,$lang]=$choices[0];$r=Core::runtimeOptions()[$lang];if($r){$p['config']['language']=$lang;$p['config']['entry']=$n;$p['config']['runtime']=$r[0];Core::sql('UPDATE projects SET config=? WHERE id=?',[Core::json($p['config']),$id]);}}
  elseif(count($choices)!==1)Core::sql('UPDATE projects SET release_error=? WHERE id=?',['自动识别入口不确定，请在项目设置中选择语言、版本和入口文件',$id]);
 }
 function setMode(array $b,array $cfg,string $t,bool $drop):void{
  if($cfg['mode']==='polling')Core::telegram($t,'deleteWebhook',['drop_pending_updates'=>$drop]);
  else{$url=$cfg['webhook_url']?:rtrim(Core::setting('base_url',''),'/').'/hook/'.$b['hook_key'];if(!str_starts_with($url,'https://'))throw new RuntimeException('请先设置平台 HTTPS 地址');
   Core::telegram($t,'setWebhook',['url'=>$url,'secret_token'=>$cfg['secret_token'],'max_connections'=>$cfg['max_connections'],'allowed_updates'=>$cfg['allowed_updates'],'drop_pending_updates'=>$drop]);}
 }
 function runCommand(array $cmd,string $cwd,int $limit=600):void{
  if(!is_dir($cwd)||!is_readable($cwd))throw new RuntimeException('构建目录不存在或无法读取');
  if(!isset($cmd[0])||!is_file($cmd[0])||!is_executable($cmd[0]))throw new RuntimeException('构建程序不存在或缺少执行权限：'.($cmd[0]??'未知'));
  $des=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']];$env=getenv();$env['COMPOSER_ALLOW_SUPERUSER']='0';$env['HOME']=Core::BASE.'/data/home';$env['GOCACHE']=Core::BASE.'/data/go-cache';$env['GOMODCACHE']=Core::BASE.'/data/go-mod';
  $proc=proc_open($cmd,$des,$pipes,$cwd,$env);if(!is_resource($proc))throw new RuntimeException('无法执行构建');foreach($pipes as $h)stream_set_blocking($h,false);$output='';$start=time();$exit=-1;
  while(true){foreach($pipes as $h)$output.=stream_get_contents($h);$output=substr($output,-12000);$s=proc_get_status($proc);if(!$s['running']){$exit=$s['exitcode'];break;}if(time()-$start>$limit){proc_terminate($proc,SIGKILL);throw new RuntimeException('构建超时');}$this->supervise();usleep(100000);}
  foreach($pipes as $h){$output.=stream_get_contents($h);fclose($h);}proc_close($proc);if($exit!==0)throw new RuntimeException('构建失败：'.substr($output,-6000));
 }
 function build(array $p,string $dir):void{
  $c=$p['config'];$entry=$c['entry'];if(!is_file($dir.'/'.$entry)||is_link($dir.'/'.$entry))throw new RuntimeException('入口文件不存在');$rt=$c['runtime'];$lang=$c['language'];
  $fingerprint=$rt.'|'.$lang;foreach(['requirements.txt','pyproject.toml','composer.json','composer.lock','go.mod','go.sum'] as $f)if(is_file($dir.'/'.$f))$fingerprint.=$f.':'.hash_file('sha256',$dir.'/'.$f);
  $depkey=hash('sha256',$fingerprint);$depbase=Core::BASE.'/projects/'.$p['id'].'/deps/'.$depkey;

  if($lang==='python'){
   $this->runCommand([$rt,'-m','venv',$dir.'/.venv'],$dir);
   $site=glob($dir.'/.venv/lib/python*/site-packages')[0]??null;
   if(is_dir($depbase.'/site-packages')&&$site)Core::copyTree($depbase.'/site-packages',$site);
   elseif(is_file($dir.'/requirements.txt'))$this->runCommand([$dir.'/.venv/bin/python','-m','pip','install','-r','requirements.txt'],$dir);
   elseif(is_file($dir.'/pyproject.toml'))$this->runCommand([$dir.'/.venv/bin/python','-m','pip','install','.'],$dir);
   if($site&&!is_dir($depbase.'/site-packages'))Core::copyTree($site,$depbase.'/site-packages');
   $this->runCommand([$dir.'/.venv/bin/python','-m','py_compile',$entry],$dir,30);
  }elseif($lang==='php'){
   $this->runCommand([$rt,'-l',$entry],$dir,30);if(is_dir($depbase.'/vendor'))Core::copyTree($depbase.'/vendor',$dir.'/vendor');elseif(is_file($dir.'/composer.json')){$this->runCommand([$rt,'-d','error_reporting=22527',(is_file('/usr/local/bin/composer')?'/usr/local/bin/composer':'/usr/bin/composer'),'install','--no-interaction','--prefer-dist','--no-dev'],$dir);if(is_dir($dir.'/vendor'))Core::copyTree($dir.'/vendor',$depbase.'/vendor');}
  }elseif($lang==='go'){
   if(is_file($dir.'/go.mod')){$this->runCommand([$rt,'mod','download'],$dir);$this->runCommand([$rt,'build','-o',$dir.'/.starcore-bot','.'],$dir);}
   else $this->runCommand([$rt,'build','-o',$dir.'/.starcore-bot',$entry],$dir);
  }
 }
 function publish(array $rel):void{
  $id=(int)$rel['project_id'];Core::lockProject($id,function()use($id,$rel){
   $p=Core::project($id);$old=$p['active_version'];$switched=false;$root=Core::BASE.'/projects/'.$id;$dir=$root.'/versions/'.$rel['version'];$bots=Core::rows('SELECT * FROM bots WHERE project_id=?',[$id]);
   if(!Core::sql("UPDATE releases SET state='building' WHERE id=? AND state='pending'",[$rel['id']])->rowCount())return;Core::sql("UPDATE projects SET release_state='building',release_error=NULL WHERE id=?",[$id]);
   try{
    if(is_file($dir.'/.starcore-runtime.json'))$p['config']=json_decode(file_get_contents($dir.'/.starcore-runtime.json'),true);$this->build($p,$dir);Core::sql("UPDATE releases SET state='checking' WHERE id=?",[$rel['id']]);Core::sql("UPDATE projects SET release_state='checking' WHERE id=?",[$id]);
    $switched=true;foreach($bots as $b)$this->stop((int)$b['id']);
    Core::set('candidate:'.$id,$rel['version']);
    foreach($bots as $b)if($b['desired']==='running')$this->start((int)$b['id'],false);
    $until=microtime(true)+10;while(microtime(true)<$until){foreach($bots as $b)if($b['desired']==='running'){if(!isset($this->processes[$b['id']])||!proc_get_status($this->processes[$b['id']]['proc'])['running'])throw new RuntimeException('机器人 '.$b['id'].' 启动失败');$ready=Core::redis()->get('sc:ready:'.$b['id']);$child=(int)Core::redis()->get('sc:child:'.$b['id']);if($ready&&(!$child||!posix_kill($child,0)))throw new RuntimeException('业务进程已退出');if(!$ready&&microtime(true)>$until-1)throw new RuntimeException('业务进程未就绪');}usleep(100000);}
    if($p['source_kind']==='direct'){
     $src=Core::safePath($p['source_path']);Core::copyTree($src,$root.'/source-backup');try{Core::mirrorSource($dir,$src);}catch(\Throwable $e){Core::mirrorSource($root.'/source-backup',$src);throw $e;}
    }
    Core::sql("UPDATE projects SET active_version=?,release_state='active',release_error=NULL WHERE id=?",[$rel['version'],$id]);Core::set('candidate:'.$id,null);Core::sql("UPDATE releases SET state='success',detail='进程连续存活 10 秒，发布成功' WHERE id=?",[$rel['id']]);
    foreach($bots as $b){Core::log((int)$b['id'],'代码发布',$rel['version']);if($b['desired']==='running')Core::sql("UPDATE bots SET state='running',failures=0 WHERE id=?",[$b['id']]);}
    if(is_dir($root.'/source-backup'))Core::removeTree($root.'/source-backup');
    if($old&&$old!==$rel['version'])Core::removeTree($root.'/versions/'.$old);
   }catch(\Throwable $e){
    if($switched)foreach($bots as $b)$this->stop((int)$b['id']);Core::set('candidate:'.$id,null);Core::sql("UPDATE projects SET active_version=?,release_state=?,release_error=? WHERE id=?",[$old,$old?'active':'failed',$e->getMessage(),$id]);
    foreach($bots as $b)if($b['desired']==='running'&&$old){try{$this->start((int)$b['id'],false);}catch(\Throwable $ignore){}}
    Core::sql("UPDATE releases SET state='failed',detail=? WHERE id=?",[$e->getMessage(),$rel['id']]);foreach($bots as $b)Core::log((int)$b['id'],'发布失败并回滚',$e->getMessage());
   }
  });
 }
 function supervise():void{
  Core::redis()->setex('sc:daemon',30,(string)time());
  foreach($this->processes as $id=>$x){
   $s=proc_get_status($x['proc']);
   if(!$s['running']){proc_close($x['proc']);unset($this->processes[$id]);$b=Core::bot($id);$n=(int)$b['failures']+1;$retry=$b['desired']==='running'&&$b['config']['auto_restart']&&$n<5;
    $error='进程异常退出，退出码 '.$s['exitcode'].'，连续失败 '.$n.' 次';Core::sql('UPDATE bots SET pid=NULL,state=?,failures=?,last_error=? WHERE id=?',[$retry?'retrying':'error',$n,$error,$id]);$this->writeLog($id,$error);Core::log($id,'异常退出',$error);if($retry)$this->retry[$id]=time()+10;else Core::sql("UPDATE bots SET desired='stopped' WHERE id=?",[$id]);
   }elseif(!$x['healthy']&&time()-$x['start']>=10&&Core::redis()->get('sc:ready:'.$id)){
    $this->processes[$id]['healthy']=true;Core::sql("UPDATE bots SET state='running',failures=0 WHERE id=?",[$id]);$this->writeLog((int)$id,'业务进程就绪，运行状态正常');
   }
  }
  foreach($this->retry as $id=>$at)if(time()>=$at){unset($this->retry[$id]);$b=Core::bot($id);if($b['desired']==='running'){try{$this->writeLog((int)$id,'触发异常自动重启');$this->start($id,false);Core::log($id,'异常自动重启');}catch(\Throwable $e){$n=(int)$b['failures']+1;Core::sql("UPDATE bots SET failures=?,state=?,last_error=? WHERE id=?",[$n,$n<5?'retrying':'error',$e->getMessage(),$id]);if($n<5)$this->retry[$id]=time()+10;else Core::sql("UPDATE bots SET desired='stopped' WHERE id=?",[$id]);}}}
 }
 function maintenance():void{
  foreach(Core::rows('SELECT id,config FROM bots') as $b){$cfg=json_decode($b['config'],true);$changed=false;
   foreach(['maintenance_on'=>true,'maintenance_off'=>false] as $k=>$state)if(!empty($cfg[$k])&&strtotime($cfg[$k])<=time()){$cfg['maintenance']=$state;$cfg[$k]=null;$changed=true;Core::log((int)$b['id'],$state?'定时开启维护':'定时关闭维护');}
   if($changed)Core::sql('UPDATE bots SET config=? WHERE id=?',[Core::json($cfg),$b['id']]);
  }
 }
 function cleanup():void{
  $days=(int)Core::setting('log_days',30);if(!$days)return;$before=time()-$days*86400;
  foreach(glob(Core::BASE.'/logs/*/*.log') as $p){
   if(filesize($p)>0&&filemtime($p)<$before){file_put_contents($p,'');continue;}
   if(filesize($p)>52428800){rename($p,$p.'.'.date('YmdHis').'.log');touch($p);}
  }
  foreach(glob(Core::BASE.'/logs/*/*.*.log') as $p)if(filemtime($p)<$before)unlink($p);
  Core::sql('DELETE FROM operations WHERE created_at < DATE_SUB(NOW(),INTERVAL ? DAY)',[$days]);
 }
 function run():void{
  Core::set('runtimes',Core::runtimeOptions());
  foreach(Core::rows('SELECT id,source_kind,source_path,release_state FROM projects') as $p){$backup=Core::BASE.'/projects/'.$p['id'].'/source-backup';if(is_dir($backup)&&$p['source_kind']==='direct'){if(in_array($p['release_state'],['building','checking'],true))Core::mirrorSource($backup,Core::safePath($p['source_path']));Core::removeTree($backup);}Core::set('candidate:'.$p['id'],null);}
  Core::sql("UPDATE releases SET state='failed',detail='服务重启，发布中断，保留旧版本' WHERE state IN ('building','checking')");
  Core::sql("UPDATE projects SET release_state=IF(active_version IS NULL,'failed','active'),release_error='发布中断，保留旧版本' WHERE release_state IN ('building','checking')");
  pcntl_async_signals(true);pcntl_signal(SIGTERM,function(){$this->alive=false;});pcntl_signal(SIGINT,function(){$this->alive=false;});
  Core::sql("UPDATE jobs SET state='failed',result='服务重启，任务中断，请重新操作',finished_at=NOW() WHERE state='running'");
  Core::sql('UPDATE bots SET pid=NULL,state=IF(project_id IS NULL,\'unbound\',\'stopped\')');
  foreach(Core::rows("SELECT id,config FROM bots WHERE desired='running' AND project_id IS NOT NULL") as $b)if(json_decode($b['config'],true)['auto_start'])try{$this->start((int)$b['id'],false);}catch(\Throwable $e){Core::sql("UPDATE bots SET state='error',last_error=? WHERE id=?",[$e->getMessage(),$b['id']]);}
  $lastMaintenance=0;$lastCleanup=0;
  while($this->alive){
   try{
    $this->supervise();
    if(time()-$lastMaintenance>=1){$this->maintenance();$lastMaintenance=time();}
    if(time()-$lastCleanup>=3600){$this->cleanup();$lastCleanup=time();}
    foreach($this->tasks as $jid=>$task){
     $status=proc_get_status($task['proc']);if(!$status['running']){proc_close($task['proc']);unset($this->tasks[$jid]);$j=Core::one('SELECT state FROM jobs WHERE id=?',[$jid]);
      if(($j['state']??'')==='running')Core::sql("UPDATE jobs SET state='failed',result='后台操作进程异常退出',payload='{}',finished_at=NOW() WHERE id=?",[$jid]);
      if($task['resume'])try{$bot=Core::bot($task['bot']);if($bot['desired']==='running')$this->start($task['bot'],false);}catch(\Throwable $e){Core::sql("UPDATE bots SET state='error',last_error=? WHERE id=?",[$e->getMessage(),$task['bot']]);}
     }
    }
    $job=Core::one("SELECT * FROM jobs WHERE state='pending' ORDER BY id LIMIT 1");
    if($job){$jid=(int)(json_decode($job['payload'],true)['id']??0);foreach($this->tasks as $task)if($task['bot']===$jid){$job=[];break;}}

    if($job){Core::sql("UPDATE jobs SET state='running' WHERE id=?",[$job['id']]);
     if(in_array($job['type'],['sync','profile','photo','mode','token'],true)){
      $v=json_decode($job['payload'],true);$bid=(int)$v['id'];$resume=false;
      if(in_array($job['type'],['mode','token'],true)){$bot=Core::bot($bid);$resume=$bot['desired']==='running';$this->stop($bid);}
      $proc=proc_open(['/www/server/php/84/bin/php','-c',Core::BASE.'/data/cli.ini','-d','display_errors=0',Core::BASE.'/app/bin/job-worker.php',(string)$job['id']],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file',Core::BASE.'/logs/worker-error.log','a']],$pipes);
      $this->tasks[$job['id']]=['proc'=>$proc,'bot'=>$bid,'resume'=>$resume];
     }else try{$this->command($job);Core::sql("UPDATE jobs SET state='success',result='完成',payload='{}',finished_at=NOW() WHERE id=?",[$job['id']]);}catch(\Throwable $e){Core::sql("UPDATE jobs SET state='failed',result=?,payload='{}',finished_at=NOW() WHERE id=?",[$e->getMessage(),$job['id']]);}}
    $rel=Core::one("SELECT * FROM releases WHERE state='pending' AND due_at<=NOW() ORDER BY due_at,id LIMIT 1");if($rel&&!$this->tasks)$this->publish($rel);
    foreach(Core::rows('SELECT id FROM bots') as $b){$id=(int)$b['id'];if(isset($this->sync[$id]['proc'])){$s=proc_get_status($this->sync[$id]['proc']);if(!$s['running']){proc_close($this->sync[$id]['proc']);$this->sync[$id]=['at'=>time()];}}
     if(!isset($this->sync[$id]['proc'])&&time()-($this->sync[$id]['at']??0)>=5){$proc=proc_open(['/www/server/php/84/bin/php','-c',Core::BASE.'/data/cli.ini','-d','display_errors=0',Core::BASE.'/app/bin/sync.php',(string)$id],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file',Core::BASE.'/logs/sync.log','a']],$pipes);$this->sync[$id]=['proc'=>$proc,'at'=>time()];}}
   }catch(\Throwable $e){fwrite(STDERR,date('c').' '.$e->getMessage()."\n");}
   usleep(200000);
  }
  foreach($this->tasks as $task){proc_terminate($task['proc']);proc_close($task['proc']);}
  Core::redis()->del('sc:daemon');
  foreach(array_keys($this->processes) as $id)$this->stop($id);
  foreach($this->sync as $s)if(isset($s['proc'])){proc_terminate($s['proc']);proc_close($s['proc']);}
 }
}
