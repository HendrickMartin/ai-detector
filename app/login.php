<?php
session_start();
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $db = new mysqli("127.0.0.1", "app", "apppass", "aidetect");
  $u = trim($_POST['username'] ?? '');
  $p = $_POST['password'] ?? '';
  if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $u) || strlen($p) < 8) {
    $error = "Username must be 3-30 characters (letters, numbers or underscores) and the password at least 8 characters.";
  } elseif (($_POST['action'] ?? '') === 'register') {
    $h = password_hash($p, PASSWORD_DEFAULT);
    $st = $db->prepare("INSERT INTO users (username, password_hash) VALUES (?,?)");
    $st->bind_param("ss", $u, $h);
    try {
      $st->execute();
      session_regenerate_id(true);
      $_SESSION['uid'] = $db->insert_id;
      $_SESSION['user'] = $u;
      header("Location: index.php"); exit;
    } catch (mysqli_sql_exception $e) {
      $error = "That username is taken. Choose another, or log in if it is yours.";
    }
  } else {
    $st = $db->prepare("SELECT id, password_hash FROM users WHERE username=?");
    $st->bind_param("s", $u);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row && password_verify($p, $row['password_hash'])) {
      session_regenerate_id(true);
      $_SESSION['uid'] = $row['id'];
      $_SESSION['user'] = $u;
      header("Location: index.php"); exit;
    }
    $error = "Username or password is incorrect.";
  }
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in - AI Image Detector</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . "/style.css") ?>">
</head>
<body class="auth">
<main>
  <div class="card">
    <h1>AI Image Detector</h1>
    <p class="lede">Log in to check whether an image was made by AI.</p>
    <?php if ($error): ?><div class="alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <div class="field">
        <label for="username">Username</label>
        <input id="username" name="username" autocomplete="username" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        <span class="hint">3-30 characters: letters, numbers or underscores.</span>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <span class="hint">At least 8 characters.</span>
      </div>
      <div class="actions">
        <button class="btn" name="action" value="login">Log in</button>
        <button class="btn secondary" name="action" value="register">Create account</button>
      </div>
    </form>
  </div>
</main>
</body></html>
