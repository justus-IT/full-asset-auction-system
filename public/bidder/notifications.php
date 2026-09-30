<?php
require_once __DIR__ . '/../../includes/layout.php';
$u = require_login('bidder');
if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(); db()->prepare('UPDATE notifications SET is_read=true WHERE user_id=? AND NOT is_read')->execute([$u['id']]); redirect('/bidder/notifications.php'); }
$r = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100'); $r->execute([$u['id']]); $rows = $r->fetchAll();
page_head('Notifications', $u);
?>
<h1>Notifications</h1><form method="post"><?= csrf_field() ?><button class="sec">Mark all as read</button></form><br>
<?php foreach ($rows as $n): ?><div class="card" style="<?= $n['is_read'] ? '' : 'border-left:4px solid #1b3a6b' ?>"><b><?= e($n['title']) ?></b><br><?= e($n['message']) ?><br><small><?= e(substr($n['created_at'], 0, 16)) ?> UTC</small></div>
<?php endforeach; if (!$rows) echo '<p>No notifications.</p>'; page_foot();
