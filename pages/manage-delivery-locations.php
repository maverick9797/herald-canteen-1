<?php
// manage-delivery-locations.php — Chef & Staff manage delivery locations
require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';

require_any_role(['chef', 'staff']);

$role      = $_SESSION['role'];
$user_name = $_SESSION['full_name'] ?? ucfirst($role);

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Helper ────────────────────────────────────────────────────────────────────
function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$errors  = [];
$success = '';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token. Please try again.';
    } else {
        $action = trim($_POST['action'] ?? '');

        // ── ADD LOCATION ──────────────────────────────────────────────────────
        if ($action === 'add_location') {
            $loc_name   = trim(strip_tags($_POST['location_name'] ?? ''));
            $blk_name   = trim(strip_tags($_POST['block_name']    ?? ''));
            $sort_order = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
            $is_active  = isset($_POST['is_active']) ? 1 : 0;

            if ($loc_name === '')                           $errors[] = 'Location name is required.';
            elseif (mb_strlen($loc_name) > 150)            $errors[] = 'Location name too long (max 150 chars).';
            if ($blk_name === '')                           $errors[] = 'Block name is required.';
            elseif (mb_strlen($blk_name) > 100)            $errors[] = 'Block name too long (max 100 chars).';
            if ($sort_order === false || $sort_order < 0)  $errors[] = 'Sort order must be a non-negative integer.';

            if (empty($errors)) {
                // Duplicate check
                $dup = $conn->prepare('SELECT location_id FROM delivery_locations WHERE location_name=? AND block_name=? LIMIT 1');
                $dup->bind_param('ss', $loc_name, $blk_name);
                $dup->execute();
                $dup->store_result();
                if ($dup->num_rows > 0) {
                    $errors[] = 'A location with that name already exists in that block.';
                } else {
                    $ins = $conn->prepare('INSERT INTO delivery_locations (location_name, block_name, sort_order, is_active) VALUES (?,?,?,?)');
                    $ins->bind_param('ssii', $loc_name, $blk_name, $sort_order, $is_active);
                    $ins->execute();
                    $ins->close();
                    $_SESSION['_toast'] = ['text' => 'Location added successfully.', 'type' => 'success'];
                    header('Location: manage-delivery-locations.php');
                    exit;
                }
                $dup->close();
            }
        }

        // ── EDIT LOCATION ──────────────────────────────────────────────────────
        elseif ($action === 'edit_location') {
            $loc_id     = filter_var($_POST['location_id'] ?? 0, FILTER_VALIDATE_INT);
            $loc_name   = trim(strip_tags($_POST['location_name'] ?? ''));
            $blk_name   = trim(strip_tags($_POST['block_name']    ?? ''));
            $sort_order = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
            $is_active  = isset($_POST['is_active']) ? 1 : 0;

            if (!$loc_id || $loc_id <= 0)                  $errors[] = 'Invalid location ID.';
            if ($loc_name === '')                           $errors[] = 'Location name is required.';
            elseif (mb_strlen($loc_name) > 150)            $errors[] = 'Location name too long (max 150 chars).';
            if ($blk_name === '')                           $errors[] = 'Block name is required.';
            elseif (mb_strlen($blk_name) > 100)            $errors[] = 'Block name too long (max 100 chars).';
            if ($sort_order === false || $sort_order < 0)  $errors[] = 'Sort order must be a non-negative integer.';

            if (empty($errors)) {
                $upd = $conn->prepare('UPDATE delivery_locations SET location_name=?, block_name=?, sort_order=?, is_active=? WHERE location_id=?');
                $upd->bind_param('ssiii', $loc_name, $blk_name, $sort_order, $is_active, $loc_id);
                $upd->execute();
                $upd->close();
                $_SESSION['_toast'] = ['text' => 'Location updated successfully.', 'type' => 'success'];
                header('Location: manage-delivery-locations.php');
                exit;
            }
        }

        // ── TOGGLE ACTIVE ─────────────────────────────────────────────────────
        elseif ($action === 'toggle_active') {
            $loc_id = filter_var($_POST['location_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($loc_id && $loc_id > 0) {
                $conn->query("UPDATE delivery_locations SET is_active = 1 - is_active WHERE location_id = " . (int)$loc_id);
                $_SESSION['_toast'] = ['text' => 'Location status updated.', 'type' => 'success'];
            }
            header('Location: manage-delivery-locations.php');
            exit;
        }
    }
}

