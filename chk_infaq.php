<?php
require_once 'config/config.php';
$r = $connect->query('SHOW COLUMNS FROM infaq');
while($row = $r->fetch_assoc()) {
    echo $row['Field'].':'.$row['Type']."\n";
}
$r->free();
