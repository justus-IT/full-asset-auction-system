<?php
declare(strict_types=1);
// CLI only. Schedule every minute: * * * * * php /path/to/institution-auction/cron/close_auctions.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
$n = close_expired_auctions(null);
echo date('c') . " closed=$n\n";
