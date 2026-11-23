exec(open('/www/wwwroot/StarCore/app/bin/smoke.py').read().split("expect_bad('projects.add',{'name':'bad'")[0])
import subprocess,uuid
src=pathlib.Path('/www/wwwroot/starcore-qa-source-'+uuid.uuid4().hex[:8])
src.mkdir();(src/'bot.php').write_text('<?php while($line=fgets(STDIN)) {echo "handled\\n";}\n');(src/'old.txt').write_text('old')
subprocess.run(['chown','-R','www:www',str(src)],check=True)
p=ok('projects.add',{'name':'原目录验收临时项目','kind':'direct','path':str(src),'language':'php'});pid=p['id'];waitjob(p['job'])
ok('files.write',{'id':pid,'path':'bot.php','content':'<?php while($line=fgets(STDIN)) {echo "new\\n";}\n'})
ok('files.delete',{'id':pid,'path':'old.txt','confirm':'DELETE'})
assert 'handled' in (src/'bot.php').read_text() and (src/'old.txt').exists()
checks.append('direct-source draft edits do not change original before publication')
ok('projects.publish',{'id':pid})
for n in range(120):
 p=ok('projects.get?id='+str(pid))
 if p['release_state']=='active':break
 time.sleep(.25)
assert p['release_state']=='active',p
assert 'new' in (src/'bot.php').read_text() and not (src/'old.txt').exists()
checks.append('successful release synchronizes changes and deletions back to source')
waitjob(ok('projects.delete',{'id':pid,'confirm':'DELETE','delete_source':False})['job'])
assert src.exists() and (src/'bot.php').exists()
checks.append('deleting direct project with preserve choice retains original source')
import shutil;shutil.rmtree(src)
print(json.dumps({'passed':checks,'count':len(checks)},ensure_ascii=False,indent=2))
