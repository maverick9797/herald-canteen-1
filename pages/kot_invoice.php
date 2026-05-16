<?php
// kot_invoice.php — Customer KOT invoice viewer & downloader
// Security rules:
//   - Must be logged in as customer
//   - Can only access their own invoice via a secret token
//   - Can VIEW (HTML) before payment is confirmed
//   - Can DOWNLOAD (PDF-like print page) only after payment confirmed

require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';

// ── Auth: customers only ──────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    header('Location: portal-login.php');
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    // Chef/staff must not access customer invoices — send to their own panel
    if ($_SESSION['role'] === 'chef') {
        header('Location: chef-control.php');
    } else {
        header('Location: staff-control.php');
    }
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// ── Validate token ────────────────────────────────────────────────────────────
$token = trim($_GET['token'] ?? '');
if (strlen($token) !== 64 || !ctype_alnum($token)) {
    http_response_code(403);
    die('Invalid invoice token.');
}

// ── Fetch invoice record — scoped to the current user ────────────────────────
$stmt = $conn->prepare("
    SELECT
        ki.invoice_id,
        ki.order_id,
        ki.user_id,
        ki.is_paid,
        ki.created_at    AS invoice_created_at,
        ki.paid_at,
        o.total_amount,
        o.status          AS order_status,
        o.payment_method,
        o.delivery_mode,
        o.special_notes,
        o.delivery_location_name,
        o.delivery_block_name,
        o.created_at     AS order_created_at,
        u.full_name,
        u.email,
        u.phone
    FROM kot_invoices ki
    JOIN orders o ON o.order_id = ki.order_id
    JOIN users  u ON u.user_id  = ki.user_id
    WHERE ki.invoice_token = ?
      AND ki.user_id       = ?
    LIMIT 1
");
$stmt->bind_param('si', $token, $user_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    http_response_code(403);
    die('Invoice not found or access denied.');
}

$order_id  = (int) $invoice['order_id'];
$is_paid   = (bool) $invoice['is_paid'];
$is_download = isset($_GET['download']) && $is_paid; // download only if paid

// ── Fetch order items ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT mi.name AS item_name, oi.quantity, oi.price
    FROM order_items oi
    JOIN menu_items mi ON mi.item_id = oi.item_id
    WHERE oi.order_id = ?
    ORDER BY oi.order_item_id
");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Record first download ─────────────────────────────────────────────────────
if ($is_download && empty($invoice['downloaded_at'])) {
    $conn->query("UPDATE kot_invoices SET downloaded_at = NOW() WHERE order_id = $order_id");
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$order_num     = str_pad($order_id, 4, '0', STR_PAD_LEFT);
$delivery_label = match($invoice['delivery_mode']) {
    'delivery' => 'Delivery 🚚',
    'takeaway' => 'Takeaway 🥡',
    'dine_in'  => 'Delivery 🚚',  // legacy value — map to Delivery
    default    => ucfirst($invoice['delivery_mode']),
};
$status_label  = match($invoice['order_status']) {
    'pending'          => 'In Process',
    'preparing'        => 'Preparing',
    'ready'            => 'Ready',
    'out_for_delivery' => 'On Delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
    default            => ucfirst(str_replace('_', ' ', $invoice['order_status'])),
};

// ── If download mode, output printer-friendly page ───────────────────────────
if ($is_download) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="invoice-' . $order_num . '.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= $order_num ?> — Herald Canteen</title>
    <?php if (!$is_download): ?>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php endif; ?>
    <style>
        /* Invoice-specific styles */
        @media print {
            .no-print { display: none !important; }
            body {
                background: #fff !important;
                color: #111 !important;
                margin: 0 !important;
                padding: 0 !important;
                min-height: unset !important;
                display: block !important;
            }
            .invoice-box {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                background: #fff !important;
                max-width: 100% !important;
                border-radius: 0 !important;
                padding: 20px 24px !important;
                page-break-inside: avoid;
            }
            .invoice-brand h2, .inv-num { color: #2a7a27 !important; }
            .info-block h4 { color: #2a7a27 !important; }
            .info-block p, .items-table td, .items-table th { color: #111 !important; }
            .items-table .total-row td { color: #2a7a27 !important; }
            .invoice-footer { color: #444 !important; }
            .preview-banner { display: none !important; }
        }

        @page { size: A4; margin: 15mm; }

        body.invoice-standalone {
            font-family: 'Segoe UI', sans-serif;
            background: var(--bg-color, #181818);
            color: var(--text-color, #fff);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 16px;
        }

        .invoice-box {
            background: var(--card-bg, #242424);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 36px 40px;
            max-width: 640px;
            width: 100%;
            box-shadow: 0 12px 40px rgba(0,0,0,0.4);
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(77,184,72,0.25);
        }

        .invoice-brand h2 {
            font-size: 22px;
            font-weight: 800;
            color: #4db848;
            margin-bottom: 4px;
        }

        .invoice-brand p {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
        }

        .invoice-meta {
            text-align: right;
            font-size: 13px;
        }

        .invoice-meta .inv-num {
            font-size: 18px;
            font-weight: 700;
            color: #4db848;
        }

        .invoice-meta p { color: rgba(255,255,255,0.55); margin: 3px 0; }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }

        .info-block h4 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #4db848;
            margin-bottom: 8px;
        }

        .info-block p {
            font-size: 13px;
            color: rgba(255,255,255,0.75);
            margin: 3px 0;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .items-table th {
            text-align: left;
            padding: 8px 10px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: rgba(255,255,255,0.4);
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .items-table td {
            padding: 10px 10px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.85);
        }

        .items-table .total-row td {
            border-top: 2px solid rgba(77,184,72,0.25);
            border-bottom: none;
            font-weight: 700;
            font-size: 15px;
            color: #4db848;
            padding-top: 14px;
        }

        .invoice-footer {
            text-align: center;
            font-size: 12px;
            color: rgba(255,255,255,0.35);
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.06);
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(77,184,72,0.15);
            color: #4db848;
        }

        .preview-banner {
            background: rgba(255,180,0,0.12);
            border: 1px solid rgba(255,180,0,0.25);
            border-radius: 10px;
            padding: 12px 18px;
            font-size: 13px;
            color: #fbbf24;
            margin-bottom: 20px;
            text-align: center;
        }

        .action-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .btn-invoice {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
        }

        .btn-invoice-primary {
            background: #4db848;
            color: #fff;
        }

        .btn-invoice-outline {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
            color: rgba(255,255,255,0.7);
        }
    </style>
</head>
<body class="invoice-standalone">

<?php if (!$is_download): ?>
<div class="action-bar no-print" style="max-width:640px;width:100%;">
    <a href="my_orders.php" class="btn-invoice btn-invoice-outline">← My Orders</a>
    <?php if ($is_paid): ?>
        <button class="btn-invoice btn-invoice-primary" onclick="window.print()">
            🖨️ Print / Save PDF
        </button>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$is_paid): ?>
<div class="preview-banner no-print">
    ⚠ Payment pending — this is a preview only. The download button will be available once payment is confirmed.
</div>
<?php endif; ?>

<div class="invoice-box">
    <div class="invoice-header">
        <div class="invoice-brand">
            <h2>Herald Canteen</h2>
            <p>Herald College Kathmandu</p>
        </div>
        <div class="invoice-meta">
            <div class="inv-num">Invoice #<?= $order_num ?></div>
            <p><?= date('d M Y, g:i A', strtotime($invoice['order_created_at'])) ?></p>
            <p><span class="status-badge"><?= htmlspecialchars($status_label) ?></span></p>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-block">
            <h4>Billed To</h4>
            <p><?= htmlspecialchars($invoice['full_name']) ?></p>
            <p><?= htmlspecialchars($invoice['email']) ?></p>
            <?php if ($invoice['phone']): ?>
                <p><?= htmlspecialchars($invoice['phone']) ?></p>
            <?php endif; ?>
        </div>
        <div class="info-block">
            <h4>Order Info</h4>
            <p>Order ID: <strong>#<?= $order_num ?></strong></p>
            <p>Mode: <?= htmlspecialchars($delivery_label) ?></p>
            <?php if ($invoice['delivery_mode'] === 'delivery'): ?>
            <p>📍 Location:
                <?php if (!empty($invoice['delivery_location_name'])): ?>
                    <strong><?= htmlspecialchars($invoice['delivery_location_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    — <?= htmlspecialchars($invoice['delivery_block_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <em style="opacity:.6;">Not specified</em>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <p>Payment: <?= strtoupper(htmlspecialchars($invoice['payment_method'])) ?></p>
            <?php if ($invoice['paid_at']): ?>
                <p>Paid: <?= date('d M Y', strtotime($invoice['paid_at'])) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($invoice['special_notes']): ?>
    <div style="margin-bottom:20px;background:rgba(255,255,255,0.04);border-radius:8px;padding:12px 14px;font-size:13px;color:rgba(255,255,255,0.65);">
        <strong>📝 Customer Remark:</strong> <?= htmlspecialchars($invoice['special_notes'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <table class="items-table">
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
                <td><?= htmlspecialchars($item['item_name']) ?></td>
                <td style="text-align:center;"><?= (int)$item['quantity'] ?></td>
                <td style="text-align:right;">Rs <?= number_format($item['price'], 2) ?></td>
                <td style="text-align:right;">Rs <?= number_format($item['price'] * $item['quantity'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3">Total</td>
                <td style="text-align:right;">Rs <?= number_format($invoice['total_amount'], 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="invoice-footer">
        <?php if ($is_paid): ?>
            ✅ Payment confirmed — Thank you for ordering from Herald Canteen!
        <?php else: ?>
            ⏳ Payment pending — Please complete your payment to confirm this order.
        <?php endif; ?>
        <br>This is a computer-generated invoice. No signature required.
    </div>
</div>

<?php if ($is_download): ?>
<script>window.onload = function() { window.print(); }</script>
<?php endif; ?>

</body>
</html>
