<?php
require_once __DIR__ . '/../includes/layout.php';
start_session();
if (!empty($_SESSION['uid'])) redirect('/');
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $err = attempt_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
    if (!$err) redirect('/');
}
page_head('Login');
?>
<div class="card auth"><h1>Sign in</h1>
<?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Email</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
<label>Password</label><input type="password" name="password" required><p><button>Sign in</button></p></form>
<p>No account? <a href="/register.php">Register as a bidder</a></p></div>
<?php page_foot();
