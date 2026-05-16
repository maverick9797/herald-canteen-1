<?php
// chef_kot_print.php — Server-side printable KOT for chef
// Access: chef only, via ?kot_id=N
require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';

require_role('chef');

$kot_id = filter_var($_GET['kot_id'] ?? 0, FILTER_VALIDATE_INT);
if (!$kot_id || $kot_id <= 0) {
    http_response_code(400);
    die('Invalid KOT ID.');
}

// Fetch KOT (both active AND archived so it remains downloadable after Ready)
$stmt = $conn->prepare("
    SELECT
        k.kot_id,
        k.order_id,
        k.kot_status,
        k.delivery_mode,
        k.special_notes,
        k.created_at      AS kot_created_at,
        o.status          AS order_status,
        o.total_amount,
        o.payment_method,
        o.created_at      AS order_created_at,
        o.delivery_location_name,
        o.delivery_block_name,
        u.full_name       AS customer_name
    FROM kitchen_order_tickets k
    JOIN orders o ON o.order_id = k.order_id
    JOIN users  u ON u.user_id  = o.user_id
    WHERE k.kot_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $kot_id);
$stmt->execute();
$kot = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$kot) {
    http_response_code(404);
    die('KOT not found.');
}

// Fetch order items
$stmt = $conn->prepare("
    SELECT mi.name AS item_name, oi.quantity, oi.price
    FROM order_items oi
    JOIN menu_items mi ON mi.item_id = oi.item_id
    WHERE oi.order_id = ?
    ORDER BY oi.order_item_id
");
$stmt->bind_param('i', $kot['order_id']);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Helpers
function delivery_label(string $mode): string {
    return match($mode) {
        'delivery' => 'Delivery 🚚',
        'takeaway' => 'Takeaway 🥡',
        default    => ucfirst($mode),
    };
}

function status_label(string $s): string {
    return match($s) {
        'pending'          => 'Pending',
        'preparing'        => 'Preparing',
        'ready'            => 'Ready',
        'out_for_delivery' => 'Out for Delivery',
        'delivered'        => 'Delivered',
        'cancelled'        => 'Cancelled',
        default            => ucfirst(str_replace('_', ' ', $s)),
    };
}

$order_num = str_pad((int)$kot['order_id'], 4, '0', STR_PAD_LEFT);
$printed   = date('d M Y, g:i A');
$ordered   = date('d M Y, g:i A', strtotime($kot['order_created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KOT #<?= (int)$kot['kot_id'] ?> — Herald Canteen</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background: #fff;
            color: #111;
            max-width: 480px;
            margin: 30px auto;
            padding: 24px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #4db848;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .header h1 { font-size: 20px; color: #4db848; margin-bottom: 2px; }
        .header .sub { font-size: 12px; color: #666; }
        .kot-num {
            font-size: 32px;
            font-weight: 800;
            color: #111;
            letter-spacing: -1px;
            margin: 6px 0 4px;
        }
        .badge {
            display: inline-block;
            background: #4db848;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
            margin-bottom: 20px;
        }
        .info-cell .lbl {
            font-size: 10px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 2px;
        }
        .info-cell .val { font-size: 13px; font-weight: 600; color: #111; }

        .remark-box {
            background: #f5f5f5;
            border-left: 3px solid #4db848;
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #333;
        }
        .remark-box .remark-lbl {
            font-size: 10px;
            color: #4db848;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .5px;
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 13px;
        }
        thead th {
            background: #f5f5f5;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .3px;
            color: #555;
            border-bottom: 2px solid #ddd;
        }
        tbody td {
            padding: 9px 10px;
            border-bottom: 1px solid #eee;
            color: #222;
        }
        tfoot td {
            padding: 10px 10px;
            font-weight: 700;
            font-size: 15px;
            color: #4db848;
            border-top: 2px solid #4db848;
            background: #f9fff9;
        }

        .footer {
            text-align: center;
            font-size: 11px;
            color: #aaa;
            border-top: 1px solid #eee;
            padding-top: 14px;
            margin-top: 4px;
        }

        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        .no-print a, .no-print button {
            display: inline-block;
            margin: 4px;
            padding: 9px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }
        .btn-print { background: #4db848; color: #fff; }
        .btn-back  { background: #f0f0f0; color: #333; }

        @page { size: A4; margin: 15mm; }
        @media print {
            .no-print { display: none !important; }
            body { margin: 0; max-width: 100%; padding: 0; }
            /* Prevent extra blank page */
            html, body { height: auto !important; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a href="chef-control.php#kot-section" class="btn-back">← Back to Chef Panel</a>
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
</div>

<div class="header">
    <h1>🍽️ Herald Canteen</h1>
    <div class="sub">Herald College Kathmandu — Kitchen Order Ticket</div>
    <div class="kot-num">KOT #<?= (int)$kot['kot_id'] ?></div>
    <span class="badge">Order #<?= $order_num ?> &nbsp;•&nbsp; <?= htmlspecialchars(status_label($kot['order_status']), ENT_QUOTES, 'UTF-8') ?></span>
</div>

<div class="info-grid">
    <div class="info-cell">
        <div class="lbl">Customer</div>
        <div class="val"><?= htmlspecialchars($kot['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="info-cell">
        <div class="lbl">Delivery Mode</div>
        <div class="val"><?= htmlspecialchars(delivery_label($kot['delivery_mode']), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php if ($kot['delivery_mode'] === 'delivery'): ?>
    <div class="info-cell" style="grid-column:1/-1;">
        <div class="lbl">📍 Delivery Location</div>
        <div class="val">
            <?php if (!empty($kot['delivery_location_name'])): ?>
                <?= htmlspecialchars($kot['delivery_location_name'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($kot['delivery_block_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <span style="color:#999;font-style:italic;">Not specified</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="info-cell">
        <div class="lbl">Order Time</div>
        <div class="val"><?= htmlspecialchars($ordered, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="info-cell">
        <div class="lbl">Printed At</div>
        <div class="val"><?= htmlspecialchars($printed, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="info-cell">
        <div class="lbl">Payment</div>
        <div class="val"><?= strtoupper(htmlspecialchars($kot['payment_method'], ENT_QUOTES, 'UTF-8')) ?></div>
    </div>
    <div class="info-cell">
        <div class="lbl">KOT Status</div>
        <div class="val"><?= htmlspecialchars(ucfirst($kot['kot_status']), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>

<?php if (!empty($kot['special_notes'])): ?>
<div class="remark-box">
    <div class="remark-lbl">📝 Customer Remark</div>
    <?= htmlspecialchars($kot['special_notes'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th>Item</th>
            <th style="text-align:center;">Qty</th>
            <th style="text-align:right;">Unit Price</th>
            <th style="text-align:right;">Amount</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="text-align:center;"><?= (int)$item['quantity'] ?></td>
            <td style="text-align:right;">Rs. <?= number_format($item['price'], 2) ?></td>
            <td style="text-align:right;">Rs. <?= number_format($item['price'] * $item['quantity'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="3">Total Amount</td>
            <td style="text-align:right;">Rs. <?= number_format($kot['total_amount'], 2) ?></td>
        </tr>
    </tfoot>
</table>

<div class="footer">
    This KOT is for kitchen use only. Herald Canteen System.<br>
    For queries, contact the canteen counter.
</div>

</body>
</html>
