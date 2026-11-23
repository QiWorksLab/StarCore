import pathlib,os,subprocess,shutil,time,re
base=pathlib.Path('/www/wwwroot/StarCore')
matches=[p for p in pathlib.Path('/www/server/panel/vhost/nginx').glob('*.conf') if re.search(r'root\s+/www/wwwroot/StarCore(?:/app/public)?;',p.read_text())]
if len(matches)!=1:raise RuntimeError('Set the site directory to StarCore before deploying; ambiguous site selection')
vhost=matches[0]
domain=vhost.stem
backup=base/'data/nginx-before.conf'
if not backup.exists():shutil.copyfile(vhost,backup)
s=vhost.read_text().replace('root /www/wwwroot/StarCore;','root /www/wwwroot/StarCore/app/public;').replace('index index.php index.html','index index.php index.html')
vhost.write_text(s)
rewrite=pathlib.Path('/www/server/panel/vhost/rewrite')/(domain+'.conf')
if not (base/'data/rewrite-before.conf').exists():shutil.copyfile(rewrite,base/'data/rewrite-before.conf')
rewrite.write_text('location / {\n    try_files $uri $uri/ /index.php?s=$uri&$args;\n}\n')
(base/'app/public/.user.ini').write_text('open_basedir=/www/wwwroot/:/tmp/\n')
(base/'app/.env').write_text('APP_DEBUG = true\n')
appcfg=base/'app/config/app.php'
s=appcfg.read_text().replace("'show_error_msg'   => false","'show_error_msg'   => false")
appcfg.write_text(s)
route=base/'app/config/route.php';s=route.read_text().replace("'url_route_must'        => false","'url_route_must'        => true").replace("'url_html_suffix'       => 'html'","'url_html_suffix'       => ''");route.write_text(s)
# Keep PHP deprecations in logs rather than corrupting API JSON.
public=base/'app/public/index.php';s=public.read_text();s=s.replace('<?php','<?php\nini_set("display_errors","0");error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);',1);public.write_text(s)
for d in ['data/home','data/go-cache','data/go-mod','logs','projects','app/runtime']:(base/d).mkdir(parents=True,exist_ok=True)
for p in [base/'data',base/'logs',base/'projects',base/'app/runtime']:
 subprocess.run(['chown','-R','www:www',str(p)],check=True)
os.chmod(base/'data',0o750);os.chmod(base/'data/config.json',0o640);os.chmod(base/'data/initial-login.txt',0o600);subprocess.run(['chown','root:root',str(base/'data/initial-login.txt')],check=True)
subprocess.run(['chown','-R','www:www',str(base/'app/public')],check=True)
unit='''[Unit]
Description=StarCore Bot Manager
After=network-online.target mysqld.service redis.service
Wants=network-online.target
[Service]
Type=simple
User=www
Group=www
WorkingDirectory=/www/wwwroot/StarCore/app
ExecStart=/www/server/php/84/bin/php -c /www/wwwroot/StarCore/data/cli.ini -d display_errors=0 -d error_reporting=22527 /www/wwwroot/StarCore/app/bin/daemon.php
Restart=on-failure
RestartSec=5
KillMode=control-group
TimeoutStopSec=15
UMask=0027
StandardOutput=append:/www/wwwroot/StarCore/logs/daemon.log
StandardError=append:/www/wwwroot/StarCore/logs/daemon-error.log
[Install]
WantedBy=multi-user.target
'''
pathlib.Path('/etc/systemd/system/starcore.service').write_text(unit)
subprocess.run(['/www/server/nginx/sbin/nginx','-t'],check=True)
subprocess.run(['/www/server/nginx/sbin/nginx','-s','reload'],check=True)
subprocess.run(['systemctl','daemon-reload'],check=True)
subprocess.run(['systemctl','enable','--now','starcore'],check=True)
print('Site configured; StarCore daemon enabled')