// ── TOAST ─────────────────────────────────────────────────────────────────────
$toast = null;
if (isset($_SESSION['_toast'])) {
    $toast = $_SESSION['_toast'];
    unset($_SESSION['_toast']);
}

// ── Editing? ──────────────────────────────────────────────────────────────────
$edit_loc = null;
$edit_id  = filter_var($_GET['edit_id'] ?? 0, FILTER_VALIDATE_INT);
if ($edit_id && $edit_id > 0) {
    $es = $conn->prepare('SELECT * FROM delivery_locations WHERE location_id=? LIMIT 1');
    $es->bind_param('i', $edit_id);
    $es->execute();
    $edit_loc = $es->get_result()->fetch_assoc();
    $es->close();
}

// ── Fetch all locations grouped by block ──────────────────────────────────────
$all_locs = $conn->query('SELECT * FROM delivery_locations ORDER BY block_name, sort_order, location_name');
$blocks   = [];
while ($row = $all_locs->fetch_assoc()) {
    $blocks[$row['block_name']][] = $row;
}

// ── Known blocks for dropdown ─────────────────────────────────────────────────
$known_blocks = ['WLV Block', 'Main Block', 'IT Block', 'Science Block', 'Management Block'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Delivery Locations — Herald Canteen</title>
<script src="../assets/js/theme.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
/* ── page-specific overrides ── */
.loc-page-wrap { display: flex; gap: 0; min-height: 100vh; }
.loc-main { flex: 1; padding: 28px 32px; max-width: 960px; }
.loc-card { background: var(--card-bg, #1e1e1e); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 22px 26px; margin-bottom: 24px; }
.loc-card h2 { font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 18px; }
.loc-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.loc-form-row.wide { grid-template-columns: 1fr; }
.loc-form label { font-size: 12px; color: rgba(255,255,255,0.5); display: block; margin-bottom: 5px; }
.loc-form input[type="text"],
.loc-form input[type="number"],
.loc-form select { width: 100%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 10px 12px; color: #fff; font-size: 13px; font-family: inherit; }
.loc-form input:focus, .loc-form select:focus { outline: none; border-color: rgba(77,184,72,0.5); }
.loc-form select option { background: #1e1e1e; }
.loc-submit-row { display: flex; gap: 10px; align-items: center; margin-top: 6px; }
.btn-loc-add { background: #4db848; color: #fff; border: none; padding: 10px 22px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
.btn-loc-cancel { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.65); border: none; padding: 10px 18px; border-radius: 8px; font-size: 13px; cursor: pointer; text-decoration: none; }
.block-section { margin-bottom: 26px; }
.block-heading { font-size: 13px; font-weight: 700; color: #4db848; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid rgba(77,184,72,0.2); }
.loc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.loc-table th { text-align: left; padding: 8px 10px; font-size: 11px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: .4px; border-bottom: 1px solid rgba(255,255,255,0.07); }
.loc-table td { padding: 10px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); color: rgba(255,255,255,0.8); vertical-align: middle; }
.loc-table tr:last-child td { border-bottom: none; }
.btn-loc-sm { border: none; padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
.btn-loc-edit { background: rgba(77,184,72,0.15); color: #4db848; border: 1px solid rgba(77,184,72,0.3); }
.btn-loc-toggle-off { background: rgba(255,100,100,0.12); color: rgba(255,120,120,0.9); border: 1px solid rgba(255,100,100,0.2); }
.btn-loc-toggle-on  { background: rgba(77,184,72,0.12); color: #4db848; border: 1px solid rgba(77,184,72,0.25); }
.loc-errors { background: rgba(220,50,50,0.1); border: 1px solid rgba(220,50,50,0.3); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; color: #ff9a9a; font-size: 13px; }
.loc-errors li { margin-left: 16px; margin-top: 4px; }
.loc-toast { position: fixed; top: 20px; right: 20px; background: #4db848; color: #fff; padding: 12px 20px; border-radius: 10px; font-size: 13px; font-weight: 600; z-index: 9999; box-shadow: 0 4px 20px rgba(0,0,0,.4); animation: toastIn .3s ease; }
.loc-toast.danger { background: #d93f3f; }
@keyframes toastIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
.loc-checkbox-row { display: flex; align-items: center; gap: 8px; font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 4px; }
.loc-checkbox-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: #4db848; }
</style>
</head>
<body>
<div class="layout loc-page-wrap">

    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="navbar-title">
            <span>🍽️</span> Herald Canteen
        </div>
        <nav>
            <?php if ($role === 'chef'): ?>
                <a href="chef-control.php">👨‍🍳 Chef Dashboard</a>
                <a href="chef-kitchen-tickets.php">🎫 Kitchen Tickets</a>
                <a href="chef-categories.php">🖼️ Categories</a>
                <a href="chef-menu.php">🍽️ Manage Menu</a>
                <a href="manage-delivery-locations.php" class="active">📍 Delivery Locations</a>
                <a href="logout.php">🚪 Logout</a>
            <?php else: ?>
                <a href="staff-control.php">🧾 Staff Home</a>
                <a href="staff-control.php#orders-section">📦 Active Orders</a>
                <a href="staff-order-history.php">🕘 Paid History</a>
                <a href="manage-delivery-locations.php" class="active">📍 Delivery Locations</a>
                <a href="logout.php">🚪 Logout</a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- MAIN -->
    <div class="main loc-main">

        <!-- Topbar -->
        <div class="topbar" style="margin-bottom:24px;">
            <div>
                <h1 style="font-size:22px;font-weight:800;color:#fff;margin-bottom:2px;">📍 Delivery Locations</h1>
                <p style="font-size:13px;color:rgba(255,255,255,0.45);">Manage where orders can be delivered on campus.</p>
            </div>
            <span class="ops-role-pill"><?= $role === 'chef' ? '👨‍🍳' : '🧾' ?> <?= h($user_name) ?></span>
        </div>

        <!-- ERRORS -->
        <?php if (!empty($errors)): ?>
        <div class="loc-errors">
            <strong>Please fix the following:</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- ADD / EDIT FORM -->
        <div class="loc-card">
            <h2><?= $edit_loc ? '✏️ Edit Location' : '➕ Add Delivery Location' ?></h2>
            <form method="POST" class="loc-form" action="manage-delivery-locations.php<?= $edit_loc ? '?edit_id=' . (int)$edit_loc['location_id'] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action"     value="<?= $edit_loc ? 'edit_location' : 'add_location' ?>">
                <?php if ($edit_loc): ?>
                    <input type="hidden" name="location_id" value="<?= (int)$edit_loc['location_id'] ?>">
                <?php endif; ?>

                <div class="loc-form-row">
                    <div>
                        <label>Location Name <span style="color:#ff6b6b">*</span></label>
                        <input type="text" name="location_name" maxlength="150" required
                               value="<?= h($edit_loc['location_name'] ?? ($_POST['location_name'] ?? '')) ?>"
                               placeholder="e.g. Library Entrance">
                    </div>
                    <div>
                        <label>Block Name <span style="color:#ff6b6b">*</span></label>
                        <select name="block_name" id="block_select" onchange="toggleOtherBlock(this)">
                            <?php
                            $cur_block = $edit_loc['block_name'] ?? ($_POST['block_name'] ?? '');
                            $is_other  = !in_array($cur_block, $known_blocks) && $cur_block !== '';
                            foreach ($known_blocks as $kb): ?>
                                <option value="<?= h($kb) ?>" <?= $cur_block === $kb ? 'selected' : '' ?>><?= h($kb) ?></option>
                            <?php endforeach; ?>
                            <option value="__other__" <?= $is_other ? 'selected' : '' ?>>Other…</option>
                        </select>
                        <input type="text" name="block_name_other" id="block_other_input"
                               maxlength="100" placeholder="Enter custom block name"
                               value="<?= $is_other ? h($cur_block) : '' ?>"
                               style="margin-top:8px;display:<?= $is_other ? 'block' : 'none' ?>;">
                    </div>
                </div>

                <div class="loc-form-row">
                    <div>
                        <label>Sort Order</label>
                        <input type="number" name="sort_order" min="0" value="<?= (int)($edit_loc['sort_order'] ?? ($_POST['sort_order'] ?? 0)) ?>">
                    </div>
                    <div style="display:flex;align-items:flex-end;padding-bottom:4px;">
                        <label class="loc-checkbox-row">
                            <input type="checkbox" name="is_active" value="1"
                                <?= ((int)($edit_loc['is_active'] ?? ($_POST['is_active'] ?? 1))) ? 'checked' : '' ?>>
                            Active (visible to customers)
                        </label>
                    </div>
                </div>

                <div class="loc-submit-row">
                    <button type="submit" class="btn-loc-add">
                        <?= $edit_loc ? '💾 Save Changes' : '➕ Add Location' ?>
                    </button>
                    <?php if ($edit_loc): ?>
                        <a href="manage-delivery-locations.php" class="btn-loc-cancel">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- LOCATIONS LIST -->
        <div class="loc-card">
            <h2>🗺️ All Locations</h2>

            <?php if (empty($blocks)): ?>
                <div class="location-empty-state">No delivery locations configured yet. Add one above.</div>
            <?php else: ?>
                <?php foreach ($blocks as $block_name => $locs): ?>
                <div class="block-section">
                    <div class="block-heading"><?= h($block_name) ?> <span style="opacity:.5;font-weight:400;">(<?= count($locs) ?>)</span></div>
                    <table class="loc-table">
                        <thead>
                            <tr>
                                <th>Location Name</th>
                                <th>Sort</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locs as $loc): ?>
                            <tr>
                                <td><?= h($loc['location_name']) ?></td>
                                <td style="color:rgba(255,255,255,0.4);"><?= (int)$loc['sort_order'] ?></td>
                                <td>
                                    <?php if ($loc['is_active']): ?>
                                        <span class="location-status-badge active">Active</span>
                                    <?php else: ?>
                                        <span class="location-status-badge inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <a href="manage-delivery-locations.php?edit_id=<?= (int)$loc['location_id'] ?>"
                                       class="btn-loc-sm btn-loc-edit">✏️ Edit</a>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token"  value="<?= h($csrf) ?>">
                                        <input type="hidden" name="action"      value="toggle_active">
                                        <input type="hidden" name="location_id" value="<?= (int)$loc['location_id'] ?>">
                                        <button type="submit" class="btn-loc-sm <?= $loc['is_active'] ? 'btn-loc-toggle-off' : 'btn-loc-toggle-on' ?>">
                                            <?= $loc['is_active'] ? '⏸ Deactivate' : '▶ Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- /main -->
</div><!-- /layout -->

<?php if ($toast): ?>
<div class="loc-toast <?= $toast['type'] === 'success' ? '' : 'danger' ?>" id="loc-toast">
    <?= h($toast['text']) ?>
</div>
<script>setTimeout(function(){ var t=document.getElementById('loc-toast'); if(t){t.style.opacity='0'; t.style.transition='opacity .4s'; setTimeout(function(){t.remove();},400);} }, 3000);</script>
<?php endif; ?>

<script>
function toggleOtherBlock(sel) {
    var inp = document.getElementById('block_other_input');
    if (sel.value === '__other__') {
        inp.style.display = 'block';
        inp.required = true;
        inp.name = 'block_name';
        sel.name = '_block_name_sel';
    } else {
        inp.style.display = 'none';
        inp.required = false;
        inp.name = 'block_name_other';
        sel.name = 'block_name';
    }
}
// Init on page load
(function(){
    var sel = document.getElementById('block_select');
    if (sel) toggleOtherBlock(sel);
})();
</script>
</body>
</html>
