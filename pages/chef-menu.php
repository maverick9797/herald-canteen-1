<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";

require_role('chef');

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function clean_menu_text($input, string $field, int $max, array &$errors, bool $required = true): string|false
{
    $value = trim((string)$input);
    if ($required && $value === '') { $errors[$field] = ucfirst($field) . ' is required.'; return false; }
    if ($value !== '' && strlen($value) > $max) { $errors[$field] = ucfirst($field) . " cannot exceed {$max} characters."; return false; }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function validate_menu_price($price, array &$errors): float|false
{
    $value = filter_var(trim((string)$price), FILTER_VALIDATE_FLOAT);
    if ($value === false || $value <= 0) { $errors['price'] = 'Price must be greater than 0.'; return false; }
    if ($value > 999999.99) { $errors['price'] = 'Price is too high.'; return false; }
    return round((float)$value, 2);
}
function validate_menu_rating($rating, array &$errors): float|false
{
    $value = filter_var(trim((string)$rating), FILTER_VALIDATE_FLOAT);
    if ($value === false) { $errors['rating'] = 'Rating must be valid.'; return false; }
    if ($value < 0 || $value > 5) { $errors['rating'] = 'Rating must be between 0 and 5.'; return false; }
    return round((float)$value, 1);
}

$errors = [];
$edit_item = null;
$toast = $_SESSION['_toast'] ?? null;
unset($_SESSION['_toast']);

if (isset($_GET['edit_id'])) {
    $id = filter_var($_GET['edit_id'], FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $stmt = $conn->prepare('SELECT * FROM menu_items WHERE item_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $edit_item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_item']) || isset($_POST['update_item']))) {
    $is_update = isset($_POST['update_item']);
    $item_id = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT);
    $category_id = filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT);
    $name = clean_menu_text($_POST['name'] ?? '', 'item name', 150, $errors);
    $description = clean_menu_text($_POST['description'] ?? '', 'description', 500, $errors, false);
    if ($description === false) $description = '';
    $price = validate_menu_price($_POST['price'] ?? '', $errors);
    $rating = validate_menu_rating($_POST['rating'] ?? '0', $errors);
    $available = isset($_POST['is_available']) ? 1 : 0;
    if (!$category_id || $category_id <= 0) $errors['category_id'] = 'Please select a valid category.';
    if ($is_update && (!$item_id || $item_id <= 0)) $errors['general'] = 'Invalid menu item.';

    if (empty($errors)) {
        if ($is_update) {
            $stmt = $conn->prepare('UPDATE menu_items SET category_id = ?, name = ?, description = ?, price = ?, rating = ?, is_available = ? WHERE item_id = ?');
            $stmt->bind_param('issddii', $category_id, $name, $description, $price, $rating, $available, $item_id);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['_toast'] = $ok ? ['type'=>'success','text'=>'Menu item updated successfully.'] : ['type'=>'danger','text'=>'Failed to update menu item.'];
        } else {
            $stmt = $conn->prepare('INSERT INTO menu_items (category_id, name, description, price, rating, is_available) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issddi', $category_id, $name, $description, $price, $rating, $available);
            $ok = $stmt->execute();
            $stmt->close();
            $_SESSION['_toast'] = $ok ? ['type'=>'success','text'=>'Menu item added successfully.'] : ['type'=>'danger','text'=>'Failed to add menu item.'];
        }
        session_write_close();
        header('Location: chef-menu.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id = filter_var($_POST['item_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($item_id && $item_id > 0) {
        $stmt = $conn->prepare('DELETE FROM menu_items WHERE item_id = ?');
        $stmt->bind_param('i', $item_id);
        $ok = $stmt->execute();
        $stmt->close();
        $_SESSION['_toast'] = $ok ? ['type'=>'danger','text'=>'Menu item deleted successfully.'] : ['type'=>'danger','text'=>'Failed to delete menu item.'];
    }
    session_write_close();
    header('Location: chef-menu.php');
    exit;
}

$category_options = $conn->query('SELECT category_id, name FROM categories WHERE is_available = 1 ORDER BY name');
$menu_items = $conn->query('SELECT mi.item_id, mi.name, mi.description, mi.price, mi.rating, mi.is_available, c.name AS category_name FROM menu_items mi INNER JOIN categories c ON mi.category_id = c.category_id ORDER BY mi.item_id DESC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Menu — Chef</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="chef-page ops-page">
<div class="layout">
    <div class="sidebar">
        <div class="navbar-title">Herald Canteen<span>Chef Portal</span></div>
        <nav>
            <a href="chef-control.php">👨‍🍳 Chef Dashboard</a>
            <a href="chef-kitchen-tickets.php">🎫 Kitchen Tickets</a>
            <a href="chef-categories.php">🖼️ Categories</a>
            <a href="chef-menu.php" class="active">🍽️ Manage Menu</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main">
        <div class="topbar"><div class="topbar-welcome">Welcome, <?php echo h($_SESSION['full_name'] ?? 'Chef'); ?></div><label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label></div>
        <div class="content">
            <section class="ops-hero ops-hero-chef"><div><p class="ops-eyebrow">Menu Management</p><h1>Manage Menu</h1><p>Add, update, and remove food items from a dedicated menu management page.</p></div><div class="ops-hero-side"><span class="ops-role-pill">👨‍🍳 <?php echo h($_SESSION['full_name'] ?? 'Chef'); ?></span><a href="chef-control.php" class="ops-link-btn">Back to Dashboard</a></div></section>
            <?php if ($toast): ?><div class="<?php echo ($toast['type'] ?? '') === 'danger' ? 'alert-error' : 'alert-success'; ?>"><?php echo h($toast['text'] ?? 'Action completed.'); ?></div><?php endif; ?>
            <?php if (!empty($errors['general'])): ?><div class="alert-error"><?php echo h($errors['general']); ?></div><?php endif; ?>
            <section class="ops-panel">
                <div class="ops-panel-heading"><div><p class="ops-eyebrow"><?php echo $edit_item ? 'Update Item' : 'New Item'; ?></p><h2><?php echo $edit_item ? 'Edit Menu Item' : 'Add Menu Item'; ?></h2><p>Individual menu items use text details only. Images remain category-level.</p></div></div>
                <form method="POST" class="chef-form-grid">
                    <?php if ($edit_item): ?><input type="hidden" name="item_id" value="<?php echo (int)$edit_item['item_id']; ?>"><?php endif; ?>
                    <div><label>Category *</label><select name="category_id" required><option value="">Select Category</option><?php if ($category_options): while ($cat=$category_options->fetch_assoc()): $selected = $edit_item && (int)$edit_item['category_id'] === (int)$cat['category_id']; ?><option value="<?php echo (int)$cat['category_id']; ?>" <?php echo $selected ? 'selected' : ''; ?>><?php echo h($cat['name']); ?></option><?php endwhile; endif; ?></select><?php if (isset($errors['category_id'])): ?><span class="error-message"><?php echo h($errors['category_id']); ?></span><?php endif; ?></div>
                    <div><label>Item Name *</label><input type="text" name="name" required value="<?php echo h($edit_item['name'] ?? ''); ?>"><?php if (isset($errors['item name'])): ?><span class="error-message"><?php echo h($errors['item name']); ?></span><?php endif; ?></div>
                    <div class="full"><label>Description</label><textarea name="description"><?php echo h($edit_item['description'] ?? ''); ?></textarea><?php if (isset($errors['description'])): ?><span class="error-message"><?php echo h($errors['description']); ?></span><?php endif; ?></div>
                    <div><label>Price *</label><input type="number" name="price" step="0.01" min="0.01" required value="<?php echo h($edit_item['price'] ?? ''); ?>"><?php if (isset($errors['price'])): ?><span class="error-message"><?php echo h($errors['price']); ?></span><?php endif; ?></div>
                    <div><label>Rating</label><input type="number" name="rating" step="0.1" min="0" max="5" value="<?php echo h($edit_item['rating'] ?? '0'); ?>"><?php if (isset($errors['rating'])): ?><span class="error-message"><?php echo h($errors['rating']); ?></span><?php endif; ?></div>
                    <div class="full"><label><input type="checkbox" name="is_available" value="1" <?php echo $edit_item ? (((int)$edit_item['is_available'] === 1) ? 'checked' : '') : 'checked'; ?>> Available</label></div>
                    <div class="full"><button type="submit" name="<?php echo $edit_item ? 'update_item' : 'add_item'; ?>" class="ops-btn ops-btn-primary"><?php echo $edit_item ? 'Update Item' : 'Add Item'; ?></button><?php if ($edit_item): ?> <a href="chef-menu.php" class="ops-btn ops-btn-ghost">Cancel</a><?php endif; ?></div>
                </form>
            </section>
            <section class="ops-panel"><div class="ops-panel-heading"><div><p class="ops-eyebrow">Menu List</p><h2>Existing Menu Items</h2><p>Edit availability, price, rating, and descriptions.</p></div></div>
                <div class="ops-table-wrap"><table class="chef-table"><tr><th>ID</th><th>Category</th><th>Name</th><th>Description</th><th>Price</th><th>Rating</th><th>Available</th><th>Actions</th></tr><?php if ($menu_items && $menu_items->num_rows > 0): $i=1; while ($item=$menu_items->fetch_assoc()): ?><tr><td><?php echo $i++; ?></td><td><?php echo h($item['category_name']); ?></td><td><?php echo h($item['name']); ?></td><td><?php echo h($item['description']); ?></td><td>Rs. <?php echo number_format((float)$item['price'], 2); ?></td><td><?php echo number_format((float)$item['rating'], 1); ?></td><td><?php echo ((int)$item['is_available'] === 1) ? 'Yes' : 'No'; ?></td><td><div class="action-links"><a class="ops-btn ops-btn-ghost" href="chef-menu.php?edit_id=<?php echo (int)$item['item_id']; ?>">Edit</a><form method="POST" style="display:inline" onsubmit="return confirm('Delete this menu item?');"><input type="hidden" name="item_id" value="<?php echo (int)$item['item_id']; ?>"><button type="submit" name="delete_item" class="ops-btn ops-btn-warning">Delete</button></form></div></td></tr><?php endwhile; else: ?><tr><td colspan="8">No menu items found.</td></tr><?php endif; ?></table></div>
            </section>
        </div>
    </div>
</div>
</body>
</html>
