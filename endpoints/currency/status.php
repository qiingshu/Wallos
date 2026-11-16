<?php
require_once '../../includes/connect_endpoint.php';require_once '../../includes/validate_endpoint.php';require_once '../../includes/exchangerate_api.php';
$q=$db->prepare('SELECT provider FROM fixer WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$provider=(int)($q->execute()->fetchArray(SQLITE3_NUM)[0]??4);$status=wallos_exchangerate_status($userId,$db);
$q=$db->prepare('SELECT code,rate FROM currencies WHERE user_id=:id ORDER BY code');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$r=$q->execute();$rates=[];while($v=$r->fetchArray(SQLITE3_ASSOC))$rates[]=$v;
$cacheValid=($status['signature']??'')===wallos_exchange_current_signature($db,$userId);
$noKey=in_array($provider,[2,3],true);$configured=$noKey||wallos_credential_get($db,'exchange'.$provider,$userId)!=='';
echo json_encode(['success'=>true,'provider'=>$provider,'requires_key'=>!$noKey,'configured'=>$configured,'last_success'=>$status['last_success']??null,'message'=>$configured?(!$cacheValid&&!empty($status['last_success'])?'配置或币种已变更，待更新；当前显示上次成功缓存。':($status['message']??'尚未获取汇率')):'未配置密钥，已停止请求；上次成功汇率保留。','cache_provider'=>$status['provider']??null,'cache_valid'=>$cacheValid,'rates'=>array_values(array_filter($rates,fn($r)=>in_array($r['code'],$status['codes']??[],true)))]);
