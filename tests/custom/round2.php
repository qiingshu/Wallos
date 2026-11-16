<?php
require __DIR__.'/../../includes/exchangerate_api.php';
function ok($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
$db=new SQLite3(':memory:');$db->enableExceptions(true);
$db->exec('CREATE TABLE user(id INTEGER,main_currency INTEGER,api_key TEXT);CREATE TABLE currencies(id INTEGER,code TEXT,rate REAL,user_id INTEGER);CREATE TABLE fixer(provider INTEGER,api_key TEXT,user_id INTEGER);CREATE TABLE last_exchange_update(date TEXT,user_id INTEGER);CREATE TABLE google_search(id INTEGER PRIMARY KEY,user_id INTEGER,api_key TEXT);CREATE TABLE ai_settings(user_id INTEGER,type TEXT,enabled INTEGER,api_key TEXT,model TEXT,url TEXT,run_schedule TEXT);');
$db->exec("INSERT INTO user VALUES(987654321,1,'user-test');INSERT INTO currencies VALUES(1,'CNY',1,987654321),(2,'USD',0.1,987654321);INSERT INTO fixer VALUES(4,'',987654321);INSERT INTO ai_settings VALUES(987654321,'chatgpt',0,'ai-old','test-model','','weekly')");
$uid=987654321;wallos_credentials_init($db);wallos_credential_put($db,'exchange4',$uid,'test-sentinel-rate-key');
ok(wallos_credential_get($db,'exchange4',$uid)==='test-sentinel-rate-key','credential save');wallos_credential_put($db,'exchange4',$uid,'');ok(wallos_credential_get($db,'exchange4',$uid)==='test-sentinel-rate-key','blank preserves');
wallos_credential_put($db,'exchange4',$uid,'new-sentinel-rate-key');ok(wallos_credential_get($db,'exchange4',$uid)==='new-sentinel-rate-key','replace');
$db->exec("CREATE TRIGGER fail_secret BEFORE UPDATE ON credential_values WHEN NEW.value='reject' BEGIN SELECT RAISE(FAIL,'simulated');END");
try{wallos_credential_put($db,'exchange4',$uid,'reject');throw new LogicException('accepted');}catch(RuntimeException $e){}catch(Exception $e){}
ok(wallos_credential_get($db,'exchange4',$uid)==='new-sentinel-rate-key','failed save preserves old credential');
$requests=0;$fetch=function()use(&$requests){$requests++;return ['result'=>'success','base_code'=>'USD','conversion_rates'=>['USD'=>1,'CNY'=>7.2,'EUR'=>0.9]];};
$a=wallos_exchangerate_refresh($db,$uid,false,$fetch);ok($a['success']&&$requests===1,'initial rate update');
$a=wallos_exchangerate_refresh($db,$uid,false,$fetch);ok($a['cached']&&$requests===1,'ordinary request uses daily cache');
$a=wallos_exchangerate_refresh($db,$uid,true,$fetch);ok($a['success']&&$requests===2,'explicit force bypasses daily cache');
$before=$db->querySingle('SELECT rate FROM currencies WHERE id=2');$a=wallos_exchangerate_refresh($db,$uid,true,fn()=>['result'=>'error']);ok(!$a['success']&&$db->querySingle('SELECT rate FROM currencies WHERE id=2')===$before,'failure keeps rates');
wallos_credential_put($db,'exchange4',$uid,'another-test-key');$a=wallos_exchangerate_refresh($db,$uid,false,$fetch);ok($a['success']&&empty($a['cached']),'key replacement invalidates cache');
$db->exec("INSERT INTO currencies VALUES(3,'EUR',1,987654321)");$a=wallos_exchangerate_refresh($db,$uid,false,$fetch);ok($a['success']&&empty($a['cached']),'new currency invalidates cache');
$db->exec('UPDATE user SET main_currency=3');$a=wallos_exchangerate_refresh($db,$uid,false,$fetch);ok($a['success']&&abs($db->querySingle('SELECT rate FROM currencies WHERE id=2')-1/0.9)<1e-9,'main-currency change rebases once');
$db->exec('UPDATE user SET main_currency=1');$a=wallos_exchangerate_refresh($db,$uid,true,fn()=>['result'=>'error']);ok(!$a['success']&&abs($db->querySingle('SELECT rate FROM currencies WHERE id=2')-1/7.2)<1e-9,'failed refresh still rebases valid cached table for new main currency');
wallos_credential_put($db,'exchange0',$uid,'mock-fixer-key');$db->exec('UPDATE fixer SET provider=0');$a=wallos_exchangerate_refresh($db,$uid,false,fn()=>['rates'=>['USD'=>1,'CNY'=>7.2,'EUR'=>0.9]]);ok($a['success']&&empty($a['cached']),'provider switch invalidates cache');
$db->exec('UPDATE fixer SET provider=4');$a=wallos_exchangerate_refresh($db,$uid,true,$fetch);$rateBefore=$db->querySingle('SELECT rate FROM currencies WHERE id=2');
$a=wallos_exchangerate_refresh($db,$uid,true,function()use($db){$db->exec("INSERT INTO currencies VALUES(4,'GBP',1,987654321)");return ['result'=>'success','base_code'=>'USD','conversion_rates'=>['USD'=>1,'CNY'=>8,'EUR'=>0.9]];});ok(!$a['success']&&$db->querySingle('SELECT rate FROM currencies WHERE id=2')===$rateBefore,'configuration changed during request rejects stale result');$db->exec('DELETE FROM currencies WHERE id=4');
$lock=wallos_exchange_lock($uid);$n=$requests;$a=wallos_exchangerate_refresh($db,$uid,true,$fetch);ok(!empty($a['busy'])&&$requests===$n,'concurrent refresh rejected without extra request');flock($lock,LOCK_UN);fclose($lock);
wallos_credential_put($db,'exchange4',$uid,'',true);$n=$requests;$a=wallos_exchangerate_refresh($db,$uid,true,$fetch);ok(!$a['success']&&$requests===$n,'delete stops requests and retains cache');
putenv('EXCHANGERATE_API_KEY=old-env-must-not-resurrect');ok(wallos_exchangerate_key($db,$uid)==='','deleted key never falls back to environment');
$r=wallos_credential_probe($db,$uid,'ai','ai-new',function(){throw new LogicException('paid call');});ok(!$r['success'],'AI off blocks manual test without paid call');
wallos_credential_put($db,'ai',$uid,'',true);ok($db->querySingle('SELECT enabled FROM ai_settings')===0&&$db->querySingle('SELECT run_schedule FROM ai_settings')==='weekly','AI delete disables but preserves schedule');
wallos_credential_put($db,'serpapi',$uid,'serp-test');ok(wallos_credential_get($db,'serpapi',$uid)==='serp-test','first-time credentials create a persistent row');
echo "ALL TARGETED UNIT CHECKS PASSED\n";
