<?php
require_once '../../includes/connect_endpoint.php';require_once '../../includes/validate_endpoint.php';
try{wallos_credential_put($db,'serpapi',$userId,trim($_POST['api_key']??''));echo json_encode(['success'=>true,'message'=>'配置已保存；留空时保留原密钥。']);}catch(Throwable $e){echo json_encode(['success'=>false,'message'=>'保存失败，原密钥已保留。']);}
