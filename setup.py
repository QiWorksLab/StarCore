import os,json,secrets,sqlite3,subprocess,pathlib,re
base=pathlib.Path('/www/wwwroot/StarCore')
for d in ['data','projects','logs','app/app/service','app/app/controller','app/bin','app/public/assets','examples']:
 (base/d).mkdir(parents=True,exist_ok=True)
cfgfile=base/'data/config.json'
if not (base/'data/db.ready').exists():
 dbpass=secrets.token_hex(24)
 root=subprocess.check_output(['/www/server/panel/pyenv/bin/python','-c',"import sys;sys.path.insert(0,'/www/server/panel/class');import public;print(public.M('config').where('id=?',(1,)).getField('mysql_root'))"],cwd='/www/server/panel',text=True).strip()
 redispass=''
 for line in pathlib.Path('/www/server/redis/redis.conf').read_text().splitlines():
  if line.strip().startswith('requirepass '): redispass=line.strip().split(None,1)[1].strip('"').strip("'")
 cfg={'db_host':'127.0.0.1','db_name':'StarCore','db_user':'StarCore','db_pass':dbpass,'key':secrets.token_hex(32),'redis_password':redispass,'base_url':''}
 cfgfile.write_text(json.dumps(cfg))
 os.chmod(cfgfile,0o640)
 tmp=base/'data/mysql-root.cnf'; tmp.write_text('[client]\nuser=root\npassword='+root+'\n');os.chmod(tmp,0o600)
 sql="CREATE DATABASE IF NOT EXISTS StarCore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'StarCore'@'127.0.0.1' IDENTIFIED BY '"+dbpass+"'; GRANT ALL ON StarCore.* TO 'StarCore'@'127.0.0.1';"
 r=subprocess.run(['/www/server/mysql/bin/mysql','--defaults-extra-file='+str(tmp)],input=sql,text=True,capture_output=True)
 tmp.unlink()
 if r.returncode: raise RuntimeError('database initialization failed: '+r.stderr)
 (base/'data/db.ready').touch(); print('Dedicated MySQL database created; secrets stored without displaying')
else: print('Configuration exists; retained')
