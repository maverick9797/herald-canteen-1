<?php
// payment_preview.php — UI PREVIEW ONLY, no DB or session needed
// Delete this file before going live!

$full_name        = "Sangam Rijal";
$total            = 305.00;
$transaction_uuid = "hc_preview_123";

$cart_items = [
    ['name' => 'Momo (8 pcs)',  'quantity' => 2, 'price' => 90.00],
    ['name' => 'Thukpa',        'quantity' => 1, 'price' => 100.00],
    ['name' => 'Masala Tea',    'quantity' => 1, 'price' => 25.00],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — Herald Canteen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "Segoe UI", sans-serif;
            background: #181818;
            color: #ffffff;
        }

        a { text-decoration: none; }

        /* ── Layout ── */
        .layout { display: flex; min-height: 100vh; }

        /* ── Sidebar (identical to dashboard) ── */
        .sidebar {
            width: 220px;
            background: #2e2e2e;
            color: white;
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            gap: 30px;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 10;
            border-right: 1px solid rgba(77, 184, 72, 0.1);
        }
        .sidebar h3 { font-size: 22px; color: #4db848; }
        .sidebar nav { display: flex; flex-direction: column; gap: 6px; }
        .sidebar nav a {
            color: rgba(255,255,255,0.45);
            font-size: 14px;
            padding: 10px 14px;
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sidebar nav a:hover,
        .sidebar nav a.active {
            background: rgba(77,184,72,0.1);
            color: #4db848;
            border: 1px solid rgba(77,184,72,0.15);
        }

        /* ── Main ── */
        .main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; }

        /* ── Topbar ── */
        .topbar {
            padding: 16px 30px;
            background: rgba(24,24,24,0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(77,184,72,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        .topbar-left h2 {
            font-size: 18px;
            font-weight: 600;
            font-family: "Poppins", "Segoe UI", sans-serif;
        }
        .topbar-left p { font-size: 13px; color: rgba(255,255,255,0.4); margin-top: 2px; }

        /* ── Content ── */
        .content { padding: 30px; }

        /* ── Preview badge ── */
        .preview-badge {
            display: inline-block;
            background: rgba(77,184,72,0.1);
            color: #4db848;
            border: 1px solid rgba(77,184,72,0.3);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            margin-bottom: 20px;
        }

        /* ── Section title ── */
        .section-title h2 {
            font-size: 30px;
            font-weight: 700;
            color: #ffffff;
            font-family: "Poppins", "Segoe UI", sans-serif;
            letter-spacing: 0.5px;
        }
        .section-title p {
            margin-top: 8px;
            margin-bottom: 24px;
            font-size: 15px;
            font-weight: 600;
            color: #4db848;
            font-family: "Poppins", "Segoe UI", sans-serif;
            letter-spacing: 0.3px;
        }

        /* ── Alert ── */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 14px;
            font-size: 14px;
        }
        .alert.success {
            background: rgba(77,184,72,0.1);
            color: #6dcc68;
            border: 1px solid rgba(77,184,72,0.3);
        }
        .alert.error {
            background: rgba(229,57,53,0.1);
            color: #ef9a9a;
            border: 1px solid rgba(229,57,53,0.3);
        }

        /* ── Checkout grid ── */
        .checkout-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 20px;
            align-items: start;
            margin-top: 24px;
        }

        /* ── Card (matches popup style) ── */
        .card {
            background: #2a2a2a;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3);
        }
        .card-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(77,184,72,0.7);
            margin-bottom: 16px;
        }

        /* ── Order items ── */
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 13px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .order-item:last-child { border-bottom: none; }
        .order-item-name { font-size: 14px; font-weight: 600; color: #ffffff; margin-bottom: 3px; }
        .order-item-qty  { font-size: 12px; color: rgba(255,255,255,0.4); }
        .order-item-price { font-size: 14px; font-weight: 700; color: #4db848; }

        .order-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .order-total span:first-child {
            font-size: 15px;
            font-weight: 700;
            font-family: "Poppins", "Segoe UI", sans-serif;
        }
        .total-amount {
            font-size: 22px;
            font-weight: 700;
            color: #4db848;
            font-family: "Poppins", "Segoe UI", sans-serif;
        }

        /* ── Payment method grid ── */
        .method-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }
        .method-card { position: relative; }
        .method-card input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .method-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px 10px;
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            background: #212121;
            cursor: pointer;
            transition: all 0.2s;
        }
        .method-card input:checked + .method-label {
            border-color: rgba(77,184,72,0.5);
            background: rgba(77,184,72,0.08);
            box-shadow: 0 0 0 1px rgba(77,184,72,0.2);
        }
        .method-label:hover {
            border-color: rgba(77,184,72,0.25);
            background: rgba(77,184,72,0.05);
        }
        .method-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
        }
        .icon-cod    { background: rgba(77,184,72,0.12); }
        .icon-esewa  { background: rgba(96,187,70,0.12); }
        .icon-khalti { background: rgba(92,45,145,0.15); }
        .icon-card   { background: rgba(100,160,230,0.12); }

        .method-name {
            font-size: 12px;
            font-weight: 700;
            font-family: "Poppins", "Segoe UI", sans-serif;
            color: #ffffff;
            text-align: center;
        }
        .method-sub { font-size: 11px; color: rgba(255,255,255,0.4); text-align: center; }

        /* ── Pay button (matches .add-btn) ── */
        #pay-btn {
            width: 100%;
            padding: 12px 20px;
            background: #4db848;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            font-family: "Poppins", "Segoe UI", sans-serif;
            cursor: pointer;
            transition: background 0.2s;
            box-shadow: 0 4px 12px rgba(77,184,72,0.25);
        }
        #pay-btn:hover { background: #3a9236; }

        @media (max-width: 900px) {
            .checkout-grid { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { margin-left: 0; }
        }
    </style>
</head>
<body>
<div class="layout">

    <!-- Sidebar -->
    <div class="sidebar">
        <h3>Herald Canteen</h3>
        <nav>
            <a href="dashboard.php">Home</a>
            <a href="my_cart.php">My Cart</a>
            <a href="orders.php">My Orders</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="main">

        <!-- Topbar -->
        <div class="topbar">
            <div class="topbar-left">
                <h2>Checkout</h2>
                <p>Hello <?php echo htmlspecialchars($full_name); ?>, review your order below</p>
            </div>
            <!-- Theme Toggle -->
            <label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label>
        </div>

        <div class="content">

            <div class="preview-badge">👁 PREVIEW MODE — no real data</div>

            <!-- Alert previews -->
            <div class="alert success">✅ Payment successful! Your order has been placed.</div>
            <div class="alert error">❌ Payment failed or was cancelled. Please try again.</div>

            <div class="section-title">
                <h2>Payment</h2>
                <p>Choose how you'd like to pay</p>
            </div>

            <div class="checkout-grid">

                <!-- Order Summary -->
                <div class="card">
                    <div class="card-title">Order Summary</div>
                    <?php foreach ($cart_items as $item): ?>
                    <div class="order-item">
                        <div>
                            <div class="order-item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                            <div class="order-item-qty">Qty: <?php echo $item['quantity']; ?></div>
                        </div>
                        <div class="order-item-price">
                            Rs. <?php echo number_format($item['price'] * $item['quantity'], 2); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="order-total">
                        <span>Total</span>
                        <span class="total-amount">Rs. <?php echo number_format($total, 2); ?></span>
                    </div>
                </div>

                <!-- Payment Methods -->
                <div class="card">
                    <div class="card-title">Payment Method</div>

                    <div class="method-grid">
                        <label class="method-card">
                            <input type="radio" name="payment_method" value="cod" checked>
                            <div class="method-label">
                                <div class="method-icon icon-cod">💵</div>
                                <div class="method-name">Cash on Delivery</div>
                                <div class="method-sub">Pay when received</div>
                            </div>
                        </label>

                        <label class="method-card">
                            <input type="radio" name="payment_method" value="esewa">
                            <div class="method-label">
                                <div class="method-icon icon-esewa">🟢</div>
                                <div class="method-name">eSewa</div>
                                <div class="method-sub">Digital wallet</div>
                            </div>
                        </label>

                        <label class="method-card">
                            <input type="radio" name="payment_method" value="khalti">
                            <div class="method-label">
                                <div class="method-icon icon-khalti">🟣</div>
                                <div class="method-name">Khalti</div>
                                <div class="method-sub">Digital wallet</div>
                            </div>
                        </label>

                        <label class="method-card">
                            <input type="radio" name="payment_method" value="card">
                            <div class="method-label">
                                <div class="method-icon icon-card">💳</div>
                                <div class="method-name">Card</div>
                                <div class="method-sub">Via eSewa gateway</div>
                            </div>
                        </label>
                    </div>

                    <button id="pay-btn" onclick="previewPay()">
                        Pay Rs. <?php echo number_format($total, 2); ?>
                    </button>

                    <p style="color:rgba(255,255,255,0.3);font-size:12px;text-align:center;margin-top:10px;">
                        🔒 Preview only — no real charges
                    </p>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
function previewPay() {
    const method = document.querySelector('input[name="payment_method"]:checked').value;
    const labels = {
        cod:    'COD → cod_confirm.php → orders.php',
        esewa:  'eSewa → redirects to eSewa page → esewa_verify.php → orders.php',
        khalti: 'Khalti → khalti_initiate.php → Khalti page → khalti_verify.php → orders.php',
        card:   'Card → eSewa gateway → esewa_verify.php → orders.php',
    };
    alert('PREVIEW: ' + labels[method]);
}
</script>
</body>
</html>