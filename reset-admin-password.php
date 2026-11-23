<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(posix_geteuid()!==0){fwrite(STDERR,"请使用 root 或 sudo 执行。\n");exit(1);}
require '/www/wwwroot/StarCore/app/app/service/Core.php';
use app\service\Core;
try{
 $db=Core::db();$db->beginTransaction();
 $users=Core::rows("SELECT id,username FROM users WHERE role='super' ORDER BY id FOR UPDATE");
 if(count($users)!==1)throw new RuntimeException('超级管理员数量不是 1，停止自动重置');
 $u=$users[0];$password=bin2hex(random_bytes(12));
 Core::sql('UPDATE users SET password=?,session_version=session_version+1,enabled=1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
 $db->commit();
 echo "超级管理员密码已重置，旧登录已失效。\n账号：".$u['username']."\n新密码：".$password."\n请保存并登录后修改；工具不保存新密码明文。\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,"重置失败：".$e->getMessage()."\n");exit(1);}
