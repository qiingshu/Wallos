<?php
require_once 'validate.php';require_once __DIR__.'/../../includes/connect_endpoint_crontabs.php';require_once __DIR__.'/../../includes/exchangerate_api.php';require 'settimezone.php';
$rows=$db->query('SELECT id FROM user');while($r=$rows->fetchArray(SQLITE3_ASSOC)){$result=wallos_exchangerate_refresh($db,(int)$r['id']);echo htmlspecialchars($result['message'])."\n";}
