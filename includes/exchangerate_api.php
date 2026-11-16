<?php
require_once __DIR__.'/credentials.php';
require_once __DIR__.'/frankfurter.php';
require_once __DIR__.'/allratestoday.php';
function wallos_exchange_init($db) {$db->exec('CREATE TABLE IF NOT EXISTS exchange_refresh_status(user_id INTEGER PRIMARY KEY,payload TEXT NOT NULL)');}
function wallos_exchangerate_key($db=null,$uid=1) {if($db===null)$db=$GLOBALS['db']??null;return $db?wallos_credential_get($db,'exchange4',$uid):'';}
function wallos_exchangerate_status($userId,$db=null) {
 if($db===null)$db=$GLOBALS['db']??null;
 if($db && $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='exchange_refresh_status'")){
 $q=$db->prepare('SELECT payload FROM exchange_refresh_status WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$r=$q->execute()->fetchArray(SQLITE3_NUM);if($r)return json_decode($r[0],true)?:[];}
 return [];
}
function wallos_exchange_store_status($db,$uid,$status) {
 $q=$db->prepare('INSERT INTO exchange_refresh_status(user_id,payload) VALUES(:id,:payload) ON CONFLICT(user_id) DO UPDATE SET payload=excluded.payload');$q->bindValue(':id',$uid,SQLITE3_INTEGER);$q->bindValue(':payload',json_encode($status,JSON_UNESCAPED_UNICODE),SQLITE3_TEXT);if(!$q->execute())throw new RuntimeException('状态保存失败');
}
function wallos_exchange_lock($uid) {
 $path=__DIR__.'/../db/exchange-'.(int)$uid.'.lock';$f=fopen($path,'c');if($f){chmod($path,0660);if(function_exists('posix_geteuid')&&posix_geteuid()===0){chown($path,82);chgrp($path,82);}}if(!$f || !flock($f,LOCK_EX|LOCK_NB)){if($f)fclose($f);return null;}return $f;
}
function wallos_exchangerate_normalize($data, $mainCode, $codes) {
    if (!is_array($data) || ($data['result'] ?? '') !== 'success' || ($data['base_code'] ?? '') !== 'USD') {
        throw new RuntimeException('汇率接口返回异常，已保留上次成功汇率。');
    }
    $rates = $data['conversion_rates'] ?? [];
    $base = $rates[$mainCode] ?? null;
    if (!is_numeric($base) || !is_finite((float)$base) || $base <= 0) throw new RuntimeException('主币种汇率无效，已保留上次成功汇率。');
    $out = [];
    foreach ($codes as $code) {
        $rate = $rates[$code] ?? null;
        if (!is_numeric($rate) || !is_finite((float)$rate) || $rate <= 0) throw new RuntimeException('部分币种缺少有效汇率，已保留上次成功汇率。');
        $out[$code] = $code === $mainCode ? 1.0 : (float)$rate / (float)$base;
    }
    return $out;
}
function wallos_exchange_current_signature($db,$uid) {
 $q=$db->prepare('SELECT provider FROM fixer WHERE user_id=:id');$q->bindValue(':id',$uid,SQLITE3_INTEGER);$p=(int)($q->execute()->fetchArray(SQLITE3_NUM)[0]??4);
 $key=in_array($p,[2,3],true)?'':wallos_credential_get($db,'exchange'.$p,$uid);
 $q=$db->prepare('SELECT c.code FROM user u JOIN currencies c ON c.id=u.main_currency AND c.user_id=u.id WHERE u.id=:id');$q->bindValue(':id',$uid,SQLITE3_INTEGER);$m=$q->execute()->fetchArray(SQLITE3_NUM)[0]??'';
 $q=$db->prepare('SELECT code FROM currencies WHERE user_id=:id ORDER BY code');$q->bindValue(':id',$uid,SQLITE3_INTEGER);$r=$q->execute();$codes=[];while($v=$r->fetchArray(SQLITE3_NUM))$codes[]=$v[0];
 return hash('sha256',json_encode([$p,hash('sha256',$key),wallos_credential_generation($db,'exchange'.$p,$uid),$m,$codes]));
}
function wallos_exchangerate_refresh($db,$userId,$force=false,$fetch=null) {
 wallos_exchange_init($db);$lock=wallos_exchange_lock($userId);
 if(!$lock)return ['success'=>false,'busy'=>true,'message'=>'汇率正在更新，请稍候。'];
 try {
  $q=$db->prepare('SELECT provider FROM fixer WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$provider=(int)($q->execute()->fetchArray(SQLITE3_NUM)[0]??4);
  $key=in_array($provider,[2,3],true)?'':wallos_credential_get($db,'exchange'.$provider,$userId);
  $status=wallos_exchangerate_status($userId,$db);

  $q=$db->prepare('SELECT c.code FROM user u JOIN currencies c ON c.id=u.main_currency AND c.user_id=u.id WHERE u.id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$main=$q->execute()->fetchArray(SQLITE3_NUM)[0]??'';
  $q=$db->prepare('SELECT code FROM currencies WHERE user_id=:id ORDER BY code');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$r=$q->execute();$codes=[];while($v=$r->fetchArray(SQLITE3_NUM))$codes[]=$v[0];
  // Rebase valid cached rates atomically even when the provider is unavailable.
  if (!empty($status['last_success']) && !empty($status['main_currency']) && $status['main_currency']!==$main && in_array($main,$status['codes']??[],true)) {
   $q=$db->prepare('SELECT rate FROM currencies WHERE user_id=:id AND code=:code');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$q->bindValue(':code',$main,SQLITE3_TEXT);$base=$q->execute()->fetchArray(SQLITE3_NUM)[0]??0;
   if(is_numeric($base)&&is_finite((float)$base)&&$base>0){
    $db->exec('BEGIN IMMEDIATE');
    try{$q=$db->prepare('UPDATE currencies SET rate=rate/:base WHERE user_id=:id');$q->bindValue(':base',(float)$base,SQLITE3_FLOAT);$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException();$status['main_currency']=$main;wallos_exchange_store_status($db,$userId,$status);if(!$db->exec('COMMIT'))throw new RuntimeException();}catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
   }
  }
  if(!in_array($provider,[2,3],true) && $key==='')return ['success'=>false,'message'=>'未配置密钥，已停止服务请求；上次成功汇率仍保留。'];
  $signature=hash('sha256',json_encode([$provider,hash('sha256',$key),wallos_credential_generation($db,'exchange'.$provider,$userId),$main,$codes]));
  $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai'));$today=$now->format('Y-m-d');
  if(!$force && ($status['signature']??'')===$signature && substr($status['last_success']??'',0,10)===$today)return ['success'=>true,'cached'=>true,'message'=>'今日汇率已更新，正在使用缓存。'];
  if(!$force && ($status['attempt_signature']??'')===$signature && substr($status['last_attempt']??'',0,10)===$today)return ['success'=>false,'cached'=>true,'message'=>$status['message']??'今日更新失败，已保留缓存。'];
  try {
   if($fetch)$data=$fetch();
   elseif($provider===2)$data=frankfurter_latest_rates($main,implode(',',$codes));
   elseif($provider===3)$data=allratestoday_latest_rates($main,implode(',',$codes));
   else {
    $headers=[];$url='';
    if($provider===4)$url='https://v6.exchangerate-api.com/v6/'.rawurlencode($key).'/latest/USD';
    elseif($provider===1){$url='https://api.apilayer.com/fixer/latest?base=EUR&symbols='.implode(',',$codes);$headers[]='apikey: '.$key;}
    else $url='https://data.fixer.io/api/latest?access_key='.rawurlencode($key).'&base=EUR&symbols='.implode(',',$codes);
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false]);$raw=curl_exec($ch);$http=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($http!==200)throw new RuntimeException();$data=json_decode($raw?:'',true);
   }
   if($provider===4)$rates=wallos_exchangerate_normalize($data,$main,$codes);
   else {
    if(!is_array($data) || isset($data['error']) || ($data['success']??true)===false || empty($data['rates']))throw new RuntimeException();
    $all=$data['rates'];if(in_array($provider,[2,3],true))$all[$main]=1;
    $base=$all[$main]??0;if(!is_numeric($base)||$base<=0)throw new RuntimeException();$rates=[];
    foreach($codes as $code){$v=$all[$code]??null;if(!is_numeric($v)||!is_finite((float)$v)||$v<=0)throw new RuntimeException();$rates[$code]=$code===$main?1:(float)$v/(float)$base;}
   }
   if(!$db->exec('BEGIN IMMEDIATE'))throw new RuntimeException();
   try {
    if(wallos_exchange_current_signature($db,$userId)!==$signature)throw new RuntimeException('配置已变更，旧结果未写入');
    $q=$db->prepare('UPDATE currencies SET rate=:rate WHERE code=:code AND user_id=:id');foreach($rates as $code=>$v){$q->bindValue(':id',$userId,SQLITE3_INTEGER);$q->bindValue(':code',$code,SQLITE3_TEXT);$q->bindValue(':rate',$v,SQLITE3_FLOAT);if(!$q->execute())throw new RuntimeException();$q->reset();}
    $q=$db->prepare('DELETE FROM last_exchange_update WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException();
    $q=$db->prepare('INSERT INTO last_exchange_update(date,user_id) VALUES(:date,:id)');$q->bindValue(':date',$today,SQLITE3_TEXT);$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute())throw new RuntimeException();
    $status=['signature'=>$signature,'attempt_signature'=>$signature,'provider'=>$provider,'main_currency'=>$main,'codes'=>$codes,'state'=>'success','message'=>'汇率已更新。','last_success'=>$now->format(DATE_ATOM),'last_attempt'=>$now->format(DATE_ATOM)];wallos_exchange_store_status($db,$userId,$status);
    if(!$db->exec('COMMIT'))throw new RuntimeException();
   }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
   return ['success'=>true,'message'=>'汇率已更新。'];
  }catch(Throwable $e){$status['state']='error';$status['message']='更新失败，已保留最后成功汇率；可手动重试。';$status['last_attempt']=$now->format(DATE_ATOM);$status['attempt_signature']=$signature;wallos_exchange_store_status($db,$userId,$status);return ['success'=>false,'message'=>$status['message']];}
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
