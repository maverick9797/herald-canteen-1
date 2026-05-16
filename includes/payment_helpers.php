<?php
/**
 * Payment helper functions for Herald Canteen.
 *
 * These helpers keep COD and eSewa order creation independent from stale
 * browser/session state and from small database-version differences.
 */

if (!defined('DELIVERY_FEE')) {
    define('DELIVERY_FEE', 30);
}
if (!defined('FREE_DELIVERY_THRESHOLD')) {
    define('FREE_DELIVERY_THRESHOLD', 500);
}

function hc_generate_payment_uuid(): string
{
    // eSewa-safe: alphanumeric characters and hyphen only.
    return 'HC-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(6)));
}

function hc_generate_checkout_token(): string
{
    return bin2hex(random_bytes(32));
}

function hc_money_string($amount): string
{
    return number_format((float)$amount, 2, '.', '');
}

function hc_normalize_amount($amount): float
{
    if (is_string($amount)) {
        $amount = str_replace(',', '', trim($amount));
    }
    return round((float)$amount, 2);
}

function hc_fetch_cart_items(mysqli $conn, int $user_id): array
{
    $stmt = $conn->prepare("\n        SELECT c.cart_id, c.quantity, m.item_id, m.name, m.price\n        FROM cart c\n        JOIN menu_items m ON c.item_id = m.item_id\n        WHERE c.user_id = ?\n        ORDER BY c.cart_id ASC\n    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare cart query: ' . $conn->error);
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

function hc_cart_subtotal(array $cart_items): float
{
    $subtotal = 0.0;
    foreach ($cart_items as $item) {
        $subtotal += ((float)$item['price']) * ((int)$item['quantity']);
    }
    return round($subtotal, 2);
}

function hc_delivery_fee_for(string $delivery_mode, float $subtotal): float
{
    $subtotal = hc_normalize_amount($subtotal);
    return ($delivery_mode === 'delivery' && $subtotal > 0 && $subtotal < FREE_DELIVERY_THRESHOLD) ? (float)DELIVERY_FEE : 0.0;
}

function hc_calculate_totals(array $cart_items, string $delivery_mode): array
{
    $subtotal = hc_cart_subtotal($cart_items);
    $delivery_fee = hc_delivery_fee_for($delivery_mode, $subtotal);
    return [
        'subtotal' => $subtotal,
        'delivery_fee' => $delivery_fee,
        'total' => round($subtotal + $delivery_fee, 2),
    ];
}

function hc_validate_delivery_location(mysqli $conn, string $delivery_mode, ?int $location_id): array
{
    if ($delivery_mode !== 'delivery') {
        return [null, null, null];
    }

    if (empty($location_id) || $location_id <= 0) {
        throw new InvalidArgumentException('no_location');
    }

    $stmt = $conn->prepare("SELECT location_id, location_name, block_name FROM delivery_locations WHERE location_id = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare delivery location query: ' . $conn->error);
    }
    $stmt->bind_param('i', $location_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new InvalidArgumentException('invalid_location');
    }

    return [(int)$row['location_id'], $row['location_name'], $row['block_name']];
}

function hc_flush_mysqli_results(mysqli $conn): void
{
    while ($conn->more_results() && $conn->next_result()) {
        $extra = $conn->use_result();
        if ($extra) {
            $extra->free();
        }
    }
}

function hc_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS total\n        FROM information_schema.TABLES\n        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?\n    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row['total']);
}

function hc_column_exists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS total\n        FROM information_schema.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?\n    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row['total']);
}

