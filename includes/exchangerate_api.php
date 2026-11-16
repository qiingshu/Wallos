<?php
// Provider 4. Credentials are read only on the server from a mounted file.
function wallos_exchangerate_key() {
    $file = getenv('EXCHANGERATE_API_KEY_FILE') ?: '/run/secrets/wallos-exchange';
    return is_readable($file) ? trim(file_get_contents($file)) : '';
}
function wallos_exchangerate_status($userId) {
    $path = __DIR__ . '/../db/exchangerate-status-' . (int)$userId . '.json';
    $data = is_readable($path) ? json_decode(file_get_contents($path), true) : [];
    return is_array($data) ? $data : [];
}
function wallos_exchangerate_save_status($userId, $data) {
    $path = __DIR__ . '/../db/exchangerate-status-' . (int)$userId . '.json';
    $temp = tempnam(dirname($path), '.exchange-');
    file_put_contents($temp, json_encode($data, JSON_UNESCAPED_UNICODE));
    chmod($temp, 0660);
    // CLI cron and PHP-FPM share this per-account status file.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) { chown($temp, 82); chgrp($temp, 82); }
    rename($temp, $path);
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
function wallos_exchangerate_refresh($db, $userId, $force = false, $fetch = null) {
    $zone = new DateTimeZone('Asia/Shanghai');
    $now = new DateTimeImmutable('now', $zone); $today = $now->format('Y-m-d');
    $status = wallos_exchangerate_status($userId);
    $q = $db->prepare('SELECT c.code FROM user u JOIN currencies c ON c.id=u.main_currency AND c.user_id=u.id WHERE u.id=:id');
    $q->bindValue(':id', $userId, SQLITE3_INTEGER); $main = $q->execute()->fetchArray(SQLITE3_ASSOC)['code'] ?? '';
    if (($status['main_currency'] ?? '') === $main && substr($status['last_success'] ?? '', 0, 10) === $today) {
        return ['success'=>true, 'message'=>'今日汇率已更新，正在使用缓存。', 'cached'=>true];
    }
    if (!$force && substr($status['last_attempt'] ?? '', 0, 10) === $today) return ['success'=>false, 'message'=>$status['message'] ?? '今日已尝试更新，请稍后手动重试。'];
    $status['last_attempt'] = $now->format(DATE_ATOM);
    try {
        $key = wallos_exchangerate_key();
        if ($key === '') throw new RuntimeException('尚未配置 ExchangeRate-API 服务端密钥。');
        if ($fetch === null) {
            $context = stream_context_create(['http'=>['timeout'=>15, 'ignore_errors'=>true], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
            $body = @file_get_contents('https://v6.exchangerate-api.com/v6/' . rawurlencode($key) . '/latest/USD', false, $context);
            $data = $body === false ? null : json_decode($body, true);
        } else { $data = $fetch(); }
        $q=$db->prepare('SELECT code FROM currencies WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);$rows=$q->execute();$codes=[];
        while($r=$rows->fetchArray(SQLITE3_ASSOC)) $codes[]=$r['code'];
        $rates=wallos_exchangerate_normalize($data,$main,$codes);
        if (!$db->exec('BEGIN IMMEDIATE')) throw new RuntimeException('汇率保存失败，已保留上次成功汇率。');
        try {
            $q=$db->prepare('UPDATE currencies SET rate=:rate WHERE code=:code AND user_id=:id');
            foreach($rates as $code=>$rate) {
                $q->bindValue(':rate',$rate,SQLITE3_FLOAT);$q->bindValue(':code',$code,SQLITE3_TEXT);$q->bindValue(':id',$userId,SQLITE3_INTEGER);
                if(!$q->execute()) throw new RuntimeException('汇率保存失败，已保留上次成功汇率。');$q->reset();
            }
            $q=$db->prepare('DELETE FROM last_exchange_update WHERE user_id=:id');$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute()) throw new RuntimeException('汇率日期保存失败。');
            $q=$db->prepare('INSERT INTO last_exchange_update(date,user_id) VALUES(:date,:id)');$q->bindValue(':date',$today,SQLITE3_TEXT);$q->bindValue(':id',$userId,SQLITE3_INTEGER);if(!$q->execute()) throw new RuntimeException('汇率日期保存失败。');
            if(!$db->exec('COMMIT')) throw new RuntimeException('汇率保存失败。');
        } catch(Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        $status['last_success']=$now->format(DATE_ATOM);$status['main_currency']=$main;$status['state']='success';$status['message']='汇率已更新。';
        wallos_exchangerate_save_status($userId,$status);
        return ['success'=>true,'message'=>'汇率已更新。'];
    } catch(Throwable $e) {
        $status['state']='error';$status['message']=$e->getMessage();wallos_exchangerate_save_status($userId,$status);
        return ['success'=>false,'message'=>$status['message']];
    }
}
