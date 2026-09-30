<?php
require_once __DIR__ . '/../../includes/layout.php';
$u = require_login('bidder');
close_expired_auctions();
$db = db(); $q = trim($_GET['q'] ?? ''); $p = []; $w = "WHERE a.is_published AND a.status IN ('scheduled','active') AND a.end_date > now()";
if ($q !== '') { $w .= ' AND (s.name ILIKE ? OR s.asset_code ILIKE ? OR s.category ILIKE ?)'; $p = ["%$q%", "%$q%", "%$q%"]; }
$r = $db->prepare("SELECT a.id, a.current_bid, a.starting_bid, a.end_date, s.name, s.asset_code, s.category, s.condition FROM auctions a JOIN assets s ON s.id=a.asset_id $w ORDER BY a.end_date LIMIT 60");
$r->execute($p); $auctions = $r->fetchAll();
$m = $db->prepare("SELECT a.id auction_id, a.status astatus, a.current_bidder_id, a.winner_id, s.name, s.asset_code, max(b.amount) mine, a.current_bid FROM bids b JOIN auctions a ON a.id=b.auction_id JOIN assets s ON s.id=a.asset_id WHERE b.bidder_id=? AND b.status<>'rejected' GROUP BY a.id, s.name, s.asset_code ORDER BY a.id DESC LIMIT 50");
$m->execute([$u['id']]); $mine = $m->fetchAll();
page_head('Auctions', $u);
?>
<h1>Open auctions</h1><form class="filters" method="get"><input name="q" placeholder="Search asset, code, category" value="<?= e($q) ?>"><button>Search</button></form>
<div class="scroll"><table><tr><th>Asset</th><th>Category</th><th>Condition</th><th>Current bid</th><th>Closes (UTC)</th><th></th></tr>
<?php foreach ($auctions as $a): ?><tr><td><?= e($a['asset_code'] . ' ' . $a['name']) ?></td><td><?= e($a['category']) ?></td><td><?= e($a['condition']) ?></td><td><?= format_kes($a['current_bid'] ?? $a['starting_bid']) ?></td><td><?= e(substr($a['end_date'], 0, 16)) ?></td><td><a class="btn" href="/auctions/view.php?id=<?= (int) $a['id'] ?>">View</a></td></tr>
<?php endforeach; if (!$auctions): ?><tr><td colspan="6">No open auctions match your search.</td></tr><?php endif; ?></table></div>
<h2 style="margin-top:2rem">My bidding history</h2><div class="scroll"><table><tr><th>Asset</th><th>My highest bid</th><th>Current bid</th><th>Result</th><th></th></tr>
<?php foreach ($mine as $b):
  $res = $b['astatus'] === 'closed' ? ((int) $b['winner_id'] === (int) $u['id'] ? 'Won' : 'Lost') : ((int) $b['current_bidder_id'] === (int) $u['id'] ? 'Leading' : 'Outbid'); ?>
<tr><td><?= e($b['asset_code'] . ' ' . $b['name']) ?></td><td><?= format_kes($b['mine']) ?></td><td><?= format_kes($b['current_bid']) ?></td><td><?= $res ?></td><td><a href="/auctions/view.php?id=<?= (int) $b['auction_id'] ?>">Open</a></td></tr>
<?php endforeach; if (!$mine): ?><tr><td colspan="5">You have not placed any bids yet.</td></tr><?php endif; ?></table></div>
<?php page_foot();
