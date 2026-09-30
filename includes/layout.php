<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

function page_head(string $title, ?array $u = null): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . e($title) . ' | Institution Asset Auction</title><link rel="stylesheet" href="/css/app.css"></head><body>'
        . '<header class="top"><a class="brand" href="/">Institution Asset Auction</a><nav>';
    if ($u) {
        $links = $u['role'] === 'admin'
            ? ['/admin/' => 'Dashboard', '/admin/assets.php' => 'Assets', '/admin/auctions.php' => 'Auctions', '/admin/bidders.php' => 'Bidders']
            : ['/bidder/' => 'Auctions', '/bidder/notifications.php' => 'Notifications'];
        foreach ($links as $h => $l) echo '<a href="' . $h . '">' . $l . '</a>';
        if ($u['role'] === 'bidder') {
            $st = db()->prepare('SELECT count(*) FROM notifications WHERE user_id = ? AND NOT is_read');
            $st->execute([$u['id']]);
            if ($n = (int) $st->fetchColumn()) echo '<span class="badge">' . $n . '</span>';
        }
        echo '<form method="post" action="/logout.php" class="inline">' . csrf_field()
            . '<button class="link">Logout (' . e($u['full_name']) . ')</button></form>';
    }
    echo '</nav></header><main class="wrap">';
    foreach (take_flash() as [$t, $m]) echo '<div class="alert ' . e($t) . '">' . e($m) . '</div>';
}

function page_foot(): void { echo '</main></body></html>'; }

function status_badge(string $s): string { return '<span class="st st-' . e($s) . '">' . e(ucfirst($s)) . '</span>'; }

function pager(int $page, int $total, int $per): string
{
    $q = $_GET; $out = '<div class="pager">';
    if ($page > 1) { $q['page'] = $page - 1; $out .= '<a href="?' . e(http_build_query($q)) . '">Previous</a>'; }
    $out .= '<span>Page ' . $page . ' of ' . max(1, (int) ceil($total / $per)) . '</span>';
    if ($page * $per < $total) { $q['page'] = $page + 1; $out .= '<a href="?' . e(http_build_query($q)) . '">Next</a>'; }
    return $out . '</div>';
}
