<?php
// db.php — Herald Canteen shared database connection
// Uses mysqli to match the project's existing style.

$host    = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'herald_canteen';

// FIX: Enable strict mysqli error reporting so that failed prepare() or
// execute() calls throw exceptions instead of silently returning false.
// Without this, errors inside transactions are swallowed and the catch()
// block never fires — leaving partial data written to the database.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die('<pre style="color:red;padding:20px;font-size:14px;">
DB CONNECTION FAILED
--------------------
' . $conn->connect_error . '

Fix: Check db.php — make sure db_user, db_pass are correct
and the database "herald_canteen" exists in phpMyAdmin.
</pre>');
}

$conn->set_charset('utf8mb4');

// Sync PHP and MySQL to the same timezone so that PHP's date()/time()
// and MySQL's NOW() always agree. Without this, expires_at written by PHP
// can be offset from MySQL's clock, causing OTP rows to appear expired
// or missing immediately after insert.
date_default_timezone_set('Asia/Kathmandu');
$conn->query("SET time_zone = '+05:45'");