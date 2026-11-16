<?php
require_once '../../includes/connect_endpoint.php';require_once '../../includes/validate_endpoint.php';require_once '../../includes/exchangerate_api.php';
$provider=filter_var($_POST['provider']??null,FILTER_VALIDATE_INT);$key=trim($_POST['api_key']??'');
if(!in_array($provider,[0,1,2,3,4],true)){http_response_code(400);echo json_encode(['success'=>false,'message'=>'请选择有效的汇率提供商。']);exit;}
$lock=wallos_exchange_lock($userId);if(!$lock){http_response_code(409);echo json_encode(['success'=>false,'message'=>'汇率正在更新，请稍候。']);exit;}
try {
 wallos_credentials_init($db);if(!$db->exec('BEGIN IMMEDIATE'))throw new RuntimeException();
 if(!in_array($provider,[2,3],true) && $key!=='')wallos_credential_put($db,'exchange'.$provider,$userId,$key,false,false);
 $q=$db->prepare('UPDATE fixer SET provider=:provider,api_key=:key WHERE user_id=:id');$q->bindValue(':provider',$provider,SQLITE3_INTEGER);$q->bindValue(':key',in_array($provider,[2,3],true)?'':wallos_credential_get($db,'exchange'.$provider,$userId),SQLITE3_TEXT);$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException();
 if($db->querySingle('SELECT COUNT(*) FROM fixer WHERE user_id='.(int)$userId)===0){$q=$db->prepare('INSERT INTO fixer(provider,api_key,user_id) VALUES(:provider,:key,:id)');$q->bindValue(':provider',$provider,SQLITE3_INTEGER);$q->bindValue(':key',in_array($provider,[2,3],true)?'':wallos_credential_get($db,'exchange'.$provider,$userId),SQLITE3_TEXT);$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException();}
 if(!$db->exec('COMMIT'))throw new RuntimeException();echo json_encode(['success'=>true,'message'=>'汇率配置已保存；留空时保留该提供商的密钥。']);
}catch(Throwable $e){$db->exec('ROLLBACK');echo json_encode(['success'=>false,'message'=>'保存失败，原配置未改动。']);}finally{flock($lock,LOCK_UN);fclose($lock);}
