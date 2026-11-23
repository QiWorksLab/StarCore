import urllib.request,urllib.error,http.cookiejar,json,pathlib,time,ssl,sys
base=pathlib.Path('/www/wwwroot/StarCore')
import subprocess,urllib.parse,os
origin=os.environ.get('STARCORE_TEST_ORIGIN') or subprocess.check_output(['/www/server/php/84/bin/php','-c',str(base/'data/cli.ini'),'-r','require "/www/wwwroot/StarCore/app/app/service/Core.php";echo app\\service\\Core::setting("base_url");'],text=True).strip()
domain=urllib.parse.urlparse(origin).netloc
if not domain:raise RuntimeError('Configure platform URL or STARCORE_TEST_ORIGIN before testing')
jar=http.cookiejar.CookieJar()
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),urllib.request.HTTPSHandler(context=ssl._create_unverified_context()))
csrf=''
checks=[]
def call(a,d=None):
 global csrf
 h={'X-CSRF-Token':csrf}
 url='https://'+domain+'/api/'+a.split('?')[0].replace('.','-')+('?' + a.split('?',1)[1] if '?' in a else '')
 if d is None:req=urllib.request.Request(url,headers=h)
 else:req=urllib.request.Request(url,data=json.dumps(d).encode(),headers={**h,'Content-Type':'application/json'})
 try:r=opener.open(req,timeout=30);j=json.load(r)
 except urllib.error.HTTPError as e:
  raw=e.read()
  try:j=json.loads(raw)
  except Exception:j={"ok":False,"error":"HTTP "+str(e.code)+" "+raw.decode(errors="replace")[:500]}
 return j
def ok(a,d=None):
 j=call(a,d)
 assert j.get('ok'),(a,j)
 return j.get('data')
def expect_bad(a,d):
 j=call(a,d);assert not j.get('ok'),(a,j)
def waitjob(n):
 for i in range(120):
  j=ok('jobs.get?id='+str(n))
  if j['state'] in ['success','failed']:assert j['state']=='success',j;return
  time.sleep(.25)
 raise AssertionError('job timed out')
csrf=ok('me')['csrf']
expect_bad('projects.add',{'name':'unauth'})
checks.append('unauthenticated writes rejected')
passwd=(base/'data/initial-login.txt').read_text().split('密码：')[1].strip()
csrf=ok('login',{'username':'admin','password':passwd})['csrf']
assert ok('me')['user']['role']=='super'
checks.append('login and CSRF session')
expect_bad('projects.add',{'name':'bad','kind':'direct','path':'/etc','language':'php'})
expect_bad('projects.add',{'name':'protected','kind':'direct','path':str(base),'language':'php'})
checks.append('directory boundary and core protection')
p=ok('projects.add',{'name':'验收临时项目','language':'php','kind':'created'})
pid=p['id']
root=ok('files.list?id='+str(pid));assert any(x['name']=='bot.php' for x in root)
orig=ok('files.read?id='+str(pid)+'&path=bot.php')
saved=ok('files.write',{'id':pid,'path':'bot.php','hash':orig['hash'],'content':orig['content']+'\n// smoke\n'})
expect_bad('files.write',{'id':pid,'path':'bot.php','hash':orig['hash'],'content':'stale'})
expect_bad('files.write',{'id':pid,'path':'../out.php','content':'bad'})
checks.append('file read/write, optimistic locking and traversal rejection')
ok('projects.publish',{'id':pid})
for i in range(160):
 pr=ok('projects.get?id='+str(pid))
 if pr['release_state']=='active':break
 if pr['release_state']=='failed':raise AssertionError(pr)
 time.sleep(.25)
assert pr['active_version']
old=pr['active_version']
checks.append('real PHP build and first release')
ok('files.write',{'id':pid,'path':'bot.php','content':'<?php syntax ! invalid'})
ok('projects.publish',{'id':pid})
for i in range(160):
 pr=ok('projects.get?id='+str(pid))
 if pr['release_error']:break
 time.sleep(.25)
assert pr['active_version']==old and pr['release_error']
checks.append('failed build retains previous active version')
# Verify pending plan replacement preserves active version.
ok('projects.publish',{'id':pid,'due_at':'2099-01-01T00:00'})
ok('projects.publish',{'id':pid,'due_at':'2099-01-02T00:00'})
rows=[x for x in ok('releases.list') if x['project_id']==pid]
assert sum(x['state']=='pending' for x in rows)==1
assert ok('projects.get?id='+str(pid))['active_version']==old
checks.append('scheduled releases replace prior plan without switching live code')
waitjob(ok('projects.delete',{'id':pid,'confirm':'DELETE','delete_source':False})['job'])
checks.append('project deletion and directory cleanup')
assert not (base/'projects'/str(pid)).exists()
# HTTP security/entrypoint checks
for path in ['/data/config.json','/.env','/app/service/Core.php','/../../etc/passwd']:
 try:r=opener.open('https://'+domain+path,timeout=10);assert r.status>=400,path
 except urllib.error.HTTPError as e:assert e.code in [400,403,404],(path,e.code)
checks.append('configuration and source inaccessible over HTTP')
print(json.dumps({'passed':checks,'count':len(checks)},ensure_ascii=False,indent=2))
