<?php
require_once __DIR__ . '/../../includes/layout.php';
$u = require_login();
close_expired_auctions();
$st = db()->prepare("SELECT a.*, s.name, s.asset_code, s.description, s.category, s.condition, s.location, s.image_url,
  extract(epoch FROM a.end_date) end_ts, extract(epoch FROM now()) now_ts, (SELECT count(*) FROM bids b WHERE b.auction_id=a.id) nbids
  FROM auctions a JOIN assets s ON s.id=a.asset_id WHERE a.id=?");
$st->execute([(int) ($_GET['id'] ?? 0)]);
$a = $st->fetch();
if (!$a || (!$a['is_published'] && $u['role'] !== 'admin')) { http_response_code(404); page_head('Not found', $u); echo '<p>Auction not found.</p>'; page_foot(); exit; }
$open = $a['is_published'] && in_array($a['status'], ['scheduled', 'active'], true) && (float) $a['end_ts'] > (float) $a['now_ts'];
$min = $a['current_bid'] === null ? (float) $a['starting_bid'] : (float) $a['current_bid'] + 0.01;
page_head($a['name'], $u);
?>
<h1><?= e($a['name']) ?> <small>#<?= e($a['asset_code']) ?></small></h1>
<div class="detail" id="auction" data-id="<?= (int) $a['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" data-end="<?= e((string) $a['end_ts']) ?>" data-now="<?= e((string) $a['now_ts']) ?>">
<div><?php if ($a['image_url']): ?><img src="<?= e($a['image_url']) ?>" alt="<?= e($a['name']) ?>"><?php else: ?><div class="card">No image available</div><?php endif; ?>
<div class="card"><p><?= nl2br(e($a['description'])) ?></p><p>Category: <?= e($a['category']) ?><br>Condition: <?= e($a['condition']) ?><br>Location: <?= e($a['location']) ?></p></div></div>
<div><div class="card"><div>Starting price: <?= format_kes($a['starting_bid']) ?></div>
<div>Current highest bid</div><div class="big" id="cur"><?= $a['current_bid'] !== null ? format_kes($a['current_bid']) : 'No bids yet' ?></div>
<p>Bids: <span id="nb"><?= (int) $a['nbids'] ?></span> &middot; Status: <span id="stat"><?= status_badge($open ? 'active' : $a['status']) ?></span></p>
<p>Closes: <?= e(substr($a['end_date'], 0, 16)) ?> UTC</p>
<div class="cd" id="cd"><div><b id="d">--</b>Days</div><div><b id="h">--</b>Hours</div><div><b id="m">--</b>Minutes</div><div><b id="s">--</b>Seconds</div></div></div>
<?php if ($a['status'] === 'closed'): ?><div class="card"><?php if ($a['winner_id']): ?>
Winning bid: <?= format_kes($a['winning_bid']) ?><br><?= (int) $a['winner_id'] === (int) $u['id'] ? '<b>Congratulations. You won this auction.</b> The institution will contact you regarding the next steps.' : 'This auction has closed.' ?>
<?php else: ?>This auction closed with no bids.<?php endif; ?></div>
<?php elseif ($u['role'] === 'bidder' && $open): ?>
<div class="card"><label for="amt">Your bid (KES) <small>Minimum: <span id="min"><?= number_format($min, 2) ?></span></small></label>
<input id="amt" inputmode="decimal" autocomplete="off"><p><button id="bid">Place bid</button></p><div id="msg" role="status"></div></div>
<?php endif; ?></div></div>
<script src="/js/auction.js"></script>
<?php page_foot();
