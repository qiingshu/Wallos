<?php
require_once __DIR__ . "/zh_custom.php";

$databaseFile = 'db/wallos.db';

$db = new SQLite3($databaseFile);
$db->busyTimeout(5000);

if (!$db) {
    die('Connection to the database failed.');
}

?>