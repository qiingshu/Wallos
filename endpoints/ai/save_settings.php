<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';
$data=json_decode(file_get_contents('php://input'),true)?:[];
$q=$db->prepare('SELECT * FROM ai_settings WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$old=$q->execute()->fetchArray(SQLITE3_ASSOC)?:[];
$enabled=(bool)($data['ai_enabled']??false);
// Switching off changes only enabled. Provider, key, model and schedule survive.
if(!$enabled && $old){$q=$db->prepare('UPDATE ai_settings SET enabled=0 WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$ok=(bool)$q->execute();echo json_encode(['success'=>$ok,'enabled'=>false,'message'=>$ok?'AI 已关闭，配置已保留。':'保存失败。']);exit;}
$type=trim($data['ai_type']??($old['type']??'chatgpt'));
$key=trim($data['api_key']??'');if($key==='')$key=$old['api_key']??'';
$model=trim($data['model']??($old['model']??''));$url=trim($data['ollama_host']??($old['url']??''));$schedule=$data['ai_run_schedule']??($old['run_schedule']??'manual');
if(!in_array($type,['chatgpt','gemini','openrouter','ollama','openai-compatible'],true)||!in_array($schedule,['manual','weekly','monthly'],true)){http_response_code(400);echo json_encode(['success'=>false,'message'=>'配置无效，原配置未改动。']);exit;}
if($enabled && ($model==='' || ($type!=='ollama' && $key===''))){echo json_encode(['success'=>false,'message'=>'请补充模型和 API 密钥后再开启 AI。']);exit;}
if(in_array($type,['ollama','openai-compatible'],true))validate_webhook_url_for_ssrf($url,$db,$i18n,$userId);else $url='';
try{
 if(!$db->exec('BEGIN IMMEDIATE'))throw new RuntimeException();
 $q=$db->prepare('DELETE FROM ai_settings WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException();
 $q=$db->prepare('INSERT INTO ai_settings(user_id,type,enabled,api_key,model,url,run_schedule) VALUES(:id,:type,:enabled,:key,:model,:url,:schedule)');
 foreach(['id'=>$userId,'type'=>$type,'enabled'=>$enabled?1:0,'key'=>$key,'model'=>$model,'url'=>$url,'schedule'=>$schedule] as $n=>$v)$q->bindValue(':'.$n,$v,is_int($v)?SQLITE3_INTEGER:SQLITE3_TEXT);
 if(!$q->execute()||!$db->exec('COMMIT'))throw new RuntimeException();
 echo json_encode(['success'=>true,'enabled'=>$enabled,'message'=>$enabled?'AI 已开启。':'AI 已关闭。']);
}catch(Throwable $e){$db->exec('ROLLBACK');echo json_encode(['success'=>false,'message'=>'保存失败，原配置未改动。']);}
