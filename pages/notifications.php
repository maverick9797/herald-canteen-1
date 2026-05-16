<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";

require_login();
// RBAC: Block chef and staff from customer notifications
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    if ($_SESSION['role'] === 'chef') {
        header('Location: chef-control.php');
        exit;
    }
    header('Location: staff-control.php');
    exit;
}

// FIX: Cast to int to prevent any type confusion, then use a prepared
// statement to mark-as-read — never interpolate session values into SQL.
$user_id = (int) $_SESSION['user_id'];

// FIX: Was $conn->query("... WHERE user_id = $user_id ...") — raw interpolation.
// Even though $user_id comes from the session (not user input), best practice
// is always a prepared statement; it also prevents issues if session data is
// ever tampered with via session-file manipulation on shared hosts.
$stmt_read = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt_read->bind_param("i", $user_id);
$stmt_read->execute();
$stmt_read->close();

// Handle delete single notification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notif_id'])) {
    $notif_id = filter_var($_POST['delete_notif_id'], FILTER_VALIDATE_INT);
    if ($notif_id > 0) {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notif_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    session_write_close(); // FIX: flush before redirect
    header("Location: notifications.php");
    exit;
}

// Handle clear all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all'])) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    session_write_close(); // FIX: flush before redirect
    header("Location: notifications.php");
    exit;
}

// Fetch all notifications for user
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$name = htmlspecialchars($_SESSION['full_name'] ?? 'User', ENT_QUOTES, 'UTF-8');

// Notification icon map
function notif_icon(string $type): string {
    return match($type) {
        'order'    => '🛒',
        'payment'  => '💳',
        'ready'    => '✅',
        'cancel'   => '❌',
        'promo'    => '🎁',
        default    => '🔔',
    };
}

// Relative time helper
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    return date('M j, Y', strtotime($datetime));
}

// Strip DB identifiers from notification title/message at render time
function clean_notif_title(string $title): string {
    // "Order #0075 Cancelled" → "Order Cancelled"
    // "Order #75 Cancelled"   → "Order Cancelled"
    return preg_replace('/#\d+\s*/u', '', $title);
}
function clean_notif_message(string $msg): string {
    // Remove "of Rs. 250.00" or "of Rs 250" style amounts
    $msg = preg_replace('/\bof Rs\.?\s*[\d,]+(\.\d+)?\b/u', '', $msg);
    // Remove "for Order #0075" or "for Order #75"
    $msg = preg_replace('/\bfor Order\s+#?\d+\b/u', '', $msg);
    // Clean up any double spaces left behind
    return preg_replace('/\s{2,}/', ' ', trim($msg));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications – Herald Canteen</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .notif-page-wrap {
            max-width: 700px;
            margin: 40px auto;
            padding: 0 16px 60px;
        }
        .notif-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .notif-page-header h1 {
            font-size: 26px;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-clear-all {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
            color: rgba(255,255,255,0.6);
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-clear-all:hover {
            background: rgba(229,57,53,0.15);
            border-color: #e53935;
            color: #e53935;
        }
        .notif-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .notif-card {
            background: #1e1e1e;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: border-color 0.2s;
        }
        .notif-card:hover { border-color: rgba(77,184,72,0.3); }
        .notif-card-icon {
            font-size: 26px;
            line-height: 1;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .notif-card-body { flex: 1; min-width: 0; }
        .notif-card-title {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 4px;
        }
        .notif-card-msg {
            font-size: 13px;
            color: rgba(255,255,255,0.55);
            line-height: 1.5;
        }
        .notif-card-time {
            font-size: 11px;
            color: rgba(255,255,255,0.3);
            margin-top: 8px;
        }
        .notif-card-del {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.25);
            font-size: 18px;
            cursor: pointer;
            line-height: 1;
            padding: 4px 6px;
            border-radius: 50%;
            transition: all 0.15s;
            flex-shrink: 0;
        }
        .notif-card-del:hover { background: rgba(229,57,53,0.15); color: #e53935; }
        .notif-empty {
            text-align: center;
            padding: 60px 0;
            color: rgba(255,255,255,0.3);
        }
        .notif-empty .empty-icon { font-size: 48px; margin-bottom: 16px; }
        .notif-empty p { font-size: 15px; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,0.5);
            text-decoration: none;
            font-size: 14px;
            margin-bottom: 20px;
            transition: color 0.2s;
        }
        .back-link:hover { color: #4db848; }
    </style>
</head>
<body class="notifications-page">

    <div class="notif-page-wrap">
        <a href="dashboard.php" class="back-link">← Back to Home</a>

        <div class="notif-page-header">
            <h1>🔔 Notifications</h1>
            <label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label>
            <?php if (count($notifications) > 0): ?>
            <form method="POST" onsubmit="return confirm('Clear all notifications?')">
                <input type="hidden" name="clear_all" value="1">
                <button type="submit" class="btn-clear-all">Clear all</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (count($notifications) === 0): ?>
        <div class="notif-empty">
            <div class="empty-icon">🔕</div>
            <p>You're all caught up! No notifications yet.</p>
        </div>
        <?php else: ?>
        <div class="notif-list">
            <?php foreach ($notifications as $n): ?>
            <div class="notif-card">
                <div class="notif-card-icon"><?php echo notif_icon($n['type']); ?></div>
                <div class="notif-card-body">
                    <div class="notif-card-title"><?php echo htmlspecialchars(clean_notif_title($n['title']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="notif-card-msg"><?php echo htmlspecialchars(clean_notif_message($n['message']), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="notif-card-time"><?php echo time_ago($n['created_at']); ?></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="delete_notif_id" value="<?php echo $n['notification_id']; ?>">
                    <button type="submit" class="notif-card-del" title="Delete">✕</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</body>
</html>