<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";

require_role('chef');

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function validate_category_text($input, string $field, int $max, array &$errors, bool $required = true): string|false
{
    $value = trim((string)$input);
    if ($required && $value === '') { $errors[$field] = ucfirst($field) . ' is required.'; return false; }
    if ($value !== '' && strlen($value) > $max) { $errors[$field] = ucfirst($field) . " cannot exceed {$max} characters."; return false; }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function upload_category_image_page(array $file, array &$errors): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) { $errors['image'] = 'Image upload failed.'; return null; }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) { $errors['image'] = 'Only JPG, JPEG, PNG, and WEBP images are allowed.'; return null; }
    if ($file['size'] > 5 * 1024 * 1024) { $errors['image'] = 'Image size cannot exceed 5MB.'; return null; }
    if (@getimagesize($file['tmp_name']) === false) { $errors['image'] = 'Uploaded file is not a valid image.'; return null; }
    $dir = dirname(__DIR__) . '/assets/images/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $name = 'category_' . time() . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) { $errors['image'] = 'Failed to save uploaded image.'; return null; }
    return '../assets/images/' . $name;
}
function delete_category_image_page(?string $image): void
{
    if (!$image) return;
    $path = dirname(__DIR__) . '/assets/images/' . basename($image);
    if (is_file($path)) unlink($path);
}

$errors = [];
$form_data = [];
$edit_category = null;
$toast = $_SESSION['_toast'] ?? null;
unset($_SESSION['_toast']);

if (isset($_GET['edit_category_id'])) {
    $id = filter_var($_GET['edit_category_id'], FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $stmt = $conn->prepare('SELECT * FROM categories WHERE category_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $edit_category = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = validate_category_text($_POST['category_name'] ?? '', 'category name', 100, $errors);
    $description = validate_category_text($_POST['category_description'] ?? '', 'description', 500, $errors, false);
    if ($description === false) $description = '';
    $available = isset($_POST['category_is_available']) ? 1 : 0;
    $image = upload_category_image_page($_FILES['category_image'] ?? [], $errors);
    if (empty($errors)) {
        $stmt = $conn->prepare('INSERT INTO categories (name, image_url, description, is_available) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('sssi', $name, $image, $description, $available);
        if ($stmt->execute()) $_SESSION['_toast'] = ['type'=>'success', 'text'=>'Category added successfully.']; else $_SESSION['_toast'] = ['type'=>'danger', 'text'=>'Failed to add category. Category may already exist.'];
        $stmt->close();
        session_write_close();
        header('Location: chef-categories.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_category'])) {
    $id = filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT);
    $name = validate_category_text($_POST['category_name'] ?? '', 'category name', 100, $errors);
    $description = validate_category_text($_POST['category_description'] ?? '', 'description', 500, $errors, false);
    if ($description === false) $description = '';
    $available = isset($_POST['category_is_available']) ? 1 : 0;
    if (!$id || $id <= 0) $errors['general'] = 'Invalid category.';
    $new_image = upload_category_image_page($_FILES['category_image'] ?? [], $errors);
    $current_image = $_POST['current_image'] ?? null;
    $final_image = $new_image ?: $current_image;
    if ($new_image && $current_image) delete_category_image_page($current_image);
    if (empty($errors)) {
        $stmt = $conn->prepare('UPDATE categories SET name = ?, image_url = ?, description = ?, is_available = ? WHERE category_id = ?');
        $stmt->bind_param('sssii', $name, $final_image, $description, $available, $id);
        if ($stmt->execute()) $_SESSION['_toast'] = ['type'=>'success', 'text'=>'Category updated successfully.']; else $_SESSION['_toast'] = ['type'=>'danger', 'text'=>'Failed to update category.'];
        $stmt->close();
        session_write_close();
        header('Location: chef-categories.php');
        exit;
    }
}

$categories = $conn->query('SELECT category_id, name, image_url, description, is_available, updated_at FROM categories ORDER BY category_id DESC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories — Chef</title>
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
            <a href="chef-categories.php" class="active">🖼️ Categories</a>
            <a href="chef-menu.php">🍽️ Manage Menu</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main">
        <div class="topbar"><div class="topbar-welcome">Welcome, <?php echo h($_SESSION['full_name'] ?? 'Chef'); ?></div><label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label></div>
        <div class="content">
            <section class="ops-hero ops-hero-chef"><div><p class="ops-eyebrow">Category Management</p><h1>Categories</h1><p>Create and organise customer-facing menu categories from a dedicated page.</p></div><div class="ops-hero-side"><span class="ops-role-pill">👨‍🍳 <?php echo h($_SESSION['full_name'] ?? 'Chef'); ?></span><a href="chef-control.php" class="ops-link-btn">Back to Dashboard</a></div></section>
            <?php if ($toast): ?><div class="<?php echo ($toast['type'] ?? '') === 'danger' ? 'alert-error' : 'alert-success'; ?>"><?php echo h($toast['text'] ?? 'Action completed.'); ?></div><?php endif; ?>
            <?php if (!empty($errors['general'])): ?><div class="alert-error"><?php echo h($errors['general']); ?></div><?php endif; ?>
            <section class="ops-panel">
                <div class="ops-panel-heading"><div><p class="ops-eyebrow"><?php echo $edit_category ? 'Update Category' : 'New Category'; ?></p><h2><?php echo $edit_category ? 'Edit Category' : 'Add Category'; ?></h2><p>Category images are used on the customer dashboard category level.</p></div></div>
                <form method="POST" enctype="multipart/form-data" class="chef-form-grid">
                    <?php if ($edit_category): ?><input type="hidden" name="category_id" value="<?php echo (int)$edit_category['category_id']; ?>"><input type="hidden" name="current_image" value="<?php echo h($edit_category['image_url'] ?? ''); ?>"><?php endif; ?>
                    <div><label>Category Name *</label><input type="text" name="category_name" required value="<?php echo h($edit_category['name'] ?? ''); ?>"><?php if (isset($errors['category name'])): ?><span class="error-message"><?php echo h($errors['category name']); ?></span><?php endif; ?></div>
                    <div><label>Image (JPG, PNG, WEBP)</label><input type="file" name="category_image" accept=".jpg,.jpeg,.png,.webp"><?php if (!empty($edit_category['image_url'])): ?><div class="current-image"><img src="<?php echo h($edit_category['image_url']); ?>" alt="Current category image"></div><?php endif; ?><?php if (isset($errors['image'])): ?><span class="error-message"><?php echo h($errors['image']); ?></span><?php endif; ?></div>
                    <div class="full"><label>Description</label><textarea name="category_description"><?php echo h($edit_category['description'] ?? ''); ?></textarea><?php if (isset($errors['description'])): ?><span class="error-message"><?php echo h($errors['description']); ?></span><?php endif; ?></div>
                    <div class="full"><label><input type="checkbox" name="category_is_available" value="1" <?php echo $edit_category ? (((int)$edit_category['is_available'] === 1) ? 'checked' : '') : 'checked'; ?>> Available</label></div>
                    <div class="full"><button type="submit" name="<?php echo $edit_category ? 'update_category' : 'add_category'; ?>" class="ops-btn ops-btn-primary"><?php echo $edit_category ? 'Update Category' : 'Add Category'; ?></button><?php if ($edit_category): ?> <a class="ops-btn ops-btn-ghost" href="chef-categories.php">Cancel</a><?php endif; ?></div>
                </form>
            </section>
            <section class="ops-panel"><div class="ops-panel-heading"><div><p class="ops-eyebrow">Category List</p><h2>Existing Categories</h2><p>Edit availability, text, and category images.</p></div></div>
                <div class="ops-table-wrap"><table class="chef-table"><tr><th>ID</th><th>Image</th><th>Name</th><th>Description</th><th>Available</th><th>Actions</th></tr><?php if ($categories && $categories->num_rows > 0): $i=1; while ($cat=$categories->fetch_assoc()): ?><tr><td><?php echo $i++; ?></td><td><?php if (!empty($cat['image_url'])): ?><img src="<?php echo h($cat['image_url']); ?>" alt="Category" class="category-thumb"><?php else: ?>No image<?php endif; ?></td><td><?php echo h($cat['name']); ?></td><td><?php echo h($cat['description']); ?></td><td><?php echo ((int)$cat['is_available'] === 1) ? 'Yes' : 'No'; ?></td><td><a class="ops-btn ops-btn-ghost" href="chef-categories.php?edit_category_id=<?php echo (int)$cat['category_id']; ?>">Edit</a></td></tr><?php endwhile; else: ?><tr><td colspan="6">No categories found.</td></tr><?php endif; ?></table></div>
            </section>
        </div>
    </div>
</div>
</body>
</html>
