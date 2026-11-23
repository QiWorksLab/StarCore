exec(open('/www/wwwroot/StarCore/app/bin/smoke.py').read().split("expect_bad('projects.add',{'name':'bad'")[0])
import subprocess,os
php='/www/server/php/84/bin/php';fixture=str(base/'app/bin/fixture.php')
def fixture_call(a,i=0):
 r=subprocess.run([php,fixture,a,str(i)],capture_output=True,text=True,check=True);return json.loads(r.stdout)
subprocess.run([php,'-d','zend.assertions=1','-d','assert.exception=1',fixture,'rules'],check=True)
checks.append('private/group/channel filtering and maintenance exemption routing')
p=ok('projects.add',{'name':'进程验收临时项目','language':'python','kind':'created'});pid=p['id']
# A synthetic robot fixture never sends Telegram messages; it tests platform transport and actual child processes.
ok('files.write',{'id':pid,'path':'bot.py','content':'import sys,json,time\nfor line in sys.stdin:\n u=json.loads(line)\n print("handled:"+str(u["update_id"]),flush=True)\n'})
ok('projects.publish',{'id':pid})
def waitproject(error=False):
 for n in range(160):
  pr=ok('projects.get?id='+str(pid))
  if error and pr['release_error']:return pr
  if not error and pr['release_state']=='active':return pr
  time.sleep(.25)
 raise AssertionError(pr)
waitproject()
b=fixture_call('add');bid=b['id']
waitjob(ok('bots.bind',{'id':bid,'project_id':pid})['job'])
waitjob(ok('bots.start',{'id':bid})['job'])
for i in range(80):
 bot=ok('bots.get?id='+str(bid))
 if bot['state']=='running':break
 time.sleep(.25)
assert bot['pid'] and bot['state']=='running',bot
checks.append('Python venv build and independent real child process')
def webhook(update,secret=None):
 req=urllib.request.Request('https://'+domain+'/hook/'+b['hook_key'],data=json.dumps(update).encode(),headers={'Content-Type':'application/json','X-Telegram-Bot-Api-Secret-Token':secret or b['secret']})
 try:r=opener.open(req,timeout=10);return r.status
 except urllib.error.HTTPError as e:return e.code
assert webhook({'update_id':100,'message':{'chat':{'id':1,'type':'private'},'from':{'id':42},'text':'test'}},'wrong')==403
assert webhook({'update_id':101,'message':{'chat':{'id':1,'type':'private'},'from':{'id':42},'text':'test'}})==200
for i in range(30):
 logs=ok('logs.get?id='+str(bid)+'&kind=stdout')['content']
 if 'handled:101' in logs:break
 time.sleep(.2)
assert 'handled:101' in logs,logs
webhook({'update_id':101,'message':{'chat':{'id':1,'type':'private'},'text':'duplicate'}})
time.sleep(.5)
assert ok('logs.get?id='+str(bid)+'&kind=stdout')['content'].count('handled:101')==1
checks.append('Webhook secret authentication, Redis delivery and update deduplication')
expect_bad('bots.config',{'id':bid,'config':{'allow':['1'],'deny':['1']}})
ok('bots.config',{'id':bid,'config':{'deny':['1']}})
webhook({'update_id':102,'message':{'chat':{'id':1,'type':'private'},'text':'blocked'}});time.sleep(1)
assert 'handled:102' not in ok('logs.get?id='+str(bid)+'&kind=stdout')['content']
ok('bots.config',{'id':bid,'config':{'deny':[]}})
checks.append('live Chat ID filter blocks delivery and conflicting lists rejected')
old=ok('projects.get?id='+str(pid))['active_version']
ok('files.write',{'id':pid,'path':'bot.py','content':'raise RuntimeError("release fixture fails")\n'})
ok('projects.publish',{'id':pid})
pr=waitproject(True);assert pr['active_version']==old,pr
for n in range(80):
 bot=ok('bots.get?id='+str(bid))
 if bot['state']=='running':break
 time.sleep(.25)
assert bot['state']=='running',bot
checks.append('new business process crash rolls back old code and restores running robot')
waitjob(ok('bots.stop',{'id':bid})['job']);time.sleep(2)
assert ok('bots.get?id='+str(bid))['state']=='stopped'
checks.append('manual stop does not trigger auto-restart')
# Successful publication while stopped, then exercise five consecutive failures.
ok('files.write',{'id':pid,'path':'bot.py','content':'import time\ntime.sleep(.2)\nraise RuntimeError("retry fixture")\n'})
ok('projects.publish',{'id':pid})
# previous release_error clears when build starts; wait pending release state
time.sleep(1);waitproject()
waitjob(ok('bots.start',{'id':bid})['job'])
for n in range(240):
 bot=ok('bots.get?id='+str(bid))
 if bot['state']=='error' and bot['failures']==5:break
 time.sleep(.25)
assert bot['state']=='error' and bot['failures']==5 and bot['desired']=='stopped',bot
logs=ok('logs.get?id='+str(bid)+'&kind=system')['content']
checks.append('real abnormal exits retry every 10 seconds and stop after five failures')
waitjob(ok('bots.delete',{'id':bid,'confirm':'DELETE'})['job'])
waitjob(ok('projects.delete',{'id':pid,'confirm':'DELETE','delete_source':False})['job'])
print(json.dumps({'passed':checks,'count':len(checks)},ensure_ascii=False,indent=2))