function hc_procedure_exists(mysqli $conn, string $procedure): bool
{
    $stmt = $conn->prepare("\n        SELECT COUNT(*) AS total\n        FROM information_schema.ROUTINES\n        WHERE ROUTINE_SCHEMA = DATABASE()\n          AND ROUTINE_NAME = ?\n          AND ROUTINE_TYPE = 'PROCEDURE'\n    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $procedure);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row['total']);
}

function hc_direct_create_kot_and_invoice(mysqli $conn, int $order_id, int $user_id, string $delivery_mode, ?string $special_notes): void
{
    if (!hc_table_exists($conn, 'kitchen_order_tickets')) {
        throw new RuntimeException('kitchen_order_tickets table is missing. Run database/payment_core_fix_migration.sql.');
    }
    if (!hc_table_exists($conn, 'kot_invoices')) {
        throw new RuntimeException('kot_invoices table is missing. Run database/payment_core_fix_migration.sql.');
    }

    $has_user_id = hc_column_exists($conn, 'kitchen_order_tickets', 'user_id');
    if ($has_user_id) {
        $stmt = $conn->prepare("\n            INSERT INTO kitchen_order_tickets\n                (order_id, user_id, kot_status, delivery_mode, special_notes)\n            VALUES (?, ?, 'active', ?, ?)\n            ON DUPLICATE KEY UPDATE\n                user_id = VALUES(user_id),\n                delivery_mode = VALUES(delivery_mode),\n                special_notes = VALUES(special_notes),\n                kot_status = 'active'\n        ");
        if (!$stmt) {
            throw new RuntimeException('KOT prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('iiss', $order_id, $user_id, $delivery_mode, $special_notes);
    } else {
        $stmt = $conn->prepare("\n            INSERT INTO kitchen_order_tickets\n                (order_id, kot_status, delivery_mode, special_notes)\n            VALUES (?, 'active', ?, ?)\n            ON DUPLICATE KEY UPDATE\n                delivery_mode = VALUES(delivery_mode),\n                special_notes = VALUES(special_notes),\n                kot_status = 'active'\n        ");
        if (!$stmt) {
            throw new RuntimeException('KOT prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('iss', $order_id, $delivery_mode, $special_notes);
    }
    $stmt->execute();
    $stmt->close();

    $invoice_token = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("\n        INSERT INTO kot_invoices\n            (order_id, user_id, invoice_token, is_paid)\n        SELECT ?, ?, ?, 0\n        FROM DUAL\n        WHERE NOT EXISTS (SELECT 1 FROM kot_invoices WHERE order_id = ?)\n    ");
    if (!$stmt) {
        throw new RuntimeException('Invoice prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iisi', $order_id, $user_id, $invoice_token, $order_id);
    $stmt->execute();
    $stmt->close();
}

function hc_create_kot_and_invoice(mysqli $conn, int $order_id, int $user_id, string $delivery_mode, ?string $special_notes): void
{
    // Prefer the stored procedure when it exists and matches the current schema.
    // If an older/incompatible procedure exists, fall back to direct inserts so
    // checkout is not blocked by a DB-version mismatch.
    if (hc_procedure_exists($conn, 'create_kot_and_invoice')) {
        $stmt = $conn->prepare("CALL create_kot_and_invoice(?, ?, ?, ?)");
        if ($stmt) {
            try {
                $stmt->bind_param('iiss', $order_id, $user_id, $delivery_mode, $special_notes);
                $stmt->execute();
                $stmt->free_result();
                $stmt->close();
                hc_flush_mysqli_results($conn);
                return;
            } catch (Throwable $e) {
                try { $stmt->close(); } catch (Throwable $ignored) {}
                hc_flush_mysqli_results($conn);
                error_log('KOT/invoice procedure failed; using direct fallback: ' . $e->getMessage());
            }
        }
    }

    hc_direct_create_kot_and_invoice($conn, $order_id, $user_id, $delivery_mode, $special_notes);
}

function hc_order_exists_for_transaction(mysqli $conn, string $payment_method, string $transaction_uuid): ?int
{
    $stmt = $conn->prepare("\n        SELECT order_id\n        FROM payments\n        WHERE payment_method = ?\n          AND transaction_uuid = ?\n          AND payment_status = 'successful'\n        LIMIT 1\n    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $payment_method, $transaction_uuid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['order_id'] : null;
}

function hc_order_exists_for_checkout_token(mysqli $conn, int $user_id, ?string $checkout_token): ?int
{
    if (empty($checkout_token) || !hc_column_exists($conn, 'orders', 'checkout_token')) {
        return null;
    }

    $stmt = $conn->prepare("\n        SELECT order_id\n        FROM orders\n        WHERE user_id = ? AND checkout_token = ?\n        LIMIT 1\n    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('is', $user_id, $checkout_token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int)$row['order_id'] : null;
}

function hc_insert_order(
    mysqli $conn,
    int $user_id,
    array $totals,
    string $payment_method,
    string $delivery_mode,
    ?string $special_notes,
    ?int $delivery_location_id,
    ?string $delivery_location_name,
    ?string $delivery_block_name,
    ?string $checkout_token = null
): int {
    $subtotal = hc_normalize_amount($totals['subtotal'] ?? 0);
    $delivery_fee = hc_normalize_amount($totals['delivery_fee'] ?? 0);
    $total = hc_normalize_amount($totals['total'] ?? ($subtotal + $delivery_fee));

    // Current fixed schema: subtotal/delivery/location/checkout columns exist.
    if (hc_column_exists($conn, 'orders', 'subtotal_amount')
        && hc_column_exists($conn, 'orders', 'delivery_fee')
        && hc_column_exists($conn, 'orders', 'delivery_mode')
        && hc_column_exists($conn, 'orders', 'checkout_token')) {
        $stmt = $conn->prepare("\n            INSERT INTO orders\n                (user_id, subtotal_amount, delivery_fee, total_amount, status, payment_method, delivery_mode,\n                 delivery_location_id, delivery_location_name, delivery_block_name, special_notes, checkout_token)\n            VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)\n        ");
        if (!$stmt) {
            throw new RuntimeException('Order prepare failed: ' . $conn->error);
        }
        $stmt->bind_param(
            'idddssissss',
            $user_id,
            $subtotal,
            $delivery_fee,
            $total,
            $payment_method,
            $delivery_mode,
            $delivery_location_id,
            $delivery_location_name,
            $delivery_block_name,
            $special_notes,
            $checkout_token
        );
        $stmt->execute();
        $order_id = (int)$conn->insert_id;
        $stmt->close();
        return $order_id;
    }

    // Backward-compatible fallback for very old databases.
    $stmt = $conn->prepare("\n        INSERT INTO orders (user_id, total_amount, status, payment_method)\n        VALUES (?, ?, 'pending', ?)\n    ");
    if (!$stmt) {
        throw new RuntimeException('Legacy order prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('ids', $user_id, $total, $payment_method);
    $stmt->execute();
    $order_id = (int)$conn->insert_id;
    $stmt->close();
    return $order_id;
}

function hc_insert_order_items(mysqli $conn, int $order_id, array $cart_items): void
{
    $stmt = $conn->prepare("INSERT INTO order_items (order_id, item_id, quantity, price) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Order items prepare failed: ' . $conn->error);
    }
    foreach ($cart_items as $item) {
        $iid = (int)$item['item_id'];
        $qty = (int)$item['quantity'];
        $pr  = (float)$item['price'];
        $stmt->bind_param('iiid', $order_id, $iid, $qty, $pr);
        $stmt->execute();
    }
    $stmt->close();
}

function hc_clear_cart(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
    if (!$stmt) {
        throw new RuntimeException('Cart clear prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}
