#!/usr/bin/env python3
"""Root-only rollback preserves the live DB and adapts current credentials to v1 mounts."""
import json,sqlite3,pathlib,os,subprocess,sys,shutil
p=pathlib.Path(os.environ.get('WALLOS_ROOT','/opt/wallos'));r=p/'rollback-round2';a=r/'active-secrets';a.mkdir(mode=0o700,exist_ok=True)
db=sqlite3.connect(p/'db/wallos.db');db.row_factory=sqlite3.Row
admin=dict(db.execute('select * from admin limit 1').fetchone());row=db.execute("select value from credential_values where name='exchange4' and owner_id=1").fetchone();exchange=row[0] if row else ''
for name,value in [('exchange',exchange),('resend',admin.get('smtp_password') or '')]:
 f=a/name;f.write_text(value);os.chown(f,82,82);os.chmod(f,0o400)
for row in db.execute('select user_id,provider from fixer').fetchall():
 if row['provider'] in (0,1):
  key=db.execute('select value from credential_values where name=? and owner_id=?',('exchange'+str(row['provider']),row['user_id'])).fetchone();db.execute('update fixer set api_key=? where user_id=?',(key[0] if key else '',row['user_id']))
status_rows=db.execute('select user_id,payload from exchange_refresh_status').fetchall() if db.execute("select 1 from sqlite_master where name='exchange_refresh_status'").fetchone() else []
for row in status_rows:
 f=p/'db'/('exchangerate-status-'+str(row['user_id'])+'.json');f.write_text(row['payload']);os.chown(f,82,82);os.chmod(f,0o640)
db.commit();db.close()
c=json.load(open(r/'compose.json'));s=c['services']['wallos'];env=s['environment'];env=env if isinstance(env,dict) else dict(v.split('=',1) for v in env)
for key,column in {'WALLOS_SMTP_HOST':'smtp_address','WALLOS_SMTP_PORT':'smtp_port','WALLOS_SMTP_ENCRYPTION':'encryption','WALLOS_SMTP_USERNAME':'smtp_username','WALLOS_SMTP_FROM':'from_email','WALLOS_SERVER_URL':'server_url'}.items():env[key]=str(admin.get(column) or '')
s['environment']=env
s['volumes']=[str(a/'exchange')+':'+v.split(':',1)[1] if '/secrets/exchange:' in v else str(a/'resend')+':'+v.split(':',1)[1] if '/secrets/resend:' in v else v for v in s['volumes']]
f=r/'compose.rollback.generated.json';f.write_text(json.dumps(c));os.chmod(f,0o600)
# Keep the live database and icons: never restore an old snapshot over new subscriptions.
if '--prepare-only' not in sys.argv:
 current=p/'compose.json'
 if current.exists(): shutil.copyfile(current,r/'compose.before-rollback.json')
 pending=p/'compose.rollback.tmp';pending.write_text(json.dumps(c));os.chmod(pending,0o600);pending.replace(current)
 subprocess.run(['docker','compose','-p','wallos','-f',str(current),'up','-d','--no-deps','--pull','never','wallos'],check=True)
 if (r/'release.json').exists():
  previous=json.loads((r/'release.json').read_text());previous['rollback_note']='Current live database and current effective credentials preserved'
  (p/'CUSTOM-RELEASE.json').write_text(json.dumps(previous,indent=2));os.chmod(p/'CUSTOM-RELEASE.json',0o600)

print('Compatibility configuration prepared; no container changed.' if '--prepare-only' in sys.argv else 'Previous version restored with current effective credentials; live data retained.')
