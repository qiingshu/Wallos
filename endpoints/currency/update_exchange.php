<?php
require_once '../../includes/connect_endpoint.php';require_once '../../includes/validate_endpoint.php';require_once '../../includes/exchangerate_api.php';
header('Content-Type: application/json; charset=utf-8');$r=wallos_exchangerate_refresh($db,$userId,($_POST['force']??'')==='true');if(!empty($r['busy']))http_response_code(409);echo json_encode($r);
