<?php
require_once __DIR__ . '/../../includes/layout.php';
$u = require_login('admin');
$db = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int) ($_POST['id'] ?? 0); $to = ($_POST['to'] ?? '') === 'inactive' ? 'inactive' : 'active';
    if ($id === (int) $u['id']) flash('err', 'You cannot change your own account status.');
    else { $db->prepare('UPDATE users SET status=?, updated_at=now() WHERE id=?')->execute([$to, $id]); audit((int) $u['id'], 'User Status Changed', 'user', $id, "Set to $to"); flash('ok', 'Account status updated.'); }
    redirect('/admin/bidders.php');
}
$q = trim($_GET['q'] ?? ''); $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 20; $p = []; $w = "WHERE role='bidder'";
if ($q !== '') { $w .= ' AND (full_name ILIKE ? OR email ILIKE ?)'; $p = ["%$q%", "%$q%"]; }
$t = $db->prepare("SELECT count(*) FROM users $w"); $t->execute($p); $total = (int) $t->fetchColumn();
$r = $db->prepare("SELECT u.*, (SELECT count(*) FROM bids b WHERE b.bidder_id=u.id) nbids FROM users u $w ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $r->execute($p);
page_head('Bidders', $u);
?>
<h1>Bidders</h1><form class="filters" method="get"><input name="q" placeholder="Name or email" value="<?= e($q) ?>"><button>Search</button></form>
<div class="scroll"><table><tr><th>Name</th><th>Email</th><th>Phone</th><th>Bids</th><th>Last login</th><th>Status</th><th></th></tr>
<?php foreach ($r as $b): ?><tr><td><?= e($b['full_name']) ?></td><td><?= e($b['email']) ?></td><td><?= e($b['phone']) ?></td><td><?= (int) $b['nbids'] ?></td><td><?= e(substr((string) $b['last_login'], 0, 16)) ?></td><td><?= status_badge($b['status']) ?></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="to" value="<?= $b['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="link" style="color:#1b3a6b"><?= $b['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?></table></div>
<?= pager($page, $total, $per) ?>
<?php page_foot();
