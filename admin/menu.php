<?php
// ============================================
//  MENU MANAGEMENT — admin/menu.php
//  Categories + Items — Add / Edit / Disable
// ============================================
require_once __DIR__ . '/../config/config.php';

session_start();
$adminPass = 'admin123'; // same as index.php
if ($_POST['pass'] ?? '' === $adminPass) $_SESSION['admin'] = true;
if (!($_SESSION['admin'] ?? false)) {
    header('Location: index.php'); exit;
}

$db  = getDB();
$msg = '';
$err = '';

// ============================================
//  HANDLE ALL FORM ACTIONS
// ============================================

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---- Category: Add ----
if ($action === 'add_category') {
    $name  = trim($_POST['name'] ?? '');
    $emoji = trim($_POST['emoji'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($name) {
        $db->prepare("INSERT INTO categories (name, emoji, sort_order, is_active) VALUES (?,?,?,1)")
           ->execute([$name, $emoji, $sort]);
        $msg = "Category '$name' add ho gayi! ✅";
    } else { $err = "Category naam zaroori hai."; }
}

// ---- Category: Edit ----
if ($action === 'edit_category') {
    $id    = (int)$_POST['id'];
    $name  = trim($_POST['name'] ?? '');
    $emoji = trim($_POST['emoji'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    if ($name && $id) {
        $db->prepare("UPDATE categories SET name=?, emoji=?, sort_order=? WHERE id=?")
           ->execute([$name, $emoji, $sort, $id]);
        $msg = "Category update ho gayi! ✅";
    }
}

// ---- Category: Toggle Active ----
if ($action === 'toggle_category') {
    $id  = (int)($_GET['id'] ?? 0);
    $val = (int)($_GET['val'] ?? 0);
    $db->prepare("UPDATE categories SET is_active=? WHERE id=?")->execute([$val, $id]);
    $msg = $val ? "Category enabled ✅" : "Category disabled ⛔";
}

// ---- Item: Add ----
if ($action === 'add_item') {
    $cat   = (int)$_POST['category_id'];
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $price = (float)$_POST['price'];
    if ($name && $cat && $price > 0) {
        $db->prepare("INSERT INTO menu_items (category_id, name, description, price, is_available) VALUES (?,?,?,?,1)")
           ->execute([$cat, $name, $desc, $price]);
        $msg = "Item '$name' add ho gaya! ✅";
    } else { $err = "Name, Category te Price zaroori hain."; }
}

// ---- Item: Edit ----
if ($action === 'edit_item') {
    $id    = (int)$_POST['id'];
    $cat   = (int)$_POST['category_id'];
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $price = (float)$_POST['price'];
    if ($name && $id) {
        $db->prepare("UPDATE menu_items SET category_id=?, name=?, description=?, price=? WHERE id=?")
           ->execute([$cat, $name, $desc, $price, $id]);
        $msg = "Item update ho gaya! ✅";
    }
}

// ---- Item: Toggle Available ----
if ($action === 'toggle_item') {
    $id  = (int)($_GET['id'] ?? 0);
    $val = (int)($_GET['val'] ?? 0);
    $db->prepare("UPDATE menu_items SET is_available=? WHERE id=?")->execute([$val, $id]);
    $msg = $val ? "Item available ✅" : "Item disabled ⛔";
}

// ---- Item: Delete ----
if ($action === 'delete_item') {
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("DELETE FROM menu_items WHERE id=?")->execute([$id]);
    $msg = "Item delete ho gaya!";
}

// ============================================
//  FETCH DATA
// ============================================
$categories = $db->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll();
$items      = $db->query("SELECT mi.*, c.name AS cat_name FROM menu_items mi JOIN categories c ON mi.category_id=c.id ORDER BY mi.category_id, mi.id")->fetchAll();

// For edit modal pre-fill
$editCat  = null;
$editItem = null;
if (isset($_GET['edit_cat'])) {
    $s = $db->prepare("SELECT * FROM categories WHERE id=?");
    $s->execute([(int)$_GET['edit_cat']]);
    $editCat = $s->fetch();
}
if (isset($_GET['edit_item'])) {
    $s = $db->prepare("SELECT * FROM menu_items WHERE id=?");
    $s->execute([(int)$_GET['edit_item']]);
    $editItem = $s->fetch();
}
?>
<!DOCTYPE html>
<html lang="pa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Menu Manager — <?= RESTAURANT_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--green:#1db954;--green2:#17a349;--red:#e53935;--amber:#f59e0b;--blue:#3b82f6;--bg:#0f1117;--bg2:#181c25;--bg3:#1f2433;--dborder:rgba(255,255,255,.08);--text:#eef0f4;--muted:#7a8099;--radius:12px;}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }

/* ---- HEADER ---- */
.header {
  background: var(--bg2);
  border-bottom: 1px solid var(--dborder);
  padding: 0 16px;
  display: flex; align-items: center; gap: 12px;
  height: 60px; position: sticky; top: 0; z-index: 100;
}
.header-logo { font-size: 22px; }
.header-title { font-size: 15px; font-weight: 700; letter-spacing: -.3px; }
.header-sub { font-size: 12px; color: var(--muted); margin-top: 1px; }
.header-nav { margin-left: auto; display: flex; gap: 6px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.header-nav a {
  font-size: 12px; font-weight: 600; padding: 7px 12px;
  border-radius: 8px; text-decoration: none;
  color: var(--muted); transition: all .2s; white-space: nowrap;
}
.header-nav a:hover { background: var(--bg3); color: var(--text); }
.header-nav a.active { background: var(--green); color: #fff; }

/* ---- LAYOUT ---- */
.layout { display: grid; grid-template-columns: 300px 1fr; gap: 0; min-height: calc(100vh - 60px); }

/* ---- SIDEBAR ---- */
.sidebar {
  background: var(--bg2);
  border-right: 1px solid var(--dborder);
  padding: 20px;
  overflow-y: auto;
}
.section-title {
  font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
  color: var(--muted); text-transform: uppercase; margin-bottom: 14px;
}

/* ---- FORMS ---- */
.form-card {
  background: var(--bg3);
  border: 1px solid var(--dborder);
  border-radius: var(--radius);
  padding: 18px;
  margin-bottom: 20px;
}
.form-card h3 { font-size: 13px; font-weight: 700; margin-bottom: 14px; color: var(--text); }
.field { margin-bottom: 10px; }
.field label { display: block; font-size: 11px; font-weight: 600; color: var(--muted); margin-bottom: 5px; letter-spacing: .4px; }
.field input, .field select, .field textarea {
  width: 100%; background: var(--bg2); border: 1px solid var(--dborder);
  border-radius: 8px; padding: 9px 12px; color: var(--text);
  font-size: 13px; font-family: inherit; transition: border-color .2s;
  outline: none;
}
.field input:focus, .field select:focus, .field textarea:focus { border-color: var(--green); }
.field textarea { resize: vertical; min-height: 60px; }
.field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 9px 16px; border-radius: 8px; border: none;
  font-size: 12px; font-weight: 700; cursor: pointer;
  font-family: inherit; transition: all .2s; text-decoration: none;
}
.btn-green  { background: var(--green); color: #fff; }
.btn-green:hover  { background: var(--green2); }
.btn-outline { background: transparent; border: 1px solid var(--dborder); color: var(--muted); }
.btn-outline:hover { border-color: var(--green); color: var(--green); }
.btn-red    { background: var(--red); color: #fff; }
.btn-amber  { background: var(--amber); color: #000; }
.btn-full   { width: 100%; justify-content: center; }

/* ---- MAIN CONTENT ---- */
.main { padding: 20px; overflow-y: auto; }

/* ---- FLASH MESSAGES ---- */
.flash {
  padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 600;
  margin-bottom: 20px; display: flex; align-items: center; gap: 8px;
}
.flash.success { background: rgba(29,185,84,.15); border: 1px solid rgba(29,185,84,.3); color: var(--green); }
.flash.error   { background: rgba(229,57,53,.15); border: 1px solid rgba(229,57,53,.3); color: #ef5350; }

/* ---- CATEGORY TABS ---- */
.cat-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
.cat-tab {
  padding: 7px 14px; border-radius: 20px; font-size: 12px; font-weight: 600;
  cursor: pointer; border: 1px solid var(--dborder); background: var(--bg2);
  color: var(--muted); transition: all .2s; text-decoration: none;
}
.cat-tab:hover, .cat-tab.active { background: var(--green); border-color: var(--green); color: #fff; }
.cat-tab.disabled-tab { opacity: .45; }

/* ---- CATEGORY CARDS ---- */
.cat-grid { display: grid; gap: 10px; margin-bottom: 28px; }
.cat-card {
  background: var(--bg2); border: 1px solid var(--dborder);
  border-radius: var(--radius); padding: 14px 18px;
  display: flex; align-items: center; gap: 12px;
  transition: border-color .2s;
}
.cat-card:hover { border-color: rgba(29,185,84,.3); }
.cat-emoji { font-size: 24px; width: 42px; text-align: center; }
.cat-info { flex: 1; }
.cat-name { font-size: 14px; font-weight: 700; }
.cat-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
.cat-actions { display: flex; gap: 6px; align-items: center; }

/* ---- ITEM TABLE ---- */
.items-table-wrap { background: var(--bg2); border: 1px solid var(--dborder); border-radius: var(--radius); overflow-x: auto; }
.items-table { width: 100%; border-collapse: collapse; min-width: 500px; }
.items-table th {
  background: var(--bg3); padding: 11px 16px; text-align: left;
  font-size: 10px; font-weight: 700; color: var(--muted);
  letter-spacing: 1px; text-transform: uppercase;
  border-bottom: 1px solid var(--dborder);
}
.items-table td {
  padding: 12px 16px; border-bottom: 1px solid var(--dborder);
  font-size: 13px; vertical-align: middle; color: var(--text);
}
.items-table tr:last-child td { border-bottom: none; }
.items-table tr.disabled-row td { opacity: .45; }
.items-table tr:hover td { background: var(--bg3); }
.item-name { font-weight: 600; color: var(--text); }
.item-desc { font-size: 11px; color: var(--muted); margin-top: 2px; }
.price-tag { font-weight: 700; color: var(--green); }
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 9px; border-radius: 20px; font-size: 10px; font-weight: 700; letter-spacing: .3px;
}
.badge-on  { background: rgba(29,185,84,.15); color: var(--green); border: 1px solid rgba(29,185,84,.25); }
.badge-off { background: rgba(229,57,53,.12); color: #ef5350; border: 1px solid rgba(229,57,53,.2); }
.actions-cell { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }

/* ---- MODAL ---- */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.7); z-index: 200;
  display: flex; align-items: center; justify-content: center; padding: 16px;
  backdrop-filter: blur(4px);
}
.modal {
  background: var(--bg2); border: 1px solid var(--dborder);
  border-radius: 16px; padding: 24px; width: 100%; max-width: 480px;
  animation: modalIn .2s ease; max-height: 90vh; overflow-y: auto;
}
@keyframes modalIn { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-header h2 { font-size: 16px; font-weight: 700; }
.modal-close { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 20px; line-height: 1; padding: 4px; }
.modal-close:hover { color: var(--text); }
.modal-footer { display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end; }

/* ---- EMPTY STATE ---- */
.empty { text-align: center; padding: 50px 20px; color: var(--muted); }
.empty-icon { font-size: 40px; margin-bottom: 12px; }
.empty p { font-size: 13px; }

/* ---- FILTER BAR ---- */
.filter-bar { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
.filter-bar input {
  background: var(--bg2); border: 1px solid var(--dborder);
  border-radius: 8px; padding: 8px 14px; color: var(--text);
  font-size: 13px; font-family: inherit; outline: none; flex: 1; min-width: 160px;
}
.filter-bar input:focus { border-color: var(--green); }

/* ---- FILTER SELECT ---- */
#catFilter {
  background: var(--bg2); border: 1px solid var(--dborder);
  border-radius: 8px; padding: 8px 12px; color: var(--text);
  font-size: 13px; font-family: inherit; outline: none;
}
#catFilter:focus { border-color: var(--green); }

/* ---- ITEM COUNT BADGE ---- */
.count-pill {
  display: inline-flex; align-items: center; justify-content: center;
  width: 20px; height: 20px; border-radius: 50%; background: var(--bg3);
  font-size: 10px; font-weight: 700; color: var(--muted); margin-left: 4px;
}

@media (max-width: 900px) {
  .layout { grid-template-columns: 1fr; }
  .sidebar { border-right: none; border-bottom: 1px solid var(--dborder); }
}
@media (max-width: 640px) {
  .header { padding: 0 12px; }
  .header-nav a { padding: 5px 8px; font-size: 11px; }
  .main { padding: 14px; }
  .sidebar { padding: 14px; }
  .cat-grid { grid-template-columns: 1fr !important; }
}
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <span class="header-logo">🍽</span>
  <div>
    <div class="header-title"><?= htmlspecialchars(RESTAURANT_NAME) ?></div>
    <div class="header-sub">Admin Panel</div>
  </div>
  <nav class="header-nav">
    <a href="index.php">📋 Orders</a>
    <a href="menu.php" class="active">🍛 Menu</a>
  </nav>
</div>

<div class="layout">

<!-- ===========================
     LEFT SIDEBAR — Add Forms
     =========================== -->
<aside class="sidebar">

  <?php if ($msg): ?><div class="flash success">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash error">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- ADD CATEGORY FORM -->
  <div class="section-title">Category</div>
  <div class="form-card">
    <h3>➕ Nawi Category Add Karo</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_category">
      <div class="field-row">
        <div class="field">
          <label>EMOJI</label>
          <input type="text" name="emoji" placeholder="🍗" maxlength="4">
        </div>
        <div class="field">
          <label>SORT ORDER</label>
          <input type="number" name="sort_order" value="<?= count($categories)+1 ?>" min="1">
        </div>
      </div>
      <div class="field">
        <label>CATEGORY NAAM *</label>
        <input type="text" name="name" placeholder="e.g. Starters" required>
      </div>
      <button type="submit" class="btn btn-green btn-full">Add Category</button>
    </form>
  </div>

  <!-- ADD ITEM FORM -->
  <div class="section-title" style="margin-top:8px">Menu Item</div>
  <div class="form-card">
    <h3>➕ Nawa Item Add Karo</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_item">
      <div class="field">
        <label>CATEGORY *</label>
        <select name="category_id" required>
          <option value="">-- Category choose karo --</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['emoji'].' '.$c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>ITEM NAAM *</label>
        <input type="text" name="name" placeholder="e.g. Butter Chicken" required>
      </div>
      <div class="field">
        <label>DESCRIPTION (optional)</label>
        <input type="text" name="description" placeholder="e.g. Creamy tomato gravy">
      </div>
      <div class="field">
        <label>PRICE (₹) *</label>
        <input type="number" name="price" placeholder="250" min="1" step="0.01" required>
      </div>
      <button type="submit" class="btn btn-green btn-full">Add Item</button>
    </form>
  </div>

</aside>

<!-- ===========================
     MAIN — Categories + Items
     =========================== -->
<main class="main">

  <!-- CATEGORIES SECTION -->
  <div class="section-title">Categories <span class="count-pill"><?= count($categories) ?></span></div>
  <div class="cat-grid" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr))">
    <?php foreach ($categories as $c):
      $itemCount = count(array_filter($items, fn($i) => $i['category_id'] == $c['id']));
      $activeCount = count(array_filter($items, fn($i) => $i['category_id'] == $c['id'] && $i['is_available']));
    ?>
    <div class="cat-card <?= !$c['is_active'] ? 'disabled-row' : '' ?>">
      <div class="cat-emoji"><?= htmlspecialchars($c['emoji'] ?: '📦') ?></div>
      <div class="cat-info">
        <div class="cat-name"><?= htmlspecialchars($c['name']) ?></div>
        <div class="cat-meta"><?= $activeCount ?>/<?= $itemCount ?> items active · Sort: <?= $c['sort_order'] ?></div>
      </div>
      <div class="cat-actions">
        <?php if ($c['is_active']): ?>
          <span class="badge badge-on">ON</span>
          <a href="?action=toggle_category&id=<?= $c['id'] ?>&val=0" class="btn btn-outline" style="padding:5px 10px;font-size:11px" title="Disable">⛔</a>
        <?php else: ?>
          <span class="badge badge-off">OFF</span>
          <a href="?action=toggle_category&id=<?= $c['id'] ?>&val=1" class="btn btn-outline" style="padding:5px 10px;font-size:11px" title="Enable">✅</a>
        <?php endif; ?>
        <a href="?edit_cat=<?= $c['id'] ?>#cat-modal" class="btn btn-outline" style="padding:5px 10px;font-size:11px" title="Edit">✏️</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ITEMS SECTION -->
  <div class="filter-bar">
    <div class="section-title" style="margin-bottom:0">Menu Items <span class="count-pill"><?= count($items) ?></span></div>
    <input type="text" id="searchInput" placeholder="🔍 Item search karo..." oninput="filterItems()">
    <select id="catFilter" onchange="filterItems()">
      <option value="">Saari Categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['emoji'].' '.$c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="items-table-wrap">
    <table class="items-table" id="itemsTable">
      <thead>
        <tr>
          <th>Item</th>
          <th>Category</th>
          <th>Price</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
        <tr class="item-row <?= !$item['is_available'] ? 'disabled-row' : '' ?>" data-cat="<?= $item['category_id'] ?>" data-name="<?= strtolower($item['name']) ?>">
          <td>
            <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
            <?php if ($item['description']): ?>
              <div class="item-desc"><?= htmlspecialchars($item['description']) ?></div>
            <?php endif; ?>
          </td>
          <td style="color:var(--muted);font-size:12px"><?= htmlspecialchars($item['cat_name']) ?></td>
          <td><span class="price-tag">₹<?= number_format($item['price'], 0) ?></span></td>
          <td>
            <?php if ($item['is_available']): ?>
              <span class="badge badge-on">● Available</span>
            <?php else: ?>
              <span class="badge badge-off">● Disabled</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="actions-cell">
              <a href="?edit_item=<?= $item['id'] ?>#item-modal" class="btn btn-outline" style="padding:5px 12px;font-size:11px">✏️ Edit</a>
              <?php if ($item['is_available']): ?>
                <a href="?action=toggle_item&id=<?= $item['id'] ?>&val=0" class="btn btn-outline" style="padding:5px 10px;font-size:11px;color:#e57373;border-color:rgba(229,57,53,.3)" title="Disable">⛔</a>
              <?php else: ?>
                <a href="?action=toggle_item&id=<?= $item['id'] ?>&val=1" class="btn btn-outline" style="padding:5px 10px;font-size:11px;color:var(--green);border-color:rgba(29,185,84,.3)" title="Enable">✅</a>
              <?php endif; ?>
              <a href="?action=delete_item&id=<?= $item['id'] ?>" class="btn btn-outline" style="padding:5px 10px;font-size:11px;color:#e57373" title="Delete" onclick="return confirm('Delete karna sure ho? Undo nahi ho sakda!')">🗑️</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
        <tr><td colspan="5"><div class="empty"><div class="empty-icon">🍽</div><p>Koi item nahi. Upar wale form se add karo!</p></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</main>
</div>

<!-- ===========================
     EDIT CATEGORY MODAL
     =========================== -->
<?php if ($editCat): ?>
<div class="modal-overlay" id="cat-modal">
  <div class="modal">
    <div class="modal-header">
      <h2>✏️ Category Edit Karo</h2>
      <a href="menu.php"><button class="modal-close">✕</button></a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_category">
      <input type="hidden" name="id" value="<?= $editCat['id'] ?>">
      <div class="field-row">
        <div class="field">
          <label>EMOJI</label>
          <input type="text" name="emoji" value="<?= htmlspecialchars($editCat['emoji']) ?>" maxlength="4">
        </div>
        <div class="field">
          <label>SORT ORDER</label>
          <input type="number" name="sort_order" value="<?= $editCat['sort_order'] ?>" min="1">
        </div>
      </div>
      <div class="field">
        <label>CATEGORY NAAM *</label>
        <input type="text" name="name" value="<?= htmlspecialchars($editCat['name']) ?>" required>
      </div>
      <div class="modal-footer">
        <a href="menu.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-green">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ===========================
     EDIT ITEM MODAL
     =========================== -->
<?php if ($editItem): ?>
<div class="modal-overlay" id="item-modal">
  <div class="modal">
    <div class="modal-header">
      <h2>✏️ Item Edit Karo</h2>
      <a href="menu.php"><button class="modal-close">✕</button></a>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_item">
      <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
      <div class="field">
        <label>CATEGORY *</label>
        <select name="category_id" required>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $c['id']==$editItem['category_id']?'selected':'' ?>>
              <?= htmlspecialchars($c['emoji'].' '.$c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>ITEM NAAM *</label>
        <input type="text" name="name" value="<?= htmlspecialchars($editItem['name']) ?>" required>
      </div>
      <div class="field">
        <label>DESCRIPTION</label>
        <input type="text" name="description" value="<?= htmlspecialchars($editItem['description']) ?>" placeholder="Optional">
      </div>
      <div class="field">
        <label>PRICE (₹) *</label>
        <input type="number" name="price" value="<?= $editItem['price'] ?>" min="1" step="0.01" required>
      </div>
      <div class="modal-footer">
        <a href="menu.php" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-green">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function filterItems() {
  const search = document.getElementById('searchInput').value.toLowerCase();
  const catId  = document.getElementById('catFilter').value;
  document.querySelectorAll('.item-row').forEach(row => {
    const nameMatch = row.dataset.name.includes(search);
    const catMatch  = !catId || row.dataset.cat === catId;
    row.style.display = (nameMatch && catMatch) ? '' : 'none';
  });
}

// Auto-open modal if URL has hash
if (window.location.hash) {
  const el = document.querySelector(window.location.hash);
  if (el) el.scrollIntoView();
}
</script>
</body>
</html>
