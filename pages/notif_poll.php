<?php
/**
 * notif_poll.php — Real-time notification polling endpoint
 *
 * Called every ~8 seconds by the JS poller in dashboard / my_orders.
 * Returns JSON: { count, new_notifs: [{title, message, type}] }
 *
 * "New" means: created after the timestamp the client last polled
 * (passed as ?since=<unix_timestamp>). Falls back to last 30 s.
 */
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode(['count' => 0, 'new_notifs' => []]);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// 'since' is a Unix timestamp from JS Date.now()/1000; default to 30 s ago
$since_raw = $_GET['since'] ?? 0;
$since_ts  = filter_var($since_raw, FILTER_VALIDATE_INT) ? (int)$since_raw : (time() - 30);
$since_sql = date('Y-m-d H:i:s', max($since_ts, time() - 300)); // cap at 5 min back

// Unread count
$cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$cnt_stmt->bind_param("i", $user_id);
$cnt_stmt->execute();
$unread_count = (int)$cnt_stmt->get_result()->fetch_row()[0];
$cnt_stmt->close();

// New notifications since last poll
$new_stmt = $conn->prepare(
    "SELECT title, message, type, created_at
     FROM notifications
     WHERE user_id = ? AND created_at > ?
     ORDER BY created_at DESC
     LIMIT 5"
);
$new_stmt->bind_param("is", $user_id, $since_sql);
$new_stmt->execute();
$new_notifs = $new_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$new_stmt->close();

echo json_encode([
    'count'      => $unread_count,
    'new_notifs' => $new_notifs,
    'server_ts'  => time(),
]);
