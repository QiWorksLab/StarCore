<?php
namespace app\controller;
use app\service\Core as C;
use RuntimeException;
class Api {
 private array $user=[];
 private function session():void {
  if(session_status()!==PHP_SESSION_ACTIVE){session_name('starcore');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();}
  if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
 }
 private function auth():void {
  $this->user=C::one('SELECT id,username,role,enabled,session_version FROM users WHERE id=?',[$_SESSION['uid']??0]);
  if(!$this->user||!$this->user['enabled']||($_SESSION['ver']??0)!=$this->user['session_version']||(time()-($_SESSION['last']??0)>7200))throw new RuntimeException('请先登录',401);
  $_SESSION['last']=time();
 }
 private function super():void{if($this->user['role']!=='super')throw new RuntimeException('此操作仅超级管理员可执行',403);}
 public function index(string $action){
  $action=str_replace('-','.',$action);$this->session();$req=request();$in=json_decode($req->getContent(),true)?:$req->post();$query=$req->get();unset($query['username'],$query['password']);$in=array_merge($query,$in);
  try{
   if($req->isPost()&&!hash_equals($_SESSION['csrf'],(string)$req->header('x-csrf-token','')))throw new RuntimeException('请求校验失败，请刷新页面',403);
   if($action==='me'){
    try{$this->auth();$u=$this->user;}catch(\Throwable $e){$u=null;}
    return json(['ok'=>true,'data'=>['user'=>$u,'csrf'=>$_SESSION['csrf'],'version'=>'1.0.0']])->header(['Cache-Control'=>'no-store']);
   }
   if($action==='login'){
    if(!$req->isPost())throw new RuntimeException('仅允许 POST',405);
    $ip=$req->ip();$l=C::one('SELECT * FROM login_attempts WHERE ip=?',[$ip]);if($l&&$l['failures']>=5&&strtotime($l['updated_at'])>time()-600)throw new RuntimeException('登录失败次数过多，10 分钟后重试',429);
    $u=C::one('SELECT * FROM users WHERE username=?',[$in['username']??'']);
    if(!$u||!$u['enabled']||!password_verify($in['password']??'',$u['password'])){
     C::sql('INSERT INTO login_attempts(ip,failures,updated_at) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE failures=IF(updated_at < DATE_SUB(NOW(),INTERVAL 10 MINUTE),1,failures+1),updated_at=NOW()',[$ip]);throw new RuntimeException('账号或密码错误',401);
    }
    C::sql('DELETE FROM login_attempts WHERE ip=?',[$ip]);session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['ver']=$u['session_version'];$_SESSION['last']=time();$_SESSION['csrf']=bin2hex(random_bytes(24));
    if(!C::setting('base_url')){C::set('base_url','https://'.$req->host());}
    return json(['ok'=>true,'data'=>['csrf'=>$_SESSION['csrf']]]);
   }
   $this->auth();
   $read=['bots.list','bots.get','projects.list','projects.get','files.list','files.read','files.diff','logs.get','logs.files','operations.list','settings.get','users.list','jobs.get','health','runtimes','releases.list','bots.avatar'];
   if(!$req->isPost()&&!in_array($action,$read,true))throw new RuntimeException('仅允许 POST',405);
   $id=(int)($in['id']??0);$actor=(int)$this->user['id'];$data=null;
   switch($action){
    case 'logout':session_destroy();break;
    case 'password':
     $u=C::one('SELECT password FROM users WHERE id=?',[$actor]);if(!password_verify($in['old']??'',$u['password']))throw new RuntimeException('原密码错误');$p=(string)($in['password']??'');if(strlen($p)<10)throw new RuntimeException('密码至少 10 位');
     C::sql('UPDATE users SET password=?,session_version=session_version+1 WHERE id=?',[password_hash($p,PASSWORD_DEFAULT),$actor]);$_SESSION['ver']++;break;
    case 'bots.list':$data=array_map([C::class,'publicBot'],C::rows('SELECT b.*,p.name AS project_name FROM bots b LEFT JOIN projects p ON p.id=b.project_id ORDER BY b.id DESC'));break;
    case 'bots.get':$b=C::bot($id);$data=C::publicBot($b);$data['webhook_endpoint']=rtrim(C::setting('base_url',''),'/').'/hook/'.$b['hook_key'];break;
    case 'bots.add':
     $t=trim((string)($in['token']??''));if(!preg_match('/^\d+:[A-Za-z0-9_-]+$/',$t))throw new RuntimeException('Token 格式不正确');$me=C::telegram($t,'getMe');
     C::sql('INSERT INTO bots(tg_id,token,hook_key,config,meta,created_at) VALUES(?,?,?,?,?,NOW())',[$me['id'],C::crypt($t),bin2hex(random_bytes(24)),C::json(C::defaults()),C::json($me)]);
     $id=(int)C::db()->lastInsertId();C::enqueue('sync',['id'=>$id],$actor);C::log($id,'添加机器人','',$actor);$data=['id'=>$id];break;
    case 'bots.sync':$data=['job'=>C::enqueue('sync',['id'=>$id],$actor)];break;
    case 'bots.token':
     $b=C::bot($id);$t=trim($in['token']??'');$me=C::telegram($t,'getMe');if((string)$me['id']!==(string)$b['tg_id'])throw new RuntimeException('新 Token 必须属于当前机器人');
     $data=['job'=>C::enqueue('token',['id'=>$id,'token'=>C::crypt($t)],$actor)];break;
    case 'bots.profile':
     C::bot($id);$method=$in['method']??'';if(!in_array($method,['setMyName','setMyCommands','setChatMenuButton','setMyDescription','setMyShortDescription','removeMyProfilePhoto'],true))throw new RuntimeException('不支持该资料操作');
     $data=['job'=>C::enqueue('profile',['id'=>$id,'method'=>$method,'args'=>$in['args']??[]],$actor)];break;
    case 'bots.photo':
     $b=C::bot($id);$f=$req->file('file');if(!$f||$f->getSize()>10485760)throw new RuntimeException('请选择 10MB 以内的图片');
     $mime=mime_content_type($f->getPathname());if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('仅支持 JPEG、PNG、WebP 图片');
     $dir=C::BASE.'/data/uploads';if(!is_dir($dir))mkdir($dir,0750,true);$p=$dir.'/'.bin2hex(random_bytes(16));if(!move_uploaded_file($f->getPathname(),$p))throw new RuntimeException('上传失败');$im=imagecreatefromstring(file_get_contents($p));if(!$im)throw new RuntimeException('图片无法解码');$jpg=$p.'.jpg';imagejpeg($im,$jpg,92);imagedestroy($im);unlink($p);$data=['job'=>C::enqueue('photo',['id'=>$id,'path'=>$jpg,'mime'=>'image/jpeg'],$actor)];break;
    case 'bots.avatar':
     $b=C::bot($id);$file=$b['meta']['avatar_file_id']??null;if(!$file)throw new RuntimeException('暂无头像',404);$token=C::decrypt($b['token']);$f=C::telegram($token,'getFile',['file_id'=>$file]);
     $h=curl_init('https://api.telegram.org/file/bot'.$token.'/'.$f['file_path']);curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$raw=curl_exec($h);$mime=curl_getinfo($h,CURLINFO_CONTENT_TYPE);curl_close($h);if(!$raw)throw new RuntimeException('头像获取失败');return response($raw,200,['Content-Type'=>$mime?:'image/jpeg','Cache-Control'=>'private,max-age=60']);
    case 'bots.config':
     $b=C::bot($id);$cfg=$b['config'];$x=$in['config']??[];
     foreach(['scopes','allow','deny','maintenance','maintenance_text','exempt','auto_restart','auto_start','maintenance_on','maintenance_off'] as $k)if(array_key_exists($k,$x))$cfg[$k]=$x[$k];
     foreach(['allow','deny','exempt'] as $k)$cfg[$k]=C::listIds($cfg[$k]);if(array_intersect($cfg['allow'],$cfg['deny']))throw new RuntimeException('同一 Chat ID 不能同时进入允许和禁止名单');
     if(!is_array($cfg['scopes'])||array_diff($cfg['scopes'],['private','group','supergroup','channel']))throw new RuntimeException('消息来源无效');
     foreach(['maintenance','auto_restart','auto_start'] as $k)$cfg[$k]=(bool)$cfg[$k];
     foreach(['maintenance_on','maintenance_off'] as $k){if(!$cfg[$k])$cfg[$k]=null;elseif(strtotime($cfg[$k])===false)throw new RuntimeException('定时时间无效');else $cfg[$k]=date('Y-m-d H:i:s',strtotime($cfg[$k]));}
     C::sql('UPDATE bots SET config=? WHERE id=?',[C::json($cfg),$id]);if($cfg['maintenance']!==$b['config']['maintenance'])C::log($id,$cfg['maintenance']?'开启维护':'关闭维护','',$actor);$data=C::publicBot(C::bot($id));break;
    case 'bots.mode':
     $b=C::bot($id);$cfg=$b['config'];$x=$in['config']??[];$cfg['mode']=$x['mode']??'polling';if(!in_array($cfg['mode'],['polling','webhook'],true))throw new RuntimeException('消息模式无效');
     $cfg['max_connections']=max(1,min(100,(int)($x['max_connections']??40)));$cfg['allowed_updates']=$x['allowed_updates']??['message','callback_query'];if(!is_array($cfg['allowed_updates']))throw new RuntimeException('allowed_updates 必须为数组');
     $cfg['webhook_url']=trim($x['webhook_url']??'');if($cfg['webhook_url']&&!preg_match('#^https://#',$cfg['webhook_url']))throw new RuntimeException('Webhook 必须使用 HTTPS');
     if(!empty($x['secret_token'])){if(!preg_match('/^[A-Za-z0-9_-]{1,256}$/',$x['secret_token']))throw new RuntimeException('Secret Token 格式无效');$cfg['secret_token']=$x['secret_token'];}
     $data=['job'=>C::enqueue('mode',['id'=>$id,'config'=>$cfg,'drop'=>(bool)($x['drop_pending_updates']??false)],$actor)];break;
    case 'bots.bind':
     $b=C::bot($id);$pid=(int)($in['project_id']??0);if($pid)C::project($pid);$data=['job'=>C::enqueue('bind',['id'=>$id,'project_id'=>$pid?:null],$actor)];break;
    case 'bots.start':case 'bots.stop':case 'bots.restart':case 'bots.delete':
     C::bot($id);if($action==='bots.delete'&&($in['confirm']??'')!=='DELETE')throw new RuntimeException('删除需要明确确认');$data=['job'=>C::enqueue(substr($action,5),['id'=>$id],$actor)];break;
    case 'projects.list':$data=C::rows('SELECT p.*,(SELECT COUNT(*) FROM bots WHERE project_id=p.id) AS bot_count FROM projects p ORDER BY id DESC');foreach($data as &$p)$p['config']=json_decode($p['config'],true);break;
    case 'projects.get':$data=C::project($id);break;
    case 'projects.add':
     $name=trim($in['name']??'');if(!$name||mb_strlen($name)>128)throw new RuntimeException('请输入项目名称');$kind=$in['kind']??'created';if(!in_array($kind,['created','copy','direct','zip'],true))throw new RuntimeException('项目类型无效');
     $src=null;if(in_array($kind,['copy','direct'],true)){$src=C::safePath($in['path']??'');if(!is_dir($src))throw new RuntimeException('请选择目录');}
     $lang=$in['language']??'python';$r=C::runtimeOptions();if(!isset($r[$lang])||!$r[$lang])throw new RuntimeException('该语言没有可用运行时');$runtime=$in['runtime']??$r[$lang][0];if(!in_array($runtime,$r[$lang],true))throw new RuntimeException('运行时无效');
     $entry=trim((string)($in['entry']??''));
     if($entry==='')$entry=['php'=>'bot.php','python'=>'bot.py','go'=>'main.go'][$lang];
     if(str_starts_with($entry,'/')||str_contains($entry,'..')||str_contains($entry,"\0"))throw new RuntimeException('入口必须为项目内相对路径');
     if($kind==='created'){
      $template=C::BASE.'/examples/'.$lang.'.'.(['python'=>'py','php'=>'php','go'=>'go'][$lang]);
      if(!is_file($template)||!is_readable($template))throw new RuntimeException('项目示例模板缺失：'.$lang);
     }
     $projectRoot=C::BASE.'/projects';
     if(!is_dir($projectRoot)&&!mkdir($projectRoot,0750,true))throw new RuntimeException('项目目录创建失败');
     C::db()->beginTransaction();
     $createdPath=null;
     try{
      C::sql('INSERT INTO projects(name,source_path,source_kind,config,created_at) VALUES(?,?,?,?,NOW())',[$name,$src,$kind,C::json(['language'=>$lang,'runtime'=>$runtime,'entry'=>$entry,'args'=>[]]),]);
      $id=(int)C::db()->lastInsertId();
      $createdPath=$projectRoot.'/'.$id;
      if(!mkdir($createdPath.'/draft',0750,true))throw new RuntimeException('项目工作目录创建失败');
      if($kind==='created'){
       $target=$createdPath.'/draft/'.$entry;
       if(!is_dir(dirname($target))&&!mkdir(dirname($target),0750,true))throw new RuntimeException('入口文件目录创建失败');
       if(!copy($template,$target))throw new RuntimeException('示例入口文件复制失败');
      }
      if($src){$job=C::enqueue('import',['id'=>$id],$actor);$data=['id'=>$id,'job'=>$job];}
      else $data=['id'=>$id];
      C::db()->commit();
     }catch(\Throwable $e){
      if(C::db()->inTransaction())C::db()->rollBack();
      if($createdPath&&is_dir($createdPath))C::removeTree($createdPath);
      throw $e;
     }
     break;
    case 'projects.config':
     $p=C::project($id);$c=$in['config']??[];$opts=C::runtimeOptions();if(!isset($opts[$c['language']??''])||!in_array($c['runtime']??'',$opts[$c['language']],true))throw new RuntimeException('运行时无效');
     if(empty($c['entry'])||str_contains($c['entry'],'..')||str_starts_with($c['entry'],'/'))throw new RuntimeException('入口无效');if(!is_array($c['args']??[]))throw new RuntimeException('启动参数必须为数组');$c['args']=array_map('strval',$c['args']??[]);
     C::sql('UPDATE projects SET name=?,config=? WHERE id=?',[trim($in['name']??$p['name']),C::json($c),$id]);break;
    case 'projects.delete':
     $p=C::project($id);if(($in['confirm']??'')!=='DELETE')throw new RuntimeException('删除需要明确确认');$del=(bool)($in['delete_source']??false);if($del)$this->super();$data=['job'=>C::enqueue('project_delete',['id'=>$id,'delete_source'=>$del],$actor)];break;
    case 'projects.publish':
     C::project($id);$due=$in['due_at']??'';$ts=$due?strtotime($due):time();if(!$ts)throw new RuntimeException('发布时间无效');
     $data=C::lockProject($id,function()use($id,$ts){$v=date('YmdHis').'-'.bin2hex(random_bytes(4));$root=C::BASE.'/projects/'.$id;C::copyTree($root.'/draft',$root.'/versions/'.$v);file_put_contents($root.'/versions/'.$v.'/.starcore-runtime.json',C::json(C::project($id)['config']));C::db()->beginTransaction();try{C::sql("UPDATE releases SET state='superseded' WHERE project_id=? AND state='pending'",[$id]);C::sql('INSERT INTO releases(project_id,version,state,due_at,created_at) VALUES(?,?,?,?,NOW())',[$id,$v,'pending',date('Y-m-d H:i:s',$ts)]);$rid=(int)C::db()->lastInsertId();C::db()->commit();return ['release'=>$rid];}catch(\Throwable $e){C::db()->rollBack();throw $e;}});break;
    case 'releases.list':$data=C::rows('SELECT r.*,p.name AS project_name FROM releases r JOIN projects p ON p.id=r.project_id ORDER BY r.id DESC LIMIT 100');break;
    case 'releases.cancel':C::sql("UPDATE releases SET state='cancelled' WHERE id=? AND state='pending'",[$id]);break;
    case 'files.list':
     C::project($id);$rel=$in['path']??'';$path=C::workPath($id,$rel);if(!is_dir($path))throw new RuntimeException('目录不存在');$data=[];foreach(scandir($path) as $n){if($n==='.'||$n==='..')continue;$p=$path.'/'.$n;$data[]=['name'=>$n,'path'=>trim($rel.'/'.$n,'/'),'directory'=>is_dir($p),'symlink'=>is_link($p),'size'=>is_file($p)?filesize($p):0];}break;
    case 'files.read':case 'files.diff':
     C::project($id);$p=C::workPath($id,$in['path']??'');if(!is_file($p)||filesize($p)>2097152)throw new RuntimeException('仅支持在线编辑 2MB 以内的普通文件');$content=file_get_contents($p);if(str_contains($content,"\0"))throw new RuntimeException('二进制文件不能在线编辑');$data=['content'=>$content,'hash'=>hash('sha256',$content)];
     if($action==='files.diff'){$project=C::project($id);$old=C::BASE.'/projects/'.$id.'/versions/'.($project['active_version']??'').'/'.($in['path']??'');$data['active']=is_file($old)&&!is_link($old)?file_get_contents($old):'';}break;
    case 'files.write':
     C::project($id);$data=C::lockProject($id,function()use($id,$in){$p=C::workPath($id,$in['path']??'');if(is_dir($p))throw new RuntimeException('不能覆盖目录');$c=(string)($in['content']??'');if(strlen($c)>2097152)throw new RuntimeException('文件过大');if(is_file($p)&&isset($in['hash'])&&!hash_equals(hash_file('sha256',$p),$in['hash']))throw new RuntimeException('文件已被修改，请重新打开后保存');if(!is_dir(dirname($p)))throw new RuntimeException('父目录不存在');file_put_contents($p,$c,LOCK_EX);return ['hash'=>hash('sha256',$c)];});break;
    case 'files.mkdir':C::project($id);C::lockProject($id,function()use($id,$in){$p=C::workPath($id,$in['path']??'');if(file_exists($p)||!mkdir($p,0750))throw new RuntimeException('目录创建失败');});break;
    case 'files.rename':C::project($id);C::lockProject($id,function()use($id,$in){$p=C::workPath($id,$in['path']??'');$to=C::workPath($id,$in['to']??'');if($p===C::workPath($id)||file_exists($to)||!rename($p,$to))throw new RuntimeException('重命名失败');});break;
    case 'files.delete':C::project($id);if(($in['confirm']??'')!=='DELETE')throw new RuntimeException('删除需要确认');C::lockProject($id,function()use($id,$in){$p=C::workPath($id,$in['path']??'');if($p===C::workPath($id))throw new RuntimeException('不能删除项目根目录');C::removeTree($p);});break;
    case 'files.upload':
     C::project($id);$f=$req->file('file');if(!$f||$f->getSize()>104857600)throw new RuntimeException('上传文件不得超过 100MB');$name=basename($f->getOriginalName());$rel=trim(($in['path']??'').'/'.$name,'/');$p=C::workPath($id,$rel);
     if(file_exists($p)&&empty($in['overwrite']))throw new RuntimeException('文件已存在');if(!move_uploaded_file($f->getPathname(),$p))throw new RuntimeException('上传失败');
     if(!empty($in['extract'])){if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip')throw new RuntimeException('仅 ZIP 支持解压');$data=['job'=>C::enqueue('extract',['id'=>$id,'path'=>$rel],$actor)];}break;
    case 'logs.files':
     C::bot($id);$data=[];foreach(glob(C::BASE.'/logs/'.$id.'/*.log') as $p)$data[]=basename($p);rsort($data);break;
    case 'logs.get':
     C::bot($id);$kind=$in['kind']??'stdout';if(!in_array($kind,['stdout','stderr','system'],true))throw new RuntimeException('日志类型无效');$file=$in['file']??($kind.'.'.date('Y-m-d').'.log');if(!preg_match('/^(stdout|stderr|system)(?:\.[0-9-]+)?\.log$/',$file))throw new RuntimeException('日志文件名无效');$p=C::BASE.'/logs/'.$id.'/'.$file;
     $offset=max(0,(int)($in['offset']??0));$data=['content'=>'','offset'=>0];if(is_file($p)){$h=fopen($p,'r');$size=filesize($p);if($offset>$size)$offset=0;if(!$offset)$offset=max(0,$size-65536);fseek($h,$offset);$data=['content'=>fread($h,65536),'offset'=>ftell($h)];fclose($h);}break;
    case 'logs.clear':C::bot($id);if(($in['confirm']??'')!=='DELETE')throw new RuntimeException('清理需要确认');foreach(glob(C::BASE.'/logs/'.$id.'/*.log') as $p)file_put_contents($p,'');C::log($id,'清理日志','',$actor);break;
    case 'operations.list':$data=C::rows('SELECT o.*,u.username FROM operations o LEFT JOIN users u ON u.id=o.actor_id WHERE (?=0 OR o.bot_id=?) ORDER BY o.id DESC LIMIT 200',[$id,$id]);break;
    case 'jobs.get':$data=C::one('SELECT id,type,state,result,created_at,finished_at FROM jobs WHERE id=?',[$id]);break;
    case 'settings.get':$data=[];foreach(['base_url','maintenance_text','exempt','log_days','sync_seconds'] as $k)$data[$k]=C::setting($k);break;
    case 'settings.save':
     $this->super();$url=rtrim(trim($in['base_url']??''),'/');if(!preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?$#',$url))throw new RuntimeException('平台地址请输入 HTTPS 域名，不带路径');
     C::set('base_url',$url);C::set('maintenance_text',(string)($in['maintenance_text']??''));C::set('exempt',C::listIds($in['exempt']??[]));$days=(int)($in['log_days']??30);if($days<0||$days>36500)throw new RuntimeException('保留天数无效');C::set('log_days',$days);break;
    case 'users.list':$this->super();$data=C::rows('SELECT id,username,role,enabled,created_at FROM users ORDER BY id');break;
    case 'users.save':
     $this->super();if(!empty($in['password'])&&strlen($in['password'])<10)throw new RuntimeException('密码至少 10 位');$name=trim($in['username']??'');$role=$in['role']??'admin';$enabled=(int)(bool)($in['enabled']??true);if(!preg_match('/^[A-Za-z0-9_.-]{3,64}$/',$name)||!in_array($role,['super','admin'],true))throw new RuntimeException('账号或角色无效');
     if($id){$old=C::one('SELECT * FROM users WHERE id=?',[$id]);if(!$old)throw new RuntimeException('账号不存在');if($old['role']==='super'&&$old['enabled']&&($role!=='super'||!$enabled)&&C::one("SELECT COUNT(*) AS n FROM users WHERE role='super' AND enabled=1")['n']<=1)throw new RuntimeException('必须保留至少一个启用的超级管理员');C::sql('UPDATE users SET username=?,role=?,enabled=?,session_version=session_version+1 WHERE id=?',[$name,$role,$enabled,$id]);}
     else {if(strlen($in['password']??'')<10)throw new RuntimeException('密码至少 10 位');C::sql('INSERT INTO users(username,password,role,enabled,created_at) VALUES(?,?,?,?,NOW())',[$name,password_hash($in['password'],PASSWORD_DEFAULT),$role,$enabled]);$id=(int)C::db()->lastInsertId();}
     if(!empty($in['password'])){if(strlen($in['password'])<10)throw new RuntimeException('密码至少 10 位');C::sql('UPDATE users SET password=? WHERE id=?',[password_hash($in['password'],PASSWORD_DEFAULT),$id]);}break;
    case 'health':$data=['daemon_online'=>(time()-(int)C::redis()->get('sc:daemon'))<30];break;
    case 'runtimes':$data=C::runtimeOptions();break;
    default:throw new RuntimeException('接口不存在',404);
   }
   return json(['ok'=>true,'data'=>$data])->header(['Cache-Control'=>'no-store']);
  }catch(\Throwable $e){$code=in_array($e->getCode(),[401,403,404,405,429],true)?$e->getCode():400;$msg=$e instanceof \PDOException?'数据库操作失败：可能存在重复记录或关联冲突':$e->getMessage();return json(['ok'=>false,'error'=>$msg],$code)->header(['Cache-Control'=>'no-store']);}
 }
 public function hook(string $key){
  try{
   $b=C::one('SELECT * FROM bots WHERE hook_key=?',[$key]);if(!$b)return json(['ok'=>false],404);$cfg=json_decode($b['config'],true);
   if($cfg['mode']!=='webhook'||!hash_equals($cfg['secret_token'],(string)request()->header('x-telegram-bot-api-secret-token','')))return json(['ok'=>false],403);
   $raw=request()->getContent();if(strlen($raw)>2097152)return json(['ok'=>false],413);$u=json_decode($raw,true);if(!is_array($u)||!isset($u['update_id']))return json(['ok'=>false],400);
   C::redis()->rPush('sc:updates:'.$b['id'],$raw);return json(['ok'=>true]);
  }catch(\Throwable $e){return json(['ok'=>false],503);}
 }
}
