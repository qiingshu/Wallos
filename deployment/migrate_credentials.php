<?php
// Run once before first start with the old mounts available. Never prints values.
require_once __DIR__.'/../includes/exchangerate_api.php';
$db=new SQLite3($argv[1]??'/var/www/html/db/wallos.db');$db->enableExceptions(true);$db->busyTimeout(5000);wallos_credentials_init($db);
if(wallos_credentials_migrated($db)){echo "Already migrated; no old credential was imported.\n";exit;}
function old_setting($name){$file=getenv($name.'_FILE');if($file&&is_readable($file))return trim(file_get_contents($file));$v=getenv($name);return $v===false?null:$v;}
$db->exec('BEGIN IMMEDIATE');
try {
 foreach(['smtp_address'=>'WALLOS_SMTP_HOST','smtp_port'=>'WALLOS_SMTP_PORT','encryption'=>'WALLOS_SMTP_ENCRYPTION','smtp_username'=>'WALLOS_SMTP_USERNAME','smtp_password'=>'WALLOS_SMTP_PASSWORD','from_email'=>'WALLOS_SMTP_FROM','server_url'=>'WALLOS_SERVER_URL','telegram_bot_token'=>'WALLOS_TELEGRAM_BOT_TOKEN','ntfy_headers'=>'WALLOS_NTFY_HEADERS','ntfy_server'=>'WALLOS_NTFY_SERVER','pushover_token'=>'WALLOS_PUSHOVER_TOKEN'] as $column=>$env) {
  $v=old_setting($env);if($v===null)continue;
  $q=$db->prepare('UPDATE admin SET "'.$column.'"=:v');$q->bindValue(':v',$v,SQLITE3_TEXT);if(!$q->execute())throw new RuntimeException();
  if(in_array($column,['smtp_address','smtp_port','encryption','smtp_username','smtp_password','from_email'],true)){
   $q=$db->prepare('UPDATE email_notifications SET "'.$column.'"=:v WHERE user_id=1');$q->bindValue(':v',$v,SQLITE3_TEXT);if(!$q->execute())throw new RuntimeException();
  }
 }
 $key=old_setting('EXCHANGERATE_API_KEY');if($key!==null)wallos_credential_put($db,'exchange4',1,$key,false,false);
 $rows=$db->query('SELECT user_id,provider,api_key FROM fixer');while($r=$rows->fetchArray(SQLITE3_ASSOC))if(in_array((int)$r['provider'],[0,1],true)&&$r['api_key']!=='')wallos_credential_put($db,'exchange'.$r['provider'],(int)$r['user_id'],$r['api_key'],false,false);
 $v=old_setting('OIDC_CLIENT_SECRET');if($v!==null){$q=$db->prepare('UPDATE oauth_settings SET client_secret=:v');$q->bindValue(':v',$v,SQLITE3_TEXT);$q->execute();}
 wallos_exchange_init($db);
 foreach(glob(dirname($argv[1]??'/var/www/html/db/wallos.db').'/exchangerate-status-*.json') as $path){
  $uid=(int)preg_replace('/\D/','',basename($path));$status=json_decode(file_get_contents($path),true);
  if($uid>0&&is_array($status)&&!empty($status['last_success'])){$status['provider']=4;$codes=$db->query('SELECT code FROM currencies WHERE user_id='.$uid.' ORDER BY code');$status['codes']=[];while($v=$codes->fetchArray(SQLITE3_NUM))$status['codes'][]=$v[0];wallos_exchange_store_status($db,$uid,$status);}
 }
 $db->exec("INSERT INTO credential_meta(name,owner_id,generation,deleted) VALUES('__migration__',0,1,0)");
 if(!$db->exec('COMMIT'))throw new RuntimeException();echo "Credential migration completed; database-only mode is persistent.\n";
}catch(Throwable $e){$db->exec('ROLLBACK');fwrite(STDERR,"Migration failed; original configuration retained.\n");exit(1);}
