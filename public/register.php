<?php
require_once __DIR__ . '/../includes/layout.php';
start_session();
if (!empty($_SESSION['uid'])) redirect('/');
$errs = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    [$errs, $id] = register_bidder($_POST);
    if (!$errs) { flash('ok', 'Registration successful. You can now sign in.'); redirect('/login.php'); }
}
page_head('Register');
?>
<div class="card auth"><h1>Bidder registration</h1>
<?php foreach ($errs as $x): ?><div class="alert err"><?= e($x) ?></div><?php endforeach; ?>
<form method="post"><?= csrf_field() ?>
<label>Full name</label><input name="full_name" required value="<?= e($_POST['full_name'] ?? '') ?>">
<label>Email</label><input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
<label>Phone</label><input name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
<label>Password (minimum 10 characters)</label><input type="password" name="password" required minlength="10">
<label>Confirm password</label><input type="password" name="password_confirm" required>
<p><button>Create account</button></p></form><p><a href="/login.php">Back to sign in</a></p></div>
<?php page_foot();
