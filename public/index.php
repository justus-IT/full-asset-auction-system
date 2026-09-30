<?php
require_once __DIR__ . '/../includes/functions.php';
$u = current_user();
redirect(!$u ? '/login.php' : ($u['role'] === 'admin' ? '/admin/' : '/bidder/'));
