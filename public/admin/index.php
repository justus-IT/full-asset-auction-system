<?php
require_once __DIR__ . '/../../includes/layout.php';
$u = require_login('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    flash('ok', close_expired_auctions((int) $u['id']) . ' expired auction(s) finalized.');
    redirect('/admin/');
}
$s = db()->query("SELECT
 (SELECT count(*) FROM assets) assets, (SELECT count(*) FROM assets WHERE status='available') avail,
 (SELECT count(*) FROM auctions WHERE is_published AND status IN ('scheduled','active') AND end_date>now()) live,
 (SELECT count(*) FROM auctions WHERE status='closed') closed, (SELECT count(*) FROM users WHERE role='bidder') bidders,
 (SELECT count(*) FROM bids) bids,
 (SELECT count(*) FROM auctions WHERE is_published AND status IN ('scheduled','active') AND end_date<=now()) pending")->fetch();
$logs = db()->query("SELECT l.*, u.full_name FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 10")->fetchAll();
page_head('Dashboard', $u);
$labels = ['assets' => 'Total assets', 'avail' => 'Available assets', 'live' => 'Active auctions', 'closed' => 'Closed auctions',
           'bidders' => 'Registered bidders', 'bids' => 'Total bids', 'pending' => 'Awaiting closure'];
?>
<h1>Dashboard</h1><div class="grid"><?php foreach ($labels as $k => $l): ?><div class="card stat"><b><?= (int) $s[$k] ?></b><?= $l ?></div><?php endforeach; ?></div>
<form method="post" class="card"><?= csrf_field() ?><button>Finalize expired auctions</button> <small>Closes auctions past their end time, selects winners and sends notifications.</small></form>
<h2>Recent activity</h2><div class="scroll"><table><tr><th>Time (UTC)</th><th>User</th><th>Action</th><th>Details</th></tr>
<?php foreach ($logs as $l): ?><tr><td><?= e(substr($l['created_at'], 0, 19)) ?></td><td><?= e($l['full_name'] ?? 'System') ?></td><td><?= e($l['action']) ?></td><td><?= e($l['description']) ?></td></tr><?php endforeach; ?></table></div>
<?php page_foot();
