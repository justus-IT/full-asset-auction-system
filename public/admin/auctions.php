<?php
require_once __DIR__ . '/../../includes/layout.php';
$u = require_login('admin');
$db = db();
const TZ = 'Africa/Nairobi';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['action'] ?? ''; $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'create') {
        $aid = (int) $_POST['asset_id']; $bid = $_POST['starting_bid'] ?? ''; $publish = isset($_POST['publish']);
        $ts = strtotime($_POST['start_date'] ?? ''); $te = strtotime($_POST['end_date'] ?? '');
        $err = [];
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $bid) || (float) $bid <= 0) $err[] = 'Starting bid must be greater than zero.';
        if (!$ts || !$te) $err[] = 'Enter valid start and end dates.'; elseif ($te <= $ts) $err[] = 'End date must be after the start date.';
        $a = $db->prepare("SELECT status FROM assets WHERE id = ?"); $a->execute([$aid]);
        if ($a->fetchColumn() !== 'available') $err[] = 'The selected asset is not available for auction.';
        if (!$err && $publish) {
            $chk = $db->prepare("SELECT (?::timestamp AT TIME ZONE ?) > now()"); $chk->execute([$_POST['end_date'], TZ]);
            if (!$chk->fetchColumn()) $err[] = 'The end date must be in the future.';
        }
        if ($err) { flash('err', implode(' ', $err)); redirect('/admin/auctions.php'); }
        try {
            $db->beginTransaction();
            $st = $db->prepare("INSERT INTO auctions (asset_id,start_date,end_date,starting_bid,status,is_published,created_by)
              VALUES (?, ?::timestamp AT TIME ZONE ?, ?::timestamp AT TIME ZONE ?, ?, ?, ?, ?) RETURNING id");
            $st->execute([$aid, $_POST['start_date'], TZ, $_POST['end_date'], TZ, $bid, $publish ? 'scheduled' : 'draft', $publish ? 'true' : 'false', $u['id']]);
            $nid = (int) $st->fetchColumn();
            if ($publish) $db->prepare("UPDATE assets SET status='auctioned', updated_at=now() WHERE id=?")->execute([$aid]);
            $db->commit();
            audit((int) $u['id'], $publish ? 'Auction Published' : 'Auction Created', 'auction', $nid, "Asset $aid, starting bid KES $bid");
            flash('ok', $publish ? 'Auction created and published.' : 'Auction saved as draft.');
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('err', $e->getCode() === '23505' ? 'This asset already has a live auction.' : 'The auction could not be saved.');
        }
    } elseif ($act === 'publish') {
        $r = $db->prepare("UPDATE auctions SET status='scheduled', is_published=true, updated_at=now() WHERE id=? AND status='draft' AND end_date>now() RETURNING asset_id");
        $r->execute([$id]);
        if ($aid = $r->fetchColumn()) { $db->prepare("UPDATE assets SET status='auctioned', updated_at=now() WHERE id=?")->execute([$aid]); audit((int) $u['id'], 'Auction Published', 'auction', $id); flash('ok', 'Auction published.'); }
        else flash('err', 'Only draft auctions with a future end date can be published.');
    } elseif ($act === 'cancel') {
        $r = $db->prepare("UPDATE auctions SET status='cancelled', updated_at=now() WHERE id=? AND status IN ('draft','scheduled','active') RETURNING asset_id");
        $r->execute([$id]);
        if ($aid = $r->fetchColumn()) {
            $db->prepare("UPDATE bids SET status='rejected' WHERE auction_id=? AND status IN ('valid','winning','outbid')")->execute([$id]);
            $db->prepare("UPDATE assets SET status='available', updated_at=now() WHERE id=?")->execute([$aid]);
            audit((int) $u['id'], 'Auction Cancelled', 'auction', $id); flash('ok', 'Auction cancelled.');
        } else flash('err', 'This auction cannot be cancelled.');
    }
    redirect('/admin/auctions.php');
}
$sf = $_GET['status'] ?? ''; $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 15;
$where = 'WHERE 1=1'; $p = [];
if (in_array($sf, ['draft', 'scheduled', 'active', 'closed', 'cancelled'], true)) { $where .= ' AND a.status = ?'; $p[] = $sf; }
if (($q = trim($_GET['q'] ?? '')) !== '') { $where .= ' AND (s.name ILIKE ? OR s.asset_code ILIKE ?)'; array_push($p, "%$q%", "%$q%"); }
$t = $db->prepare("SELECT count(*) FROM auctions a JOIN assets s ON s.id=a.asset_id $where"); $t->execute($p); $total = (int) $t->fetchColumn();
$r = $db->prepare("SELECT a.*, s.name, s.asset_code, w.full_name winner,
  to_char(a.start_date AT TIME ZONE '" . TZ . "','YYYY-MM-DD HH24:MI') sd, to_char(a.end_date AT TIME ZONE '" . TZ . "','YYYY-MM-DD HH24:MI') ed,
  (a.end_date <= now()) ended, (SELECT count(*) FROM bids b WHERE b.auction_id=a.id) nbids
  FROM auctions a JOIN assets s ON s.id=a.asset_id LEFT JOIN users w ON w.id=a.winner_id $where ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$r->execute($p); $rows = $r->fetchAll();
$avail = $db->query("SELECT id, asset_code, name, starting_price FROM assets WHERE status='available' ORDER BY name")->fetchAll();
page_head('Auctions', $u);
?>
<h1>Auctions</h1>
<div class="card"><h2>Create auction</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<div class="row"><div><label>Asset</label><select name="asset_id" required><?php foreach ($avail as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['asset_code'] . ' - ' . $a['name']) ?></option><?php endforeach; ?></select></div>
<div><label>Starting bid (KES)</label><input name="starting_bid" required inputmode="decimal"></div>
<div><label>Start (East Africa Time)</label><input type="datetime-local" name="start_date" required></div>
<div><label>End (East Africa Time)</label><input type="datetime-local" name="end_date" required></div></div>
<p><button name="save" value="1">Save as draft</button> <button name="publish" value="1" class="sec">Publish now</button></p></form></div>
<form class="filters" method="get"><input name="q" placeholder="Search asset" value="<?= e($q) ?>"><select name="status"><option value="">All statuses</option><?php foreach (['draft', 'scheduled', 'active', 'closed', 'cancelled'] as $s): ?><option <?= $sf === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><button>Filter</button></form>
<div class="scroll"><table><tr><th>ID</th><th>Asset</th><th>Start (EAT)</th><th>End (EAT)</th><th>Starting</th><th>Current / winning</th><th>Bids</th><th>Status</th><th>Winner</th><th></th></tr>
<?php foreach ($rows as $a): ?><tr><td><?= (int) $a['id'] ?></td><td><?= e($a['asset_code'] . ' ' . $a['name']) ?></td><td><?= e($a['sd']) ?></td><td><?= e($a['ed']) ?></td><td><?= format_kes($a['starting_bid']) ?></td>
<td><?= $a['current_bid'] !== null ? format_kes($a['current_bid']) : '-' ?></td><td><?= (int) $a['nbids'] ?></td>
<td><?= status_badge($a['status']) ?><?= $a['ended'] && in_array($a['status'], ['scheduled', 'active'], true) ? ' <small>awaiting closure</small>' : '' ?></td><td><?= e($a['winner'] ?? '-') ?></td>
<td><?php if ($a['status'] === 'draft'): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="link" style="color:#1b3a6b">Publish</button></form> <?php endif; ?>
<?php if (in_array($a['status'], ['draft', 'scheduled', 'active'], true)): ?><form method="post" class="inline" onsubmit="return confirm('Cancel this auction?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="link" style="color:#b42318">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></table></div>
<?= pager($page, $total, $per) ?>
<?php page_foot();
