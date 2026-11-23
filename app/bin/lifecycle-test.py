exec(open('/www/wwwroot/StarCore/app/bin/smoke.py').read().split("expect_bad('projects.add',{'name':'bad'")[0])
import subprocess
php='/www/server/php/84/bin/php';fixture=str(base/'app/bin/fixture.php')
def addbot():
 r=subprocess.run([php,'-c',str(base/'data/cli.ini'),fixture,'add'],capture_output=True,text=True,check=True);return json.loads(r.stdout)['id']
p=ok('projects.add',{'name':'多机器人验收临时项目','language':'php','kind':'created'});pid=p['id']
ok('files.write',{'id':pid,'path':'bot.php','content':'<?php while($l=fgets(STDIN)) {echo "received\\n";}\n'})
ok('projects.publish',{'id':pid})
for n in range(100):
 p=ok('projects.get?id='+str(pid))
 if p['release_state']=='active':break
 time.sleep(.25)
assert p['release_state']=='active',p
b1,b2=addbot(),addbot()
for bid in [b1,b2]:
 waitjob(ok('bots.bind',{'id':bid,'project_id':pid})['job'])
 waitjob(ok('bots.start',{'id':bid})['job'])
for n in range(100):
 first=ok('bots.get?id='+str(b1));second=ok('bots.get?id='+str(b2))
 if first['state']==second['state']=='running':break
 time.sleep(.25)
assert first['pid'] and second['pid'] and first['pid']!=second['pid']
checks.append('one shared project starts two independent real robot processes')
ok('bots.config',{'id':b1,'config':{'maintenance_on':'2000-01-01T00:00'}})
for n in range(30):
 if ok('bots.get?id='+str(b1))['config']['maintenance']:break
 time.sleep(.2)
assert ok('bots.get?id='+str(b1))['config']['maintenance'] is True
assert ok('bots.get?id='+str(b2))['config']['maintenance'] is False
checks.append('scheduled maintenance switches only its own robot')
waitjob(ok('bots.stop',{'id':b2})['job'])
subprocess.run(['systemctl','restart','starcore'],check=True)
for n in range(100):
 first=ok('bots.get?id='+str(b1));second=ok('bots.get?id='+str(b2))
 if first['state']=='running':break
 time.sleep(.25)
assert first['state']=='running' and first['config']['maintenance'] is True
assert second['state']=='stopped' and second['desired']=='stopped'
checks.append('service restart restores running and maintenance state while respecting manual stop')
# Unbinding the second must not stop the first.
pid1=first['pid']
waitjob(ok('bots.bind',{'id':b2,'project_id':0})['job'])
assert ok('bots.get?id='+str(b1))['pid']==pid1 and ok('bots.get?id='+str(b2))['state']=='unbound'
checks.append('unbinding one robot does not interrupt another bound to same project')
# Source edits remain offline until publication; changing configuration is versioned.
old=ok('projects.get?id='+str(pid))['active_version']
ok('files.write',{'id':pid,'path':'bot.php','content':'<?php while($l=fgets(STDIN)) {echo "new-version\\n";}\n'})
ok('projects.publish',{'id':pid})
for n in range(100):
 p=ok('projects.get?id='+str(pid))
 if p['active_version']!=old and p['release_state']=='active':break
 time.sleep(.25)
assert p['active_version']!=old
assert ok('bots.get?id='+str(b1))['config']['maintenance'] is True
assert not (base/'projects'/str(pid)/'versions'/old).exists()
checks.append('successful publication preserves maintenance and removes previous running version')
for bid in [b1,b2]:waitjob(ok('bots.delete',{'id':bid,'confirm':'DELETE'})['job'])
waitjob(ok('projects.delete',{'id':pid,'confirm':'DELETE'})['job'])
print(json.dumps({'passed':checks,'count':len(checks)},ensure_ascii=False,indent=2))
