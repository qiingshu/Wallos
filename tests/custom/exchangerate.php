<?php
require __DIR__.'/../../includes/exchangerate_api.php';
require __DIR__.'/../../includes/currency_rates.php';
function check($v,$message) { if(!$v) throw new RuntimeException($message); }
$data=['result'=>'success','base_code'=>'USD','conversion_rates'=>['USD'=>1,'CNY'=>7.2,'EUR'=>0.9]];
$r=wallos_exchangerate_normalize($data,'CNY',['USD','CNY','EUR']);
check(abs(10/$r['USD']-72)<1e-9,'USD to CNY direction');
check(abs(9/$r['EUR']-72)<1e-9,'EUR to CNY cross rate');
check($r['CNY']===1.0,'main currency must equal 1');
foreach ([['result'=>'error'],['result'=>'success','base_code'=>'USD','conversion_rates'=>['CNY'=>0]],['result'=>'success','base_code'=>'USD','conversion_rates'=>['CNY'=>7.2,'USD'=>-1]]] as $bad) {
 try {wallos_exchangerate_normalize($bad,'CNY',['CNY','USD']);throw new LogicException('invalid response accepted');} catch(RuntimeException $e) {check(!($e instanceof LogicException),'reject invalid data');}
}
$dir=__DIR__.'/../../db';$id=987654321;$status="$dir/exchangerate-status-$id.json";
$db=new SQLite3(':memory:');$db->exec('CREATE TABLE user(id INTEGER,main_currency INTEGER);CREATE TABLE currencies(id INTEGER,code TEXT,rate REAL,user_id INTEGER);CREATE TABLE last_exchange_update(date TEXT,user_id INTEGER);');
$db->exec("INSERT INTO user VALUES($id,1);INSERT INTO currencies VALUES(1,'CNY',1,$id),(2,'USD',0.1,$id),(3,'EUR',0.12,$id)");
// Stub key path; no external network is used by these isolated tests.
$key=tempnam(sys_get_temp_dir(),'wallos-key');file_put_contents($key,'test-not-a-real-key');putenv('EXCHANGERATE_API_KEY_FILE='.$key);
try {
 $before=$db->querySingle('SELECT rate FROM currencies WHERE id=2');
 $a=wallos_exchangerate_refresh($db,$id,true,fn()=>['result'=>'error']);
 check(!$a['success'],'failure reported');check($db->querySingle('SELECT rate FROM currencies WHERE id=2')===$before,'failure must retain rates');
 $a=wallos_exchangerate_refresh($db,$id,true,fn()=>$data);check($a['success'],'valid refresh');
 $b=wallos_exchangerate_refresh($db,$id,true,function(){throw new LogicException('cache must avoid network');});check($b['cached']??false,'same-day cache');
 $db->exec("UPDATE user SET main_currency=3 WHERE id=$id");
 $a=wallos_exchangerate_refresh($db,$id,true,fn()=>$data);check($a['success'],'changed main currency rebases');
 check(abs($db->querySingle('SELECT rate FROM currencies WHERE id=2')-1/0.9)<1e-9,'rebased rate');
 echo "PASS cross-currency conversion, invalid responses, failure retention, daily cache, changed main currency\n";
} finally {if(is_file($status))unlink($status);unlink($key);}
