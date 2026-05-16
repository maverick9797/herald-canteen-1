<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";

require_role('chef');

// Helper function for sanitizing input
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}


// Helper function for validating and sanitizing text with max length
function validate_text($input, $field_name, $max_length, &$errors, $required = true) {
    $cleaned = trim($input);
    
    if ($required && empty($cleaned)) {
        $errors[$field_name] = ucfirst($field_name) . " is required.";
        return false;
    }
    
    if (!empty($cleaned) && strlen($cleaned) > $max_length) {
        $errors[$field_name] = ucfirst($field_name) . " cannot exceed " . $max_length . " characters.";
        return false;
    }
    
    // Sanitize XSS
    $sanitized = htmlspecialchars($cleaned, ENT_QUOTES, 'UTF-8');
    return $sanitized;
}

// Helper function for validating price
function validate_price($price, &$errors) {
    $cleaned = filter_var(trim($price), FILTER_VALIDATE_FLOAT);
    
    if ($cleaned === false || $cleaned <= 0) {
        $errors['price'] = "Price must be a positive number greater than 0.";
        return false;
    }
    
    if ($cleaned > 999999.99) {
        $errors['price'] = "Price cannot exceed 999,999.99.";
        return false;
    }
    
    return round($cleaned, 2);
}

// Helper function for validating rating
function validate_rating($rating, &$errors) {
    $cleaned = filter_var(trim($rating), FILTER_VALIDATE_FLOAT);
    
    if ($cleaned === false) {
        $errors['rating'] = "Rating must be a valid number.";
        return false;
    }
    
    if ($cleaned < 0 || $cleaned > 5) {
        $errors['rating'] = "Rating must be between 0 and 5.";
        return false;
    }
    
    return round($cleaned, 1);
}


function ops_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'preparing' => 'Preparing',
        'ready' => 'Ready',
        'out_for_delivery' => 'On Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function ops_delivery_label(?string $mode): string
{
    return $mode === 'takeaway' ? 'Takeaway / Pickup' : 'Delivery';
}

function ops_age_label(?string $datetime): string
{
    if (!$datetime) {
        return 'Time unavailable';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return 'Time unavailable';
    }

    $diff = max(0, time() - $timestamp);
    if ($diff < 60) {
        return 'Just now';
    }

    $minutes = floor($diff / 60);
    if ($minutes < 60) {
        return $minutes . ' min ago';
    }

    $hours = floor($minutes / 60);
    if ($hours < 24) {
        return $hours . ' hr ago';
    }

    $days = floor($hours / 24);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function ops_urgency_class(?string $datetime): string
{
    $timestamp = $datetime ? strtotime($datetime) : false;
    if (!$timestamp) {
        return 'normal';
    }

    $minutes = floor(max(0, time() - $timestamp) / 60);
    if ($minutes >= 20) {
        return 'urgent';
    }
    if ($minutes >= 10) {
        return 'warning';
    }
    return 'normal';
}

function upload_category_image(array $file, array &$errors): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors['image'] = "Image upload failed.";
        return null;
    }

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed_extensions, true)) {
        $errors['image'] = "Only JPG, JPEG, PNG, and WEBP images are allowed.";
        return null;
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        $errors['image'] = "Image size cannot exceed 5MB.";
        return null;
    }

    $image_info = @getimagesize($file['tmp_name']);
    if ($image_info === false) {
        $errors['image'] = "Uploaded file is not a valid image.";
        return null;
    }

    $upload_dir = dirname(__DIR__) . "/assets/images/";

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $new_filename = "category_" . time() . "_" . uniqid() . "." . $extension;
    $target_path = $upload_dir . $new_filename;

    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        $errors['image'] = "Failed to save uploaded image.";
        return null;
    }

    return "../assets/images/" . $new_filename;
}

function delete_old_category_image(?string $image_path): void
{
    if (!$image_path) {
        return;
    }

    $filename = basename($image_path);
    $full_path = dirname(__DIR__) . "/assets/images/" . $filename;

    if (is_file($full_path)) {
        unlink($full_path);
    }
}

$message = '';
$errors = []; // Associative array for inline errors

// Pick up flash toast from redirect
$chef_toast = null;
if (isset($_SESSION['_toast'])) {
    $chef_toast = $_SESSION['_toast'];
    unset($_SESSION['_toast']);
}
$edit_item = null;
$edit_category = null;
$form_data = []; // Preserve form data on error

