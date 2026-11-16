<?php
/* Existing integration secrets stay in their server-side SQLite columns. Only the
   added ExchangeRate-API provider needs its own value row. No shadow env fallback. */
function wallos_credential_catalog() {
 $out=[];
 foreach ([
 ['smtp','admin','smtp_password','站点 SMTP 密码',0,'smtppassword'],
 ['oidc','oauth_settings','client_secret','OIDC Client Secret',0,'oidcClientSecret'],
 ['instance_telegram','admin','telegram_bot_token','站点 Telegram Bot Token',0,'telegrambottoken'],
 ['instance_ntfy','admin','ntfy_headers','站点 ntfy 认证请求头',0,'ntfyheaders'],
 ['instance_pushover','admin','pushover_token','站点 Pushover Token',0,'pushovertoken'],
 ['email','email_notifications','smtp_password','邮件提醒 SMTP 密码',1,'smtppassword'],
 ['telegram','telegram_notifications','bot_token','Telegram Bot Token',1,'telegrambottoken'],
 ['gotify','gotify_notifications','token','Gotify Token',1,'gotifytoken'],
 ['pushover','pushover_notifications','token','Pushover Token',1,'pushovertoken'],
 ['pushplus','pushplus_notifications','token','PushPlus Token',1,'pushplustoken'],
 ['serverchan','serverchan_notifications','sendkey','Server酱 SendKey',1,'serverchansendkey'],
 ['ntfy','ntfy_notifications','headers','ntfy 认证请求头',1,'ntfyheaders'],
 ['webhook','webhook_notifications','url','Webhook 认证地址',1,'webhookurl'],
 ['discord','discord_notifications','webhook_url','Discord Webhook',1,'discordurl'],
 ['mattermost','mattermost_notifications','webhook_url','Mattermost Webhook',1,'mattermostwebhookurl'],
 ['ai','ai_settings','api_key','AI API 密钥',1,'ai_api_key'],
 ['serpapi','google_search','api_key','SerpAPI 密钥',1,'googleSearchKey'],
 ['user_api','user','api_key','个人 API 密钥',1,'apikey'],
 ['exchange0','credential_values','value','Fixer 密钥',1,'fixerKey'],
 ['exchange1','credential_values','value','APILayer 密钥',1,'fixerKey'],
 ['exchange4','credential_values','value','ExchangeRate-API 密钥',1,'fixerKey'],
 ] as $r) $out[$r[0]]=['table'=>$r[1],'column'=>$r[2],'label'=>$r[3],'personal'=>(bool)$r[4],'input'=>$r[5]];
 foreach ([['pushover_user','pushover_notifications','user_key','Pushover 用户密钥',1,'pushoveruserkey'],['webhook_headers','webhook_notifications','headers','Webhook 认证请求头',1,'webhookcustomheaders'],['instance_gotify','admin','gotify_server','站点 Gotify 认证地址',0,'gotifyserver']] as $r) $out[$r[0]]=['table'=>$r[1],'column'=>$r[2],'label'=>$r[3],'personal'=>(bool)$r[4],'input'=>$r[5]];
 return $out;
}
function wallos_credentials_init($db) {
 $db->exec('CREATE TABLE IF NOT EXISTS credential_meta(name TEXT,owner_id INTEGER,generation INTEGER NOT NULL DEFAULT 0,deleted INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(name,owner_id))');
 $db->exec('CREATE TABLE IF NOT EXISTS credential_values(name TEXT,owner_id INTEGER,value TEXT NOT NULL DEFAULT "",PRIMARY KEY(name,owner_id))');
}
function wallos_credentials_migrated($db) {
 return $db && $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='credential_meta'") && $db->querySingle("SELECT COUNT(*) FROM credential_meta WHERE name='__migration__' AND owner_id=0")>0;
}
function wallos_credential_owner($name,$uid) {return wallos_credential_catalog()[$name]['personal']?(int)$uid:0;}
function wallos_credential_get($db,$name,$uid) {
 $entry=wallos_credential_catalog()[$name]??null;if(!$entry) return '';
 $owner=wallos_credential_owner($name,$uid);
 if($entry['table']==='credential_values') {
  if(!$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='credential_values'"))return '';
  $q=$db->prepare('SELECT value FROM credential_values WHERE name=:name AND owner_id=:id');$q->bindValue(':name',$name,SQLITE3_TEXT);
 } else {
  $where=$entry['personal']?($entry['table']==='user'?'id':'user_id').'=:id':'1=1';
  if($entry['table']==='fixer')$where.=' AND provider='.(int)substr($name,-1);
  $q=$db->prepare('SELECT "'.$entry['column'].'" FROM "'.$entry['table'].'" WHERE '.$where.' LIMIT 1');
 }
 if(!$q)return ''; if($entry['personal'] || $entry['table']==='credential_values')$q->bindValue(':id',$entry['personal']?(int)$uid:$owner,SQLITE3_INTEGER);
 $r=$q->execute()->fetchArray(SQLITE3_NUM);return $r?(string)($r[0]??''):'';
}
function wallos_credential_deleted($db,$name,$uid) {
 if(!$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='credential_meta'"))return false;
 $q=$db->prepare('SELECT deleted FROM credential_meta WHERE name=:name AND owner_id=:id');$q->bindValue(':name',$name,SQLITE3_TEXT);$q->bindValue(':id',wallos_credential_owner($name,$uid),SQLITE3_INTEGER);return (bool)($q->execute()->fetchArray(SQLITE3_NUM)[0]??0);
}
function wallos_credential_generation($db,$name,$uid) {
 if(!isset(wallos_credential_catalog()[$name]))return 0;
 if(!$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='credential_meta'"))return 0;
 $q=$db->prepare('SELECT generation FROM credential_meta WHERE name=:name AND owner_id=:id');$q->bindValue(':name',$name,SQLITE3_TEXT);$q->bindValue(':id',wallos_credential_owner($name,$uid),SQLITE3_INTEGER);return (int)($q->execute()->fetchArray(SQLITE3_NUM)[0]??0);
}
function wallos_credential_put($db,$name,$uid,$value,$delete=false,$ownTransaction=true) {
 $e=wallos_credential_catalog()[$name]??null;if(!$e)throw new RuntimeException('未知配置项');
 if(!$delete && $value==='')return; // Empty means preserve, never delete.
 wallos_credentials_init($db);$owner=wallos_credential_owner($name,$uid);
 if($ownTransaction && !$db->exec('BEGIN IMMEDIATE'))throw new RuntimeException('配置正在更新，请稍后重试');
 try {
  if($e['table']==='credential_values') {
   $q=$db->prepare('INSERT INTO credential_values(name,owner_id,value) VALUES(:name,:id,:value) ON CONFLICT(name,owner_id) DO UPDATE SET value=excluded.value');$q->bindValue(':name',$name,SQLITE3_TEXT);$q->bindValue(':id',$owner,SQLITE3_INTEGER);
  } else {
   $where=$e['personal']?($e['table']==='user'?'id':'user_id').'=:id':'1=1';
   if($e['table']==='fixer')$where.=' AND provider='.(int)substr($name,-1);
   $q=$db->prepare('UPDATE "'.$e['table'].'" SET "'.$e['column'].'"=:value WHERE '.$where);if($e['personal'])$q->bindValue(':id',(int)$uid,SQLITE3_INTEGER);
  }
  $q->bindValue(':value',$delete?'':$value,SQLITE3_TEXT);if(!$q->execute())throw new RuntimeException('保存失败');
  if (!$delete && $e['personal'] && $e['table']!=='credential_values' && $e['table']!=='user' && $db->changes()===0) {
   if($e['table']==='ai_settings') $q=$db->prepare('INSERT INTO ai_settings(user_id,type,enabled,api_key,model,run_schedule) VALUES(:id,"chatgpt",0,:value,"","manual")');
   else $q=$db->prepare('INSERT INTO "'.$e['table'].'"(user_id,"'.$e['column'].'") VALUES(:id,:value)');
   $q->bindValue(':id',(int)$uid,SQLITE3_INTEGER);$q->bindValue(':value',$value,SQLITE3_TEXT);if(!$q->execute())throw new RuntimeException('保存失败');
  }
  if($delete && $name==='ai'){$q=$db->prepare('UPDATE ai_settings SET enabled=0 WHERE user_id=:id');$q->bindValue(':id',(int)$uid,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException('保存失败');}
  if($delete && $e['personal'] && str_ends_with($e['table'],'_notifications')){$q=$db->prepare('UPDATE "'.$e['table'].'" SET enabled=0 WHERE user_id=:id');$q->bindValue(':id',(int)$uid,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException('保存失败');}
  $q=$db->prepare('INSERT INTO credential_meta(name,owner_id,generation,deleted) VALUES(:name,:id,1,:deleted) ON CONFLICT(name,owner_id) DO UPDATE SET generation=generation+1,deleted=excluded.deleted');$q->bindValue(':name',$name,SQLITE3_TEXT);$q->bindValue(':id',$owner,SQLITE3_INTEGER);$q->bindValue(':deleted',$delete?1:0,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException('保存失败');
  if($ownTransaction && !$db->exec('COMMIT'))throw new RuntimeException('保存失败');
 }catch(Throwable $e){if($ownTransaction)$db->exec('ROLLBACK');throw $e;}
}
function wallos_credential_merge($db,$uid,$data,$script=null) {
 $base=basename($script??($_SERVER['SCRIPT_NAME']??''));$map=[];
 foreach(['email'=>['smtppassword'=>'email'],'gotify'=>['token'=>'gotify'],'pushover'=>['token'=>'pushover','user_key'=>'pushover_user'],'telegram'=>['bot_token'=>'telegram'],'pushplus'=>['token'=>'pushplus'],'serverchan'=>['sendkey'=>'serverchan'],'ntfy'=>['headers'=>'ntfy'],'webhook'=>['webhook_url'=>'webhook','headers'=>'webhook_headers'],'discord'=>['url'=>'discord'],'mattermost'=>['webhook_url'=>'mattermost']] as $type=>$fields) {
  if(in_array($base,['save'.$type.'notifications.php','test'.$type.'notifications.php'],true))$map=$fields;
 }
 foreach(['savesmtpsettings.php'=>['smtppassword'=>'smtp'],'saveoidcsettings.php'=>['oidcClientSecret'=>'oidc'],'saveinstancetelegram.php'=>['telegrambottoken'=>'instance_telegram'],'saveinstancentfy.php'=>['ntfyheaders'=>'instance_ntfy'],'saveinstancepushover.php'=>['pushovertoken'=>'instance_pushover'],'saveinstancegotify.php'=>['gotifyserver'=>'instance_gotify']] as $route=>$fields)if($base===$route)$map=$fields;
 if($base==='testtelegramnotifications.php')$map=['bottoken'=>'telegram'];
 if($base==='testwebhooknotifications.php')$map=['url'=>'webhook','customheaders'=>'webhook_headers'];
 foreach($map as $field=>$name)if(!isset($data[$field]) || trim((string)$data[$field])==='')$data[$field]=wallos_credential_get($db,$name,$uid);
 return $data;
}
function wallos_credential_statuses($db,$uid) {
 $out=[];foreach(wallos_credential_catalog() as $name=>$e) {
  if(!$e['personal'] && $uid!==1)continue;
  $out[$name]=['configured'=>wallos_credential_get($db,$name,$uid)!=='','label'=>$e['label'],'input'=>$e['input'],'site'=>!$e['personal']];
 }return $out;
}
function wallos_credential_response_guard($db) {
 if(PHP_SAPI==='cli')return;
 header('Cache-Control: no-store, private');header('Pragma: no-cache');ini_set('display_errors','0');
 $submitted=json_decode(file_get_contents('php://input'),true);$submitted=array_merge($_POST,is_array($submitted)?$submitted:[]);
 $known=[];$uid=(int)($_SESSION['userId']??0);if($uid>0)foreach(wallos_credential_catalog() as $name=>$entry){$v=wallos_credential_get($db,$name,$uid);if(strlen($v)>3)$known[]=$v;}
 ob_start(function($output) use($db,$submitted,$known) {
  $data=json_decode($output,true);
  if(!is_array($data))return $output;
  $clean=function($v,$parent="") use(&$clean) {
   if(!is_array($v))return $v;
   foreach($v as $k=>$item) {
    if(preg_match('/^(api_?key|fixer_api_key|client_?secret|smtp_?password|password|bot_token|user_key|sendkey|token|vapid_private_key|telegram_bot_token|pushover_token|ntfy_headers|headers|webhook_url)$/i',(string)$k))$v[$k]=in_array($item,['已配置','未配置','无需密钥'],true)?$item:(empty($item)?'未配置':'已配置');
    else if(($k==='url'&&str_contains($parent,'webhook'))||$k==='gotify_server')$v[$k]=empty($item)?'未配置':'已配置';
    else $v[$k]=$clean($item,(string)$k);
   }return $v;
  };
  $data=$clean($data);$text=json_encode($data,JSON_UNESCAPED_UNICODE);
  // Provider errors must not echo a newly submitted credential either.
  $secrets=$known;foreach($submitted as $k=>$v)if(is_string($v)&&strlen($v)>3&&preg_match('/key|secret|password|token|headers|url/i',$k))$secrets[]=$v;
  foreach($secrets as $secret)$text=str_replace([substr(json_encode($secret,JSON_UNESCAPED_UNICODE),1,-1),rawurlencode($secret)],['[已隐藏]','[已隐藏]'],$text);
  return $text;
 });
}
function wallos_credential_probe($db,$uid,$name,$value,$transport=null) {
 if($name==='ai') {
  $q=$db->prepare('SELECT enabled,type,url FROM ai_settings WHERE user_id=:id');$q->bindValue(':id',$uid,SQLITE3_INTEGER);$ai=$q->execute()->fetchArray(SQLITE3_ASSOC);
  if(!$ai || !$ai['enabled'])return ['success'=>false,'message'=>'AI 已关闭，未执行任何模型请求。'];
 }
 if($value==='')return ['success'=>false,'message'=>'未配置，请先保存密钥。'];
 if(strlen($value)>16384 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))return ['success'=>false,'message'=>'配置格式无效。'];
 $url=null;$headers=[];
 if($name==='exchange4')$url='https://v6.exchangerate-api.com/v6/'.rawurlencode($value).'/latest/USD';
 if($name==='exchange0')$url='https://data.fixer.io/api/latest?access_key='.rawurlencode($value);
 if($name==='exchange1'){$url='https://api.apilayer.com/fixer/latest?base=EUR&symbols=USD';$headers[]='apikey: '.$value;}
 if($name==='serpapi')$url='https://serpapi.com/account?api_key='.rawurlencode($value);
 if($name==='ai') {
  $urls=['chatgpt'=>'https://api.openai.com/v1/models','openrouter'=>'https://openrouter.ai/api/v1/models','gemini'=>'https://generativelanguage.googleapis.com/v1beta/models?key='.rawurlencode($value)];
  $url=$urls[$ai['type']]??null;$headers[]='Authorization: Bearer '.$value;
 }
 if($url) {
  if($transport)[$code,$reply]=$transport($url,$headers);
  else {$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false]);$reply=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$reply=json_decode($reply?:'',true);}
  $ok=$code===200 && is_array($reply) && !isset($reply['error']) && ($reply['result']??'success')!=='error' && ($reply['success']??true)!==false;
  return ['success'=>$ok,'verified'=>$ok,'message'=>$ok?'服务端验证通过。':'验证失败，请检查密钥、权限或网络；原配置未改动。'];
 }
 if(in_array($name,['email','smtp'],true)) {
  require_once __DIR__.'/ssrf_helper.php';require_once __DIR__.'/../libs/PHPMailer/PHPMailer.php';require_once __DIR__.'/../libs/PHPMailer/SMTP.php';require_once __DIR__.'/../libs/PHPMailer/Exception.php';
  $table=$name==='smtp'?'admin':'email_notifications';$q=$db->prepare('SELECT * FROM '.$table.($name==='smtp'?' LIMIT 1':' WHERE user_id=:id'));if($name==='email')$q->bindValue(':id',$uid,SQLITE3_INTEGER);$r=$q->execute()->fetchArray(SQLITE3_ASSOC);
  if(!$r || !validate_smtp_host($r['smtp_address'],(int)$r['smtp_port'],$db))return ['success'=>false,'message'=>'SMTP 主机配置无效。'];
  try{$m=new PHPMailer\PHPMailer\PHPMailer(true);$m->isSMTP();$m->Host=$r['smtp_address'];$m->Port=(int)$r['smtp_port'];$m->SMTPSecure=$r['encryption']==='none'?'':$r['encryption'];$m->SMTPAuth=true;$m->Username=$r['smtp_username'];$m->Password=$value;$m->Timeout=12;$ok=$m->smtpConnect();$m->smtpClose();return ['success'=>(bool)$ok,'verified'=>(bool)$ok,'message'=>$ok?'SMTP 认证通过，未发送邮件。':'SMTP 认证失败，未发送邮件。'];}catch(Throwable $e){return ['success'=>false,'message'=>'SMTP 认证失败，未发送邮件，原配置未改动。'];}
 }
 return ['success'=>true,'verified'=>false,'message'=>'配置格式检查通过。该集成无独立的无副作用权限测试；未发送通知或执行付费调用。'];
}
