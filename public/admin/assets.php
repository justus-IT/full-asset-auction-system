<?php
require_once __DIR__ . '/../../includes/layout.php';
$u = require_login('admin');
$db = db();
$conds = ['available', 'poor', 'fair', 'good', 'damaged', 'obsolete'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete') {
        $c = $db->prepare('SELECT count(*) FROM auctions WHERE asset_id = ?'); $c->execute([$id]);
        if ((int) $c->fetchColumn() > 0) flash('err', 'This asset has auction records and cannot be deleted. Withdraw it instead.');
        else { $db->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]); audit((int) $u['id'], 'Asset Deleted', 'asset', $id); flash('ok', 'Asset deleted.'); }
        redirect('/admin/assets.php');
    }
    $f = array_map('trim', array_intersect_key($_POST, array_flip(['asset_code', 'name', 'description', 'category', 'condition', 'location', 'starting_price', 'status'])));
    $err = [];
    foreach (['asset_code', 'name', 'category', 'location'] as $k) if (($f[$k] ?? '') === '') $err[] = ucfirst(str_replace('_', ' ', $k)) . ' is required.';
    if (!in_array($f['condition'] ?? '', ['good', 'fair', 'poor', 'damaged', 'obsolete'], true)) $err[] = 'Select a valid condition.';
    if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $f['starting_price'] ?? '') || (float) $f['starting_price'] <= 0) $err[] = 'Starting price must be greater than zero.';
    $img = null;
    if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || $_FILES['image']['size'] > 2 * 1024 * 1024) $err[] = 'Image must be a JPEG, PNG or WebP file under 2 MB.';
        else { try { $img = storage_upload($_FILES['image']['tmp_name'], bin2hex(random_bytes(12)) . '.' . $ext, $mime); } catch (Throwable $e) { $err[] = 'Image upload failed.'; } }
    }
    if ($err) { flash('err', implode(' ', $err)); redirect('/admin/assets.php' . ($id ? "?edit=$id" : '')); }
    try {
        if ($id) {
            $db->prepare('UPDATE assets SET asset_code=?, name=?, description=?, category=?, condition=?, location=?, starting_price=?, image_url=COALESCE(?, image_url), status=CASE WHEN ? IN (\'available\',\'withdrawn\') AND status IN (\'available\',\'withdrawn\') THEN ? ELSE status END, updated_at=now() WHERE id=?')
               ->execute([$f['asset_code'], $f['name'], $f['description'] ?? '', $f['category'], $f['condition'], $f['location'], $f['starting_price'], $img, $f['status'] ?? 'available', $f['status'] ?? 'available', $id]);
            audit((int) $u['id'], 'Asset Updated', 'asset', $id, $f['asset_code']); flash('ok', 'Asset updated.');
        } else {
            $st = $db->prepare('INSERT INTO assets (asset_code,name,description,category,condition,location,starting_price,image_url,created_by) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
            $st->execute([$f['asset_code'], $f['name'], $f['description'] ?? '', $f['category'], $f['condition'], $f['location'], $f['starting_price'], $img, $u['id']]);
            audit((int) $u['id'], 'Asset Created', 'asset', (int) $st->fetchColumn(), $f['asset_code']); flash('ok', 'Asset registered.');
        }
    } catch (PDOException $e) {
        flash('err', $e->getCode() === '23505' ? 'That asset code already exists.' : 'The asset could not be saved.');
        if ($e->getCode() !== '23505') error_log($e->getMessage());
    }
    redirect('/admin/assets.php');
}
$edit = null;
if (!empty($_GET['edit'])) { $s = $db->prepare('SELECT * FROM assets WHERE id = ?'); $s->execute([(int) $_GET['edit']]); $edit = $s->fetch() ?: null; }
$q = trim($_GET['q'] ?? ''); $stf = $_GET['status'] ?? ''; $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 15;
$where = 'WHERE 1=1'; $p = [];
if ($q !== '') { $where .= ' AND (name ILIKE ? OR asset_code ILIKE ? OR category ILIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
if (in_array($stf, ['available', 'auctioned', 'sold', 'withdrawn'], true)) { $where .= ' AND status = ?'; $p[] = $stf; }
$t = $db->prepare("SELECT count(*) FROM assets $where"); $t->execute($p); $total = (int) $t->fetchColumn();
$sort = ['name' => 'name', 'code' => 'asset_code', 'price' => 'starting_price', 'new' => 'id DESC'][$_GET['sort'] ?? 'new'] ?? 'id DESC';
$r = $db->prepare("SELECT * FROM assets $where ORDER BY $sort LIMIT $per OFFSET " . (($page - 1) * $per)); $r->execute($p); $rows = $r->fetchAll();
page_head('Assets', $u);
$v = fn($k, $d = '') => e((string) ($edit[$k] ?? $d));
?>
<h1>Assets</h1>
<div class="card"><h2><?= $edit ? 'Edit asset' : 'Register asset' ?></h2>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
<div class="row"><div><label>Asset code</label><input name="asset_code" required value="<?= $v('asset_code') ?>"></div>
<div><label>Name</label><input name="name" required value="<?= $v('name') ?>"></div>
<div><label>Category</label><input name="category" required value="<?= $v('category') ?>"></div>
<div><label>Condition</label><select name="condition"><?php foreach (['good', 'fair', 'poor', 'damaged', 'obsolete'] as $c): ?><option <?= ($edit['condition'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
<div><label>Location</label><input name="location" required value="<?= $v('location') ?>"></div>
<div><label>Starting price (KES)</label><input name="starting_price" required inputmode="decimal" value="<?= $v('starting_price') ?>"></div></div>
<label>Description</label><textarea name="description" rows="3"><?= $v('description') ?></textarea>
<div class="row"><div><label>Image (JPEG, PNG, WebP; max 2 MB)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
<?php if ($edit && in_array($edit['status'], ['available', 'withdrawn'], true)): ?><div><label>Status</label><select name="status"><option value="available" <?= $edit['status'] === 'available' ? 'selected' : '' ?>>available</option><option value="withdrawn" <?= $edit['status'] === 'withdrawn' ? 'selected' : '' ?>>withdrawn</option></select></div><?php endif; ?></div>
<p><button><?= $edit ? 'Save changes' : 'Register asset' ?></button> <?php if ($edit): ?><a class="btn sec" href="/admin/assets.php">Cancel</a><?php endif; ?></p></form></div>
<form class="filters" method="get"><input name="q" placeholder="Search name, code, category" value="<?= e($q) ?>">
<select name="status"><option value="">All statuses</option><?php foreach (['available', 'auctioned', 'sold', 'withdrawn'] as $s): ?><option <?= $stf === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select>
<select name="sort"><option value="new">Newest</option><option value="name" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>>Name</option><option value="code" <?= ($_GET['sort'] ?? '') === 'code' ? 'selected' : '' ?>>Code</option><option value="price" <?= ($_GET['sort'] ?? '') === 'price' ? 'selected' : '' ?>>Price</option></select><button>Filter</button></form>
<div class="scroll"><table><tr><th>Code</th><th>Name</th><th>Category</th><th>Condition</th><th>Start price</th><th>Status</th><th></th></tr>
<?php foreach ($rows as $a): ?><tr><td><?= e($a['asset_code']) ?></td><td><?= e($a['name']) ?></td><td><?= e($a['category']) ?></td><td><?= e($a['condition']) ?></td><td><?= format_kes($a['starting_price']) ?></td><td><?= status_badge($a['status']) ?></td>
<td><a href="?edit=<?= (int) $a['id'] ?>">Edit</a> <form method="post" class="inline" onsubmit="return confirm('Delete this asset?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="link" style="color:#b42318">Delete</button></form></td></tr><?php endforeach; ?></table></div>
<?= pager($page, $total, $per) ?>
<?php page_foot();
