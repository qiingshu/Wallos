#!/usr/bin/python3
"""Restore only this deployment's config; never restore an older user database."""
import pathlib,sqlite3,json,subprocess,shutil
root=pathlib.Path('/opt/wallos');saved=root/'rollback-official-5.11.1'
subprocess.run(['python3',str(root/'backup.py')],check=True)
snap=json.loads((saved/'config-snapshot.private.json').read_text())
db=sqlite3.connect(root/'db/wallos.db')
with db:
    for table in ['fixer','email_notifications']:
        db.execute('DELETE FROM '+table+' WHERE user_id=1')
        for row in snap[table]:
            if row['user_id'] != 1: continue
            fields=list(row)
            db.execute('INSERT INTO '+table+'('+','.join(fields)+') VALUES('+','.join('?' for _ in fields)+')',list(row.values()))
    for table,columns in [('settings',['mobile_nav','convert_currency']),('notification_settings',['days'])]:
        rows=[r for r in snap[table] if r.get('user_id')==1]
        if rows:
            row=rows[0];db.execute('UPDATE '+table+' SET '+','.join(c+'=?' for c in columns)+' WHERE user_id=1',[row[c] for c in columns])
db.close()
shutil.copy2(saved/'compose.json',root/'compose.json')
subprocess.run(['docker','compose','-p','wallos','-f',str(root/'compose.json'),'up','-d'],check=True)
print('Official 5.11.1 restored; current accounts, passwords, subscriptions and icons preserved.')
