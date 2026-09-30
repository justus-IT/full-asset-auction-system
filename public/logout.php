<?php
require_once __DIR__ . '/../includes/functions.php';
start_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!empty($_SESSION['uid'])) audit((int) $_SESSION['uid'], 'Logout', 'user', (int) $_SESSION['uid'], 'User logged out');
    $_SESSION = []; session_destroy();
}
redirect('/login.php');
