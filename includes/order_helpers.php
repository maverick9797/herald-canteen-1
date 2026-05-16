<?php
/**
 * Order helper functions for customer order history and safe reordering.
 */

if (!function_exists('hc_reorder_to_cart')) {
    /**
     * Replace the customer's current cart with the exact available items from a past order.
     *
     * This is intentionally idempotent: clicking Reorder again for the same order creates
     * the same cart state instead of adding quantities on top of existing cart rows.
     * Current menu prices are used when storing cart.total_price.
     */
    function hc_reorder_to_cart(mysqli $conn, int $user_id, int $order_id): array
    {
        if ($order_id <= 0) {
            return [
                'ok' => false,
                'message' => 'Invalid order selected for reorder.',
                'warning' => null,
                'added_count' => 0,
                'skipped_count' => 0,
            ];
        }

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("\n                SELECT order_id, status\n                FROM orders\n                WHERE order_id = ? AND user_id = ?\n                LIMIT 1\n                FOR UPDATE\n            ");
            if (!$stmt) {
                throw new RuntimeException('Unable to verify order: ' . $conn->error);
            }
            $stmt->bind_param('ii', $order_id, $user_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order) {
                $conn->rollback();
                return [
                    'ok' => false,
                    'message' => 'That order could not be found or does not belong to your account.',
                    'warning' => null,
                    'added_count' => 0,
                    'skipped_count' => 0,
                ];
            }

            $stmt = $conn->prepare("\n                SELECT\n                    oi.item_id,\n                    SUM(oi.quantity) AS quantity,\n                    mi.name,\n                    mi.price,\n                    mi.is_available\n                FROM order_items oi\n                LEFT JOIN menu_items mi ON mi.item_id = oi.item_id\n                WHERE oi.order_id = ?\n                GROUP BY oi.item_id, mi.name, mi.price, mi.is_available\n                ORDER BY MIN(oi.order_item_id) ASC\n            ");
            if (!$stmt) {
                throw new RuntimeException('Unable to fetch order items: ' . $conn->error);
            }
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($rows)) {
                $conn->rollback();
                return [
                    'ok' => false,
                    'message' => 'This order has no items to reorder.',
                    'warning' => null,
                    'added_count' => 0,
                    'skipped_count' => 0,
                ];
            }

            $available_items = [];
            $skipped_count = 0;

            foreach ($rows as $row) {
                $quantity = max(0, (int)($row['quantity'] ?? 0));
                $has_menu_item = !empty($row['name']) && $row['price'] !== null;
                $is_available = $has_menu_item && (int)($row['is_available'] ?? 0) === 1;

                if ($quantity > 0 && $is_available) {
                    $available_items[] = [
                        'item_id' => (int)$row['item_id'],
                        'quantity' => $quantity,
                        'price' => (float)$row['price'],
                    ];
                } else {
                    $skipped_count++;
                }
            }

            if (empty($available_items)) {
                $conn->rollback();
                return [
                    'ok' => false,
                    'message' => 'No items from this order are currently available, so your cart was not changed.',
                    'warning' => null,
                    'added_count' => 0,
                    'skipped_count' => $skipped_count,
                ];
            }

            $stmt = $conn->prepare('DELETE FROM cart WHERE user_id = ?');
            if (!$stmt) {
                throw new RuntimeException('Unable to clear current cart: ' . $conn->error);
            }
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("\n                INSERT INTO cart (user_id, item_id, quantity, total_price)\n                VALUES (?, ?, ?, ?)\n                ON DUPLICATE KEY UPDATE\n                    quantity = VALUES(quantity),\n                    total_price = VALUES(total_price),\n                    added_at = CURRENT_TIMESTAMP\n            ");
            if (!$stmt) {
                throw new RuntimeException('Unable to rebuild cart: ' . $conn->error);
            }

            foreach ($available_items as $item) {
                $line_total = round($item['price'] * $item['quantity'], 2);
                $stmt->bind_param('iiid', $user_id, $item['item_id'], $item['quantity'], $line_total);
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();

            return [
                'ok' => true,
                'message' => 'Your cart has been replaced with the selected previous order.',
                'warning' => $skipped_count > 0 ? 'Some items from this order are no longer available and were not added.' : null,
                'added_count' => count($available_items),
                'skipped_count' => $skipped_count,
            ];
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {}

            error_log('Reorder failed: ' . $e->getMessage());
            return [
                'ok' => false,
                'message' => 'Unable to reorder right now. Please try again.',
                'warning' => null,
                'added_count' => 0,
                'skipped_count' => 0,
            ];
        }
    }
}