/* ---------------------------
   HANDLE ADD CATEGORY
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $form_data['category_name'] = $_POST['category_name'] ?? '';
    $form_data['category_description'] = $_POST['category_description'] ?? '';
    $form_data['category_is_available'] = isset($_POST['category_is_available']);
    
    $category_name = validate_text($_POST['category_name'] ?? '', 'category name', 100, $errors, true);
    
    $category_description = validate_text($_POST['category_description'] ?? '', 'description', 500, $errors, false);
    if ($category_description === false) {
        $category_description = '';
    }
    
    $category_is_available = isset($_POST['category_is_available']) ? 1 : 0;

    $category_image_url = upload_category_image($_FILES['category_image'] ?? [], $errors);

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO categories (name, image_url, description, is_available)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("sssi", $category_name, $category_image_url, $category_description, $category_is_available);

        if ($stmt->execute()) {
            $_SESSION["_toast"] = ["text" => "Category added successfully.", "type" => "success"];
            $form_data = [];
            session_write_close(); // FIX: flush toast to disk before redirect
            header("Location: chef-control.php#categories-section");
            exit;
        } else {
            $errors['general'] = "Failed to add category. Category name may already exist.";
        }

        $stmt->close();
    }
}

/* ---------------------------
   LOAD CATEGORY FOR EDIT
---------------------------- */
if (isset($_GET['edit_category_id'])) {
    $edit_category_id = filter_var($_GET['edit_category_id'], FILTER_VALIDATE_INT);
    
    if ($edit_category_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM categories WHERE category_id = ?");
        $stmt->bind_param("i", $edit_category_id);
        $stmt->execute();
        $edit_category = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* ---------------------------
   HANDLE UPDATE CATEGORY
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $category_id = filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT);
    
    $category_name = validate_text($_POST['category_name'] ?? '', 'category name', 100, $errors, true);
    
    $category_description = validate_text($_POST['category_description'] ?? '', 'description', 500, $errors, false);
    if ($category_description === false) {
        $category_description = '';
    }
    
    $category_is_available = isset($_POST['category_is_available']) ? 1 : 0;
    $current_image = $_POST['current_image'] ?? '';

    if ($category_id === false || $category_id <= 0) {
        $errors['general'] = "Invalid category.";
    }

    $new_uploaded_image = upload_category_image($_FILES['category_image'] ?? [], $errors);
    $final_image_url = $current_image;

    if ($new_uploaded_image !== null) {
        if ($current_image !== '') {
            delete_old_category_image($current_image);
        }
        $final_image_url = $new_uploaded_image;
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE categories
            SET name = ?, image_url = ?, description = ?, is_available = ?
            WHERE category_id = ?
        ");
        $stmt->bind_param("sssii", $category_name, $final_image_url, $category_description, $category_is_available, $category_id);

        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION["_toast"] = ["text" => "Category updated successfully.", "type" => "success"];
            // FIX: was missing a redirect (no PRG pattern) — the toast was set
            // then immediately consumed on the same render, so it showed once
            // but a browser refresh would resubmit the POST. Add PRG + flush.
            session_write_close();
            header("Location: chef-control.php#categories-section");
            exit;
        } else {
            $errors['general'] = "Failed to update category.";
        }

        $stmt->close();
    }
}

