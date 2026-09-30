<?php
require_once __DIR__ . '/../../includes/functions.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$u = current_user();
if (!$u) { http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'Please sign in to continue.'])); }
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$res = ['ok' => true];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if ($u['role'] !== 'bidder') { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'You are not authorized to perform this action.'])); }
    close_expired_auctions();
    $res = place_bid($id, (int) $u['id'], (string) ($_POST['amount'] ?? ''));
}
$s = db()->prepare("SELECT status, is_published, starting_bid, current_bid, extract(epoch FROM end_date) end_ts, extract(epoch FROM now()) now_ts,
  (SELECT count(*) FROM bids WHERE auction_id=a.id) nbids FROM auctions a WHERE id=? AND (is_published OR ?::boolean)");
$s->execute([$id, $u['role'] === 'admin' ? 'true' : 'false']);
if ($a = $s->fetch()) {
    $res += ['state' => [
        'status' => $a['status'], 'ended' => (float) $a['end_ts'] <= (float) $a['now_ts'] || $a['status'] === 'closed',
        'current' => $a['current_bid'] !== null ? format_kes($a['current_bid']) : 'No bids yet',
        'min' => number_format($a['current_bid'] === null ? (float) $a['starting_bid'] : (float) $a['current_bid'] + 0.01, 2),
        'bids' => (int) $a['nbids'], 'now' => (float) $a['now_ts'],
    ]];
}
echo json_encode($res);
