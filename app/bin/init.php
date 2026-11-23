<?php
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/service/Core.php';
use app\service\Core as C;
$ddl=[
"CREATE TABLE IF NOT EXISTS users(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,username VARCHAR(64) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,role ENUM('super','admin') NOT NULL,enabled TINYINT NOT NULL DEFAULT 1,session_version INT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL)",
"CREATE TABLE IF NOT EXISTS settings(name VARCHAR(64) PRIMARY KEY,value LONGTEXT NOT NULL)",
"CREATE TABLE IF NOT EXISTS projects(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,name VARCHAR(128) NOT NULL,source_path TEXT NULL,source_kind VARCHAR(16) NOT NULL DEFAULT 'created',config LONGTEXT NOT NULL,active_version VARCHAR(64) NULL,release_state VARCHAR(32) NOT NULL DEFAULT 'draft',release_error TEXT NULL,created_at DATETIME NOT NULL)",
"CREATE TABLE IF NOT EXISTS bots(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,tg_id BIGINT NOT NULL UNIQUE,token TEXT NOT NULL,hook_key VARCHAR(64) NOT NULL UNIQUE,project_id BIGINT UNSIGNED NULL,config LONGTEXT NOT NULL,meta LONGTEXT NOT NULL,desired VARCHAR(16) NOT NULL DEFAULT 'stopped',state VARCHAR(32) NOT NULL DEFAULT 'unbound',pid INT NULL,started_at DATETIME NULL,failures INT NOT NULL DEFAULT 0,last_error TEXT NULL,synced_at DATETIME NULL,sync_error TEXT NULL,created_at DATETIME NOT NULL,FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL)",
"CREATE TABLE IF NOT EXISTS jobs(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,type VARCHAR(32) NOT NULL,payload LONGTEXT NOT NULL,state VARCHAR(16) NOT NULL,result LONGTEXT NULL,actor_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,finished_at DATETIME NULL,INDEX(state))",
"CREATE TABLE IF NOT EXISTS releases(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,project_id BIGINT UNSIGNED NOT NULL,version VARCHAR(64) NOT NULL,state VARCHAR(20) NOT NULL,due_at DATETIME NOT NULL,detail TEXT NULL,created_at DATETIME NOT NULL,INDEX(state,due_at),FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE)",
"CREATE TABLE IF NOT EXISTS operations(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,bot_id BIGINT UNSIGNED NULL,event VARCHAR(64) NOT NULL,detail TEXT NULL,actor_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,INDEX(bot_id,created_at))",
"CREATE TABLE IF NOT EXISTS login_attempts(ip VARCHAR(64) PRIMARY KEY,failures INT NOT NULL,updated_at DATETIME NOT NULL)"
];
foreach($ddl as $s)C::sql($s);
foreach(['maintenance_text'=>'维护中...请耐心等待...','exempt'=>[],'log_days'=>30,'base_url'=>'','sync_seconds'=>5] as $k=>$v)if(C::setting($k)===null)C::set($k,$v);
if(!C::one('SELECT id FROM users LIMIT 1')){
 $p=bin2hex(random_bytes(10));
 C::sql('INSERT INTO users(username,password,role,created_at) VALUES(?,?,?,NOW())',['admin',password_hash($p,PASSWORD_DEFAULT),'super']);
 file_put_contents(C::BASE.'/data/initial-login.txt',"账号：admin\n密码：".$p."\n");chmod(C::BASE.'/data/initial-login.txt',0600);
 echo "Super administrator created. Initial credentials stored in protected initial-login.txt\n";
}
echo "Schema ready; Redis: ".C::redis()->ping()."\n";