/* ---------------------------
   HANDLE ADD MENU ITEM
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $form_data['category_id'] = $_POST['category_id'] ?? '';
    $form_data['name'] = $_POST['name'] ?? '';
    $form_data['description'] = $_POST['description'] ?? '';
    $form_data['price'] = $_POST['price'] ?? '';
    $form_data['rating'] = $_POST['rating'] ?? '';
    $form_data['is_available'] = isset($_POST['is_available']);
    
    $category_id = filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT);
    $name = validate_text($_POST['name'] ?? '', 'item name', 150, $errors, true);
    $description = validate_text($_POST['description'] ?? '', 'description', 500, $errors, false);
    if ($description === false) {
        $description = '';
    }
    $price = validate_price($_POST['price'] ?? 0, $errors);
    $rating = validate_rating($_POST['rating'] ?? 0, $errors);
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    if ($category_id === false || $category_id <= 0) {
        $errors['category_id'] = "Please select a valid category.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO menu_items (category_id, name, description, price, rating, is_available)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issddi", $category_id, $name, $description, $price, $rating, $is_available);

        if ($stmt->execute()) {
            $_SESSION["_toast"] = ["text" => "Menu item added successfully.", "type" => "success"];
            $form_data = [];
            session_write_close(); // FIX: flush toast to disk before redirect
            header("Location: chef-control.php#menu-section");
            exit;
        } else {
            $errors['general'] = "Failed to add menu item.";
        }

        $stmt->close();
    }
}

/* ---------------------------
   HANDLE DELETE MENU ITEM
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $is_ajax_chef = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $item_id = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT);

    if ($item_id > 0) {
        $stmt = $conn->prepare("DELETE FROM menu_items WHERE item_id = ?");
        $stmt->bind_param("i", $item_id);

        if ($stmt->execute()) {
            $stmt->close();
            if ($is_ajax_chef) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'item_id' => $item_id]);
                exit;
            }
            $_SESSION["_toast"] = ["text" => "Menu item deleted successfully.", "type" => "danger"];
            session_write_close();
            header("Location: chef-control.php#menu-section");
            exit;
        } else {
            $stmt->close();
            if ($is_ajax_chef) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Failed to delete menu item.']);
                exit;
            }
            $errors['general'] = "Failed to delete menu item.";
        }
    } else {
        if (!empty($is_ajax_chef)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid item ID.']);
            exit;
        }
    }
}

/* ---------------------------
   LOAD ITEM FOR EDIT
---------------------------- */
if (isset($_GET['edit_id'])) {
    $edit_id = filter_var($_GET['edit_id'], FILTER_VALIDATE_INT);

    if ($edit_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM menu_items WHERE item_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $edit_item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

/* ---------------------------
   HANDLE UPDATE MENU ITEM
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $item_id = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT);
    $category_id = filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT);
    $name = validate_text($_POST['name'] ?? '', 'item name', 150, $errors, true);
    $description = validate_text($_POST['description'] ?? '', 'description', 500, $errors, false);
    if ($description === false) {
        $description = '';
    }
    $price = validate_price($_POST['price'] ?? 0, $errors);
    $rating = validate_rating($_POST['rating'] ?? 0, $errors);
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    if ($item_id === false || $item_id <= 0) {
        $errors['general'] = "Invalid menu item.";
    }
    
    if ($category_id === false || $category_id <= 0) {
        $errors['category_id'] = "Please select a valid category.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE menu_items
            SET category_id = ?, name = ?, description = ?, price = ?, rating = ?, is_available = ?
            WHERE item_id = ?
        ");
        $stmt->bind_param("issddii", $category_id, $name, $description, $price, $rating, $is_available, $item_id);

        if ($stmt->execute()) {
            $_SESSION["_toast"] = ["text" => "Menu item updated successfully.", "type" => "success"];
            session_write_close(); // FIX: flush toast to disk before redirect
            header("Location: chef-control.php#menu-section");
            exit;
        } else {
            $errors['general'] = "Failed to update menu item.";
        }

        $stmt->close();
    }
}

/* ---------------------------
   HANDLE ORDER STATUS UPDATE
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $is_ajax_chef = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $order_id = filter_var($_POST['order_id'] ?? 0, FILTER_VALIDATE_INT);
    $new_status = sanitize_input($_POST['new_status'] ?? '');

    if ($order_id > 0 && in_array($new_status, ['preparing', 'ready'], true)) {
        $stmt = $conn->prepare("SELECT status FROM orders WHERE order_id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $current_order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($current_order) {
            $current_status = $current_order['status'];

            $allowed_transition =
                ($current_status === 'pending' && $new_status === 'preparing') ||
                ($current_status === 'preparing' && $new_status === 'ready');

            if ($allowed_transition) {
                $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
                $stmt->bind_param("si", $new_status, $order_id);

                if ($stmt->execute()) {
                    $stmt->close();

                    // Notify the customer about their order status change
                    $notif_data = match($new_status) {
                        'preparing' => ['🍳 Your Order is Being Prepared', 'Great news! The kitchen has started preparing your order. It will be ready soon.', 'ready'],
                        'ready'     => ['✅ Your Order is Ready!', 'Your order is ready and will be handed to delivery soon.', 'ready'],
                        default     => null,
                    };
                    if ($notif_data) {
                        // Get the user_id for this order
                        $u_stmt = $conn->prepare("SELECT user_id FROM orders WHERE order_id = ? LIMIT 1");
                        $u_stmt->bind_param("i", $order_id);
                        $u_stmt->execute();
                        $order_user = $u_stmt->get_result()->fetch_assoc();
                        $u_stmt->close();
                        if ($order_user) {
                            $n_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                            $n_stmt->bind_param("isss", $order_user['user_id'], $notif_data[0], $notif_data[1], $notif_data[2]);
                            $n_stmt->execute();
                            $n_stmt->close();
                        }
                    }

                    if ($is_ajax_chef) {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => true, 'new_status' => $new_status, 'order_id' => $order_id]);
                        exit;
                    }
                    $_SESSION["_toast"] = ["text" => "Order status updated successfully.", "type" => "success"];
                    session_write_close();
                    header("Location: chef-control.php#orders-section");
                    exit;
                } else {
                    $stmt->close();
                    if ($is_ajax_chef) {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => false, 'error' => 'Failed to update order status.']);
                        exit;
                    }
                    $errors['general'] = "Failed to update order status.";
                }
            } else {
                if ($is_ajax_chef) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Invalid status transition.']);
                    exit;
                }
                $errors['general'] = "Invalid status transition for chef.";
            }
        } else {
            if ($is_ajax_chef) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Order not found.']);
                exit;
            }
            $errors['general'] = "Order not found.";
        }
    } else {
        if (!empty($is_ajax_chef)) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid status update request.']);
            exit;
        }
        $errors['general'] = "Invalid status update request.";
    }
}

/* ---------------------------
   FETCH CATEGORY OPTIONS FOR MENU FORM
---------------------------- */
$category_options = $conn->query("
    SELECT category_id, name
    FROM categories
    WHERE is_available = 1
    ORDER BY name
");

/* ---------------------------
   FETCH ALL CATEGORIES
---------------------------- */
$category_list = $conn->query("
    SELECT category_id, name, image_url, description, is_available
    FROM categories
    ORDER BY category_id DESC
");

/* ---------------------------
   FETCH MENU ITEMS
---------------------------- */
$menu_items = $conn->query("
    SELECT mi.item_id, mi.name, mi.description, mi.price, mi.rating, mi.is_available,
           c.name AS category_name
    FROM menu_items mi
    INNER JOIN categories c ON mi.category_id = c.category_id
    ORDER BY mi.item_id DESC
");

/* ---------------------------
   FETCH CHEF ORDERS
---------------------------- */
$orders = $conn->query("
    SELECT
        o.order_id,
        o.total_amount,
        o.status,
        o.created_at,
        u.full_name
    FROM orders o
    INNER JOIN users u ON o.user_id = u.user_id
    WHERE o.status IN ('pending', 'preparing', 'ready')
    ORDER BY o.order_id DESC
");

/* ---------------------------
   FETCH DASHBOARD KOTs
   Main dashboard shows active kitchen work (pending/preparing).
   FIX: Also include archived KOTs whose order is 'ready' or 'out_for_delivery'
   so the chef can still see/print the KOT after marking an order Ready.
   The trigger trg_archive_kot_on_ready sets kot_status='archived' on ready,
   which previously caused KOTs to disappear the moment the chef marked them done.
   Full KOT history lives on chef-kitchen-tickets.php.
---------------------------- */
$kots = $conn->query("
    SELECT
        k.kot_id,
        k.order_id,
        k.kot_status,
        k.delivery_mode,
        k.special_notes,
        k.created_at AS kot_created_at,
        o.total_amount,
        o.status AS order_status,
        o.payment_method,
        o.created_at AS order_created_at,
        o.delivery_location_name,
        o.delivery_block_name,
        u.full_name AS customer_name
    FROM kitchen_order_tickets k
    INNER JOIN orders o ON k.order_id = o.order_id
    INNER JOIN users u ON o.user_id = u.user_id
    WHERE (
        (k.kot_status = 'active'   AND o.status IN ('pending', 'preparing'))
        OR
        (k.kot_status = 'archived' AND o.status IN ('ready', 'out_for_delivery'))
    )
    ORDER BY k.kot_id DESC
");

/* ---------------------------
   FETCH KOT ITEMS (all active KOT orders)
---------------------------- */
$kot_items_map = [];
if ($kots && $kots->num_rows > 0) {
    $kots->data_seek(0);
    $kot_order_ids = [];
    while ($row = $kots->fetch_assoc()) {
        $kot_order_ids[] = (int)$row['order_id'];
    }
    $kots->data_seek(0);

    if (!empty($kot_order_ids)) {
        $placeholders = implode(',', $kot_order_ids);
        $items_result = $conn->query("
            SELECT oi.order_id, mi.name AS item_name, oi.quantity, oi.price
            FROM order_items oi
            INNER JOIN menu_items mi ON oi.item_id = mi.item_id
            WHERE oi.order_id IN ($placeholders)
            ORDER BY oi.order_item_id
        ");
        while ($irow = $items_result->fetch_assoc()) {
            $kot_items_map[$irow['order_id']][] = $irow;
        }

    }
}

$kot_rows = [];
if ($kots && $kots->num_rows > 0) {
    $kots->data_seek(0);
    while ($kot_row = $kots->fetch_assoc()) {
        $kot_rows[] = $kot_row;
    }
}

$chef_summary = [
    'pending' => 0,
    'preparing' => 0,
    'ready' => 0,
    'today' => 0,
];
$today_date = date('Y-m-d');
foreach ($kot_rows as $kot_row) {
    $status_key = $kot_row['order_status'] ?? '';
    if (isset($chef_summary[$status_key])) {
        $chef_summary[$status_key]++;
    }
    if (!empty($kot_row['order_created_at']) && date('Y-m-d', strtotime($kot_row['order_created_at'])) === $today_date) {
        $chef_summary['today']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chef Control Panel</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ── Themed Modal Popup ── */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.65);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .modal-box {
            background: #2a2a2a;
            border: 1px solid rgba(77,184,72,0.2);
            border-radius: 14px;
            padding: 32px 28px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 24px 60px rgba(0,0,0,0.5);
            animation: modalIn .18s ease;
        }
        @keyframes modalIn {
            from { opacity:0; transform:scale(.93) translateY(8px); }
            to   { opacity:1; transform:scale(1)  translateY(0); }
        }
        .modal-icon { width:56px; height:56px; border-radius:50%; margin:0 auto 14px; display:flex; align-items:center; justify-content:center; }
        .modal-icon-danger { background: rgba(229,57,53,0.12); }
        .modal-title { font-size:18px; font-weight:700; color:#fff; margin-bottom:8px; }
        .modal-body  { font-size:14px; color:rgba(255,255,255,0.6); line-height:1.6; margin-bottom:24px; }
        .modal-actions { display:flex; gap:12px; justify-content:center; }
        .modal-btn { padding:10px 24px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; border:none; transition:all .15s; }
        .modal-btn-cancel { background:rgba(255,255,255,0.08); color:rgba(255,255,255,0.7); }
        .modal-btn-cancel:hover { background:rgba(255,255,255,0.14); }
        .modal-btn-danger { background:#e53935; color:#fff; }
        .modal-btn-danger:hover { background:#c62828; }

        /* ── KOT Grid ── */
        .kot-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 8px;
        }
        .kot-ticket {
            background: #1e1e1e;
            border: 1px solid rgba(77,184,72,0.2);
            border-radius: 12px;
            overflow: hidden;
        }
        .kot-header {
            background: rgba(77,184,72,0.08);
            border-bottom: 1px solid rgba(77,184,72,0.15);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .kot-id { font-size:16px; font-weight:800; color:#4db848; }
        .kot-order-id { font-size:12px; color:rgba(255,255,255,0.5); }
        .kot-body { padding: 14px 16px; }
        .kot-row { display:flex; justify-content:space-between; margin-bottom:8px; font-size:13px; }
        .kot-label { color:rgba(255,255,255,0.5); }
        .kot-value { color:#fff; font-weight:500; text-align:right; max-width:60%; }
        .kot-items-title { font-size:12px; color:#4db848; text-transform:uppercase; letter-spacing:.5px; font-weight:700; margin:14px 0 8px; }
        .kot-items-table { width:100%; border-collapse:collapse; font-size:13px; }
        .kot-items-table th { color:rgba(255,255,255,0.4); font-size:11px; text-transform:uppercase; letter-spacing:.3px; text-align:left; padding:4px 8px; border-bottom:1px solid rgba(255,255,255,0.06); }
        .kot-items-table td { padding:6px 8px; color:rgba(255,255,255,0.85); border-bottom:1px solid rgba(255,255,255,0.04); }
        .kot-total-row td { color:#4db848; border-top:1px solid rgba(77,184,72,0.2); border-bottom:none; }
        .kot-footer { padding:12px 16px; border-top:1px solid rgba(255,255,255,0.05); display:flex; gap:8px; }
        .chef-btn-sm { padding:6px 14px; font-size:12px; }
        .chef-btn-danger { background:rgba(229,57,53,0.15); color:#ef9a9a; border:1px solid rgba(229,57,53,0.3); }
        .chef-btn-danger:hover { background:rgba(229,57,53,0.25); }
    </style>
</head>
<body class="chef-page ops-page">

<div class="layout">

    <div class="sidebar">
        <div class="navbar-title">
            Herald Canteen
            <span>Chef Portal</span>
        </div>
        <nav>
            <a href="chef-control.php" class="active">👨‍🍳 Chef Dashboard</a>
            <a href="chef-kitchen-tickets.php">🎫 Kitchen Tickets</a>
            <a href="chef-categories.php">🖼️ Categories</a>
            <a href="chef-menu.php">🍽️ Manage Menu</a>
            <a href="manage-delivery-locations.php">📍 Delivery Locations</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>

    <div class="main">

        <div class="topbar">
            <div class="topbar-welcome">
                Welcome, <?php echo htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8'); ?>
            </div>

            <!-- Theme Toggle -->
            <label class="theme-toggle" title="Toggle light/dark mode">
                <input type="checkbox" class="theme-checkbox">
                <span class="theme-slider"></span>
            </label>
        </div>

        <div class="content">

            <section class="ops-hero ops-hero-chef">
                <div>
                    <p class="ops-eyebrow">Kitchen Display System</p>
                    <h1>Chef Control Centre</h1>
                    <p>Manage kitchen tickets, prepare orders, and print KOTs in real time.</p>
                </div>
                <div class="ops-hero-side">
                    <span class="ops-role-pill">👨‍🍳 <?php echo htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="ops-live-pill"><span></span> Live Sync Active</span>
                    <small id="chefLastUpdated">Last updated: just now</small>
                </div>
            </section>

            <?php if (isset($errors['general'])): ?>
                <div class="alert-error"><?php echo htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div id="chefLiveRegion" data-live-region="chef-control">
            <section class="ops-stat-grid" aria-label="Kitchen order summary">
                <article class="ops-stat-card ops-stat-pending">
                    <div class="ops-stat-icon">🆕</div>
                    <div><span>New KOTs</span><strong data-chef-stat="pending"><?php echo (int)$chef_summary['pending']; ?></strong></div>
                </article>
                <article class="ops-stat-card ops-stat-blue">
                    <div class="ops-stat-icon">🔥</div>
                    <div><span>Preparing</span><strong data-chef-stat="preparing"><?php echo (int)$chef_summary['preparing']; ?></strong></div>
                </article>
                <article class="ops-stat-card ops-stat-green">
                    <div class="ops-stat-icon">⚡</div>
                    <div><span>Active Queue</span><strong><?php echo (int)($chef_summary['pending'] + $chef_summary['preparing']); ?></strong></div>
                </article>
                <article class="ops-stat-card ops-stat-total">
                    <div class="ops-stat-icon">📅</div>
                    <div><span>Total Today</span><strong><?php echo (int)$chef_summary['today']; ?></strong></div>
                </article>
            </section>

            <section class="ops-panel" id="kot-section">
                <div class="ops-panel-heading">
                    <div>
                        <p class="ops-eyebrow">Kitchen Queue</p>
                        <h2>Kitchen Order Tickets</h2>
                        <p>Use these cards to move orders from pending to ready. Active (pending/preparing) and recently-ready KOTs are shown here so you can still print them. Fully completed tickets move to the Kitchen Tickets page.</p>
                    </div>
                    <a href="chef-menu.php" class="ops-link-btn">Manage Menu</a>
                </div>

                <div class="ops-filter-tabs" data-filter-group="chef">
                    <button type="button" class="ops-filter-btn active" data-filter="all">All</button>
                    <button type="button" class="ops-filter-btn" data-filter="pending">Pending</button>
                    <button type="button" class="ops-filter-btn" data-filter="preparing">Preparing</button>
                    <button type="button" class="ops-filter-btn" data-filter="ready">Ready</button>
                </div>

                <?php if (!empty($kot_rows)): ?>
                    <div class="ops-card-grid" id="chefKotGrid">
                        <?php foreach ($kot_rows as $kot): ?>
                            <?php
                                $items = $kot_items_map[$kot['order_id']] ?? [];
                                $status = $kot['order_status'];
                                $age_class = ops_urgency_class($kot['order_created_at']);
                            ?>
                            <article class="ops-order-card chef-kot-card"
                                     id="kot-<?php echo (int)$kot['kot_id']; ?>"
                                     data-order-id="<?php echo (int)$kot['order_id']; ?>"
                                     data-kot-id="<?php echo (int)$kot['kot_id']; ?>"
                                     data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"
                                     data-filter-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="ops-card-top">
                                    <div>
                                        <h3>KOT #<?php echo (int)$kot['kot_id']; ?></h3>
                                        <p>Order #<?php echo (int)$kot['order_id']; ?></p>
                                    </div>
                                    <span class="ops-status-badge status-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ops_status_label($status), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>

                                <div class="ops-meta-grid">
                                    <div><span>Customer</span><strong><?php echo htmlspecialchars($kot['customer_name'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div><span>Mode</span><strong><?php echo htmlspecialchars(ops_delivery_label($kot['delivery_mode']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div><span>Ordered</span><strong><?php echo htmlspecialchars(date('d M, g:i A', strtotime($kot['order_created_at'])), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                    <div><span>Age</span><strong class="ops-urgency ops-urgency-<?php echo $age_class; ?>"><?php echo htmlspecialchars(ops_age_label($kot['order_created_at']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                                </div>

                                <?php if (!empty($kot['special_notes'])): ?>
                                    <div class="ops-remark"><span>Customer Remark</span><?php echo htmlspecialchars($kot['special_notes'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>

                                <?php
                                $loc_name  = $kot['delivery_location_name'] ?? null;
                                $bloc_name = $kot['delivery_block_name'] ?? null;
                                $dm_chef   = $kot['delivery_mode'] ?? 'delivery';
                                if ($dm_chef === 'delivery'): ?>
                                <div class="ops-remark delivery-location-card" style="background:rgba(77,184,72,0.07);border-color:rgba(77,184,72,0.25);">
                                    <span>📍 Deliver To</span>
                                    <?php if ($loc_name): ?>
                                        <strong><?php echo htmlspecialchars($loc_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <?php if ($bloc_name): ?><em style="font-size:11px;color:rgba(255,255,255,0.45);display:block;margin-top:2px;"><?php echo htmlspecialchars($bloc_name, ENT_QUOTES, 'UTF-8'); ?></em><?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:rgba(255,255,255,0.4);font-style:italic;">Not specified</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <div class="ops-items-block">
                                    <div class="ops-items-title">Order Items</div>
                                    <?php if (!empty($items)): ?>
                                        <ul class="ops-item-list">
                                            <?php foreach ($items as $it): ?>
                                                <li>
                                                    <span><?php echo (int)$it['quantity']; ?> × <?php echo htmlspecialchars($it['item_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <strong>Rs. <?php echo number_format((float)$it['price'], 2); ?></strong>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="ops-muted">No items found.</p>
                                    <?php endif; ?>
                                </div>

                                <div class="ops-card-total">
                                    <span>Total</span>
                                    <strong>Rs. <?php echo number_format((float)$kot['total_amount'], 2); ?></strong>
                                </div>

                                <div class="ops-action-row" id="chef-action-<?php echo (int)$kot['order_id']; ?>">
                                    <?php if ($status === 'pending'): ?>
                                        <button type="button" class="ops-btn ops-btn-primary" onclick="updateOrderStatus(<?php echo (int)$kot['order_id']; ?>, 'preparing', this)">🔥 Start Preparing</button>
                                    <?php elseif ($status === 'preparing'): ?>
                                        <button type="button" class="ops-btn ops-btn-primary" onclick="updateOrderStatus(<?php echo (int)$kot['order_id']; ?>, 'ready', this)">✅ Mark Ready</button>
                                    <?php else: ?>
                                        <span class="ops-complete-note">Ready for staff pickup</span>
                                    <?php endif; ?>
                                    <a href="chef_kot_print.php?kot_id=<?php echo (int)$kot['kot_id']; ?>" target="_blank" class="ops-btn ops-btn-ghost">🖨️ Print KOT</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="ops-empty-state" id="chefFilterEmpty" hidden>
                        <div>🔎</div>
                        <h3>No tickets match this filter</h3>
                        <p>Choose another status tab or wait for live order updates.</p>
                    </div>
                <?php else: ?>
                    <div class="ops-empty-state">
                        <div>🍳</div>
                        <h3>No pending kitchen tickets</h3>
                        <p>New customer orders will appear here automatically.</p>
                    </div>
                <?php endif; ?>
            </section>
            </div>

            <section class="ops-panel ops-dashboard-links">
                <div class="ops-panel-heading">
                    <div>
                        <p class="ops-eyebrow">Chef Navigation</p>
                        <h2>Management Pages</h2>
                        <p>Menu and category management now live on dedicated pages so this dashboard stays focused on new kitchen work only.</p>
                    </div>
                </div>
                <div class="ops-quick-link-grid">
                    <a href="chef-kitchen-tickets.php" class="ops-quick-link"><span>🎫</span><strong>Kitchen Tickets</strong><small>View full ticket history and print KOTs.</small></a>
                    <a href="chef-categories.php" class="ops-quick-link"><span>🖼️</span><strong>Categories</strong><small>Add or edit category cards.</small></a>
                    <a href="chef-menu.php" class="ops-quick-link"><span>🍽️</span><strong>Manage Menu</strong><small>Add, update, or remove items.</small></a>
                </div>
            </section>

        </div><!-- /.content -->
    </div><!-- /.main -->
</div><!-- /.layout -->

<!-- ── Delete Confirm Modal ── -->
<div class="modal-overlay" id="deleteModal" style="display:none;">
    <div class="modal-box">
        <div class="modal-icon modal-icon-danger">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
                <circle cx="14" cy="14" r="14" fill="rgba(229,57,53,0.15)"/>
                <path d="M9 9L19 19M19 9L9 19" stroke="#e53935" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </div>
        <div class="modal-title">Delete Menu Item</div>
        <div class="modal-body" id="deleteModalBody">Are you sure you want to delete this item? This action cannot be undone.</div>
        <div class="modal-actions">
            <button class="modal-btn modal-btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <form method="POST" id="deleteForm" style="display:inline;">
                <input type="hidden" name="item_id" id="deleteItemId">
                <button type="submit" name="delete_item" class="modal-btn modal-btn-danger">Yes, Delete</button>
            </form>
        </div>
    </div>
</div>

<!-- ── Chef Toast Notification ── -->
<?php if ($chef_toast): ?>
<div class="toast chef-toast <?php echo $chef_toast['type'] === 'danger' ? 'toast-danger' : ''; ?>" id="chefToast">
    <div class="toast-icon">
        <?php if ($chef_toast['type'] === 'danger'): ?>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
            <circle cx="9" cy="9" r="9" fill="#e53935"/>
            <path d="M6 6L12 12M12 6L6 12" stroke="white" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <?php else: ?>
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
            <circle cx="9" cy="9" r="9" fill="#4db848"/>
            <path d="M5 9.5L7.5 12L13 6.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <?php endif; ?>
    </div>
    <div class="toast-body">
        <span class="toast-title"><?php echo htmlspecialchars($chef_toast['text'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="toast-sub"><?php echo $chef_toast['type'] === 'danger' ? 'Action completed.' : 'Changes saved successfully ✓'; ?></span>
    </div>
    <button class="toast-close" onclick="this.closest('.toast').classList.add('toast-hide')">✕</button>
</div>
<script>
    const chefToast = document.getElementById('chefToast');
    if (chefToast) {
        setTimeout(() => { chefToast.classList.add('toast-hide'); }, 3500);
    }
</script>
<?php endif; ?>

<script>
// ── Delete Confirm Modal ────────────────────────────────────────
function openDeleteConfirm(itemId, itemName) {
    document.getElementById('deleteItemId').value = itemId;
    document.getElementById('deleteModalBody').textContent =
        'Are you sure you want to delete "' + itemName + '"? This action cannot be undone.';
    document.getElementById('deleteModal').style.display = 'flex';
}
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});

// ── Delete via AJAX (no page reload) ───────────────────────────
document.getElementById('deleteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var itemId   = document.getElementById('deleteItemId').value;
    var confirmBtn = this.querySelector('button[type="submit"]');
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Deleting...';

    fetch('chef-control.php', {
        method:  'POST',
        headers: {
            'Content-Type':     'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'delete_item=1&item_id=' + encodeURIComponent(itemId)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        closeDeleteModal();
        if (data.ok) {
            // Remove the row from the table without reload
            var rows = document.querySelectorAll('#menu-section tr');
            rows.forEach(function(row) {
                var deleteBtn = row.querySelector('button[onclick*="openDeleteConfirm(' + data.item_id + ',"]');
                if (!deleteBtn) {
                    // Also check via data attribute approach
                    deleteBtn = row.querySelector('button[onclick*="openDeleteConfirm(' + itemId + ',"]');
                }
                if (deleteBtn) {
                    row.style.transition = 'opacity 0.25s';
                    row.style.opacity = '0';
                    setTimeout(function() { row.remove(); reindexTable(); }, 260);
                }
            });
            showChefToast('Menu item deleted successfully.', 'danger');
        } else {
            alert(data.error || 'Failed to delete item.');
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Yes, Delete';
        }
    })
    .catch(function() {
        closeDeleteModal();
        alert('Network error. Please try again.');
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Yes, Delete';
    });
});

function reindexTable() {
    var rows = document.querySelectorAll('#menu-section tr:not(:first-child)');
    rows.forEach(function(row, i) {
        var td = row.querySelector('td:first-child');
        if (td) td.textContent = i + 1;
    });
}

// ── Update Order Status via AJAX (no page reload) ──────────────
function chefStatusLabel(status) {
    var labels = {
        pending: 'Pending',
        preparing: 'Preparing',
        ready: 'Ready',
        out_for_delivery: 'On Delivery',
        delivered: 'Delivered',
        cancelled: 'Cancelled'
    };
    return labels[status] || status.replace(/_/g, ' ');
}

function adjustChefStat(status, delta) {
    var el = document.querySelector('[data-chef-stat="' + status + '"]');
    if (!el) return;
    var current = parseInt(el.textContent, 10) || 0;
    el.textContent = Math.max(0, current + delta);
}

function applyOpsFilter(group) {
    var wrap = document.querySelector('[data-filter-group="' + group + '"]');
    if (!wrap) return;
    var active = wrap.querySelector('.ops-filter-btn.active');
    var filter = active ? active.getAttribute('data-filter') : 'all';
    var selector = group === 'chef' ? '.chef-kot-card' : '.staff-order-card';
    var cards = document.querySelectorAll(selector);
    var visible = 0;
    cards.forEach(function(card) {
        var status = card.getAttribute('data-filter-status') || card.getAttribute('data-status') || '';
        var payment = card.getAttribute('data-payment-status') || '';
        var show = filter === 'all' || status === filter || (filter === 'cod_pending' && payment !== 'successful' && card.getAttribute('data-payment-method') === 'cod');
        card.hidden = !show;
        if (show) visible++;
    });
    var empty = document.getElementById(group === 'chef' ? 'chefFilterEmpty' : 'staffFilterEmpty');
    if (empty) empty.hidden = visible !== 0;
}

document.addEventListener('click', function(event) {
    var btn = event.target.closest('.ops-filter-tabs .ops-filter-btn');
    if (!btn) return;
    var groupEl = btn.closest('.ops-filter-tabs');
    if (!groupEl) return;
    groupEl.querySelectorAll('.ops-filter-btn').forEach(function(item) { item.classList.remove('active'); });
    btn.classList.add('active');
    applyOpsFilter(groupEl.getAttribute('data-filter-group'));
});

function updateChefLastUpdated() {
    var el = document.getElementById('chefLastUpdated');
    if (el) el.textContent = 'Last updated: just now';
}

function updateOrderStatus(orderId, newStatus, btn) {
    window.__hcChefActionBusy = true;
    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = 'Processing...';

    fetch('chef-control.php', {
        method:  'POST',
        headers: {
            'Content-Type':     'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'update_order_status=1&order_id=' + encodeURIComponent(orderId)
              + '&new_status=' + encodeURIComponent(newStatus)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) {
            window.__hcChefActionBusy = false;
            btn.disabled = false;
            btn.textContent = origText;
            alert(data.error || 'Failed to update order status.');
            return;
        }

        var card = btn.closest('.ops-order-card');
        var previousStatus = card ? (card.getAttribute('data-status') || '') : '';
        if (card) {
            card.setAttribute('data-status', data.new_status);
            card.setAttribute('data-filter-status', data.new_status);
            var badge = card.querySelector('.ops-status-badge, .status-badge');
            if (badge) {
                badge.className = 'ops-status-badge status-' + data.new_status;
                badge.textContent = chefStatusLabel(data.new_status);
            }
            var actions = document.getElementById('chef-action-' + orderId) || btn.closest('.ops-action-row');
            var printLink = actions ? actions.querySelector('a[href*="chef_kot_print.php"]') : null;
            if (actions) {
                if (data.new_status === 'preparing') {
                    actions.innerHTML = '<button type="button" class="ops-btn ops-btn-primary" onclick="updateOrderStatus(' + orderId + ', \'ready\', this)">✅ Mark Ready</button>';
                } else if (data.new_status === 'ready') {
                    actions.innerHTML = '<span class="ops-complete-note">Moved to Kitchen Ticket history</span>';
                    setTimeout(function() {
                        if (card) {
                            card.style.opacity = '0';
                            card.style.transform = 'translateY(-8px)';
                            setTimeout(function() { card.remove(); applyOpsFilter('chef'); }, 260);
                        }
                    }, 700);
                }
                if (data.new_status !== 'ready' && printLink) actions.appendChild(printLink);
            }
        }

        if (previousStatus && previousStatus !== data.new_status) {
            adjustChefStat(previousStatus, -1);
            adjustChefStat(data.new_status, 1);
        }
        applyOpsFilter('chef');
        updateChefLastUpdated();
        showChefToast('Order status updated successfully.', 'success');
        window.__hcChefActionBusy = false;
    })
    .catch(function() {
        window.__hcChefActionBusy = false;
        btn.disabled = false;
        btn.textContent = origText;
        alert('Network error. Please try again.');
    });
}

// ── Chef toast for AJAX actions ────────────────────────────────
function showChefToast(text, type) {
    var existing = document.getElementById('chefToast');
    if (existing) existing.remove();

    var isDanger = type === 'danger';
    var svgIcon = isDanger
        ? '<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="9" fill="#e53935"/><path d="M6 6L12 12M12 6L6 12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 18 18" fill="none"><circle cx="9" cy="9" r="9" fill="#4db848"/><path d="M5 9.5L7.5 12L13 6.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    var toast = document.createElement('div');
    toast.id = 'chefToast';
    toast.className = 'toast chef-toast' + (isDanger ? ' toast-danger' : '');
    toast.innerHTML = '<div class="toast-icon">' + svgIcon + '</div>'
        + '<div class="toast-body"><span class="toast-title">' + text + '</span>'
        + '<span class="toast-sub">' + (isDanger ? 'Action completed.' : 'Changes saved successfully \u2713') + '</span></div>'
        + '<button class="toast-close" onclick="this.closest(\'.toast\').classList.add(\'toast-hide\')">&#x2715;</button>';
    document.body.appendChild(toast);
    setTimeout(function() { toast.classList.add('toast-hide'); }, 3500);
}

(function() {
    var regionId = 'chefLiveRegion';
    var intervalMs = 4500;
    var inFlight = false;

    function rememberActiveFilter() {
        var active = document.querySelector('[data-filter-group="chef"] .ops-filter-btn.active');
        return active ? active.getAttribute('data-filter') : 'all';
    }

    function restoreActiveFilter(filter) {
        var buttons = document.querySelectorAll('[data-filter-group="chef"] .ops-filter-btn');
        if (!buttons.length) return;
        buttons.forEach(function(btn) { btn.classList.remove('active'); });
        var target = document.querySelector('[data-filter-group="chef"] .ops-filter-btn[data-filter="' + filter + '"]') ||
                     document.querySelector('[data-filter-group="chef"] .ops-filter-btn[data-filter="all"]');
        if (target) target.classList.add('active');
        applyOpsFilter('chef');
    }

    function refreshChefControl() {
        if (inFlight || document.hidden || window.__hcChefActionBusy) return;
        var currentRegion = document.getElementById(regionId);
        if (!currentRegion) return;
        var activeFilter = rememberActiveFilter();
        inFlight = true;
        fetch('chef-control.php', {
            method: 'GET',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.text(); })
        .then(function(html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var freshRegion = doc.getElementById(regionId);
            if (freshRegion) {
                currentRegion.innerHTML = freshRegion.innerHTML;
                restoreActiveFilter(activeFilter);
                updateChefLastUpdated();
            }
        })
        .catch(function() {
            var el = document.getElementById('chefLastUpdated');
            if (el) el.textContent = 'Live sync paused — retrying...';
        })
        .finally(function() { inFlight = false; });
    }

    setInterval(refreshChefControl, intervalMs);
    document.addEventListener('visibilitychange', function() { if (!document.hidden) refreshChefControl(); });
})();

// KOT printing is now handled server-side via chef_kot_print.php
// The "Print / Download KOT" link opens chef_kot_print.php?kot_id=N in a new tab.
</script>

</body>
</html>