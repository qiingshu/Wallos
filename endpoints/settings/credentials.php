<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/credentials.php';
header('Content-Type: application/json; charset=utf-8');
$data=json_decode(file_get_contents('php://input'),true)?:$_POST;
$action=$data['action']??'status';$name=$data['name']??'';
if($action==='status'){echo json_encode(['success'=>true,'credentials'=>wallos_credential_statuses($db,$userId)]);exit;}
$entry=wallos_credential_catalog()[$name]??null;
if(!$entry || (!$entry['personal'] && $userId!==1)){http_response_code(403);echo json_encode(['success'=>false,'message'=>'无权修改此配置。']);exit;}
$lock=null;if(preg_match('/^exchange[014]$/',$name)){require_once '../../includes/exchangerate_api.php';$lock=wallos_exchange_lock($userId);if(!$lock){http_response_code(409);echo json_encode(['success'=>false,'message'=>'汇率正在更新，请稍候。']);exit;}}
try {
 $value=trim((string)($data['value']??''));
 if($action==='test'){$result=wallos_credential_probe($db,$userId,$name,$value!==''?$value:wallos_credential_get($db,$name,$userId));echo json_encode($result);exit;}
 if(!in_array($action,['save','delete'],true))throw new RuntimeException('操作无效');
 if($action==='delete' && ($data['confirm']??false)!==true)throw new RuntimeException('请确认删除密钥');
 if(strlen($value)>16384 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))throw new RuntimeException('配置格式无效');
 wallos_credential_put($db,$name,$userId,$value,$action==='delete');
 echo json_encode(['success'=>true,'configured'=>wallos_credential_get($db,$name,$userId)!=='','message'=>$action==='delete'?'密钥已删除；不会回退到旧挂载配置。':($value===''?'留空保存，原配置已保留。':'配置已保存。')]);
}catch(Throwable $e){http_response_code(400);echo json_encode(['success'=>false,'message'=>'操作失败，原配置未改动。']);}finally {if($lock){flock($lock,LOCK_UN);fclose($lock);}}
