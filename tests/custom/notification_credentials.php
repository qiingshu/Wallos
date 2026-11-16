<?php
require __DIR__.'/../../includes/instance_config.php';
$db=new SQLite3(':memory:');$db->enableExceptions(true);$userId=123;
$db->exec("CREATE TABLE telegram_notifications(user_id INTEGER,bot_token TEXT,enabled INTEGER);CREATE TABLE pushover_notifications(user_id INTEGER,token TEXT,enabled INTEGER);CREATE TABLE ntfy_notifications(user_id INTEGER,headers TEXT,enabled INTEGER);INSERT INTO telegram_notifications VALUES(123,'old',1);INSERT INTO pushover_notifications VALUES(123,'old',1);INSERT INTO ntfy_notifications VALUES(123,'old',1)");
foreach(['telegram','pushover','ntfy'] as $name)wallos_credential_put($db,$name,$userId,'',true);
if(wallos_telegram_bot_token($db,'')!==''||wallos_pushover_token($db,'')!==''||wallos_ntfy_settings($db,'','')['headers']!=='')throw new RuntimeException('deleted credential falls back');
foreach(['telegram'=>'bot_token','pushover'=>'token','ntfy'=>'headers'] as $name=>$col)$db->exec('UPDATE '.$name.'_notifications SET '.$col.'="explicit-new-value",enabled=1');
if(wallos_telegram_bot_token($db,'explicit-new-value')!=='explicit-new-value'||wallos_pushover_token($db,'explicit-new-value')!=='explicit-new-value'||wallos_ntfy_settings($db,'https://example.invalid','explicit-new-value')['headers']!=='explicit-new-value')throw new RuntimeException('new credential saved by legacy notification form is ignored after delete');
echo "PASS deleted personal credentials never fall back; explicitly saved replacement works through legacy notification paths\n";
