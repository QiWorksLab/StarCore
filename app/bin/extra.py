exec(open('/www/wwwroot/StarCore/app/bin/smoke.py').read().split("expect_bad('projects.add',{'name':'bad'")[0])
import zipfile,io,uuid
def upload(a,fields,filename,content):
 boundary='starcore-'+uuid.uuid4().hex
 buf=bytearray()
 for k,v in fields.items():buf.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+str(v)+'\r\n').encode())
 buf.extend(('--'+boundary+'\r\nContent-Disposition: form-data; name="file"; filename="'+filename+'"\r\nContent-Type: application/octet-stream\r\n\r\n').encode());buf.extend(content);buf.extend(('\r\n--'+boundary+'--\r\n').encode())
 req=urllib.request.Request('https://'+domain+'/api/'+a.replace('.','-'),data=bytes(buf),headers={'Content-Type':'multipart/form-data; boundary='+boundary,'X-CSRF-Token':csrf})
 try:r=opener.open(req,timeout=20);j=json.load(r)
 except urllib.error.HTTPError as e:j=json.load(e)
 assert j['ok'],j
 return j['data']
p=ok('projects.add',{'name':'ZIP验收临时项目','kind':'zip','language':'php'});pid=p['id']
buff=io.BytesIO()
with zipfile.ZipFile(buff,'w') as z:z.writestr('bot.php','<?php while($l=fgets(STDIN)){echo "ok\\n";}')
r=upload('files.upload',{'id':pid,'extract':1,'path':''},'code.zip',buff.getvalue());waitjob(r['job'])
assert 'while' in ok('files.read?id='+str(pid)+'&path=bot.php')['content']
checks.append('real multipart ZIP upload and extraction')
buff=io.BytesIO()
with zipfile.ZipFile(buff,'w') as z:z.writestr('../escape.txt','bad')
r=upload('files.upload',{'id':pid,'extract':1,'path':''},'bad.zip',buff.getvalue())
for n in range(40):
 j=ok('jobs.get?id='+str(r['job']))
 if j['state']=='failed':break
 time.sleep(.25)
assert j['state']=='failed',j
checks.append('ZIP traversal rejected before extraction')
ok('files.mkdir',{'id':pid,'path':'sub'})
ok('files.write',{'id':pid,'path':'sub/sample.env','content':'HELLO=world\n'})
ok('files.rename',{'id':pid,'path':'sub/sample.env','to':'sub/renamed.env'})
assert ok('files.read?id='+str(pid)+'&path=sub/renamed.env')['content']=='HELLO=world\n'
ok('files.delete',{'id':pid,'path':'sub','confirm':'DELETE'})
checks.append('directory creation, file rename and recursive deletion')
waitjob(ok('projects.delete',{'id':pid,'confirm':'DELETE'})['job'])
# Create a restricted administrator for permission checks.
name='qa_'+uuid.uuid4().hex[:8];password=uuid.uuid4().hex
ok('users.save',{'username':name,'password':password,'role':'admin','enabled':True})
users=ok('users.list');uid=next(u['id'] for u in users if u['username']==name)
csrf=ok('login',{'username':name,'password':password})['csrf']
expect_bad('users.save',{'id':uid,'username':name,'role':'super','enabled':True})
expect_bad('settings.save',{'base_url':'https://example.com','maintenance_text':'bad','exempt':[]})
assert ok('bots.list') is not None
checks.append('administrator can access robots but cannot escalate role or edit global settings')
csrf=ok('login',{'username':'admin','password':passwd})['csrf']
# Fixture account is disposable; remove through direct dedicated DB, no production account affected.
import subprocess
code='require "/www/wwwroot/StarCore/app/app/service/Core.php";app\\service\\Core::sql("DELETE FROM users WHERE id=? AND username=?",['+str(uid)+',"'+name+'"]);'
subprocess.run(['/www/server/php/84/bin/php','-r',code],capture_output=True,check=True)
print(json.dumps({'passed':checks,'count':len(checks)},ensure_ascii=False,indent=2))
