<?php
session_start();
if (empty($_SESSION['uid'])) { header("Location: login.php"); exit; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$uid = (int)$_SESSION['uid'];
const MAX_BYTES = 3 * 1024 * 1024;
const MAX_PER_MIN = 5;

function csrf_ok() { return hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? ''); }
function band($ai) {
  if ($ai >= 60) return ['Likely AI-generated', 'ai'];
  if ($ai <= 40) return ['Likely real', 'real'];
  return ['Inconclusive', 'unsure'];
}

$db = new mysqli("127.0.0.1", "app", "apppass", "aidetect");
$result = null; $error = null; $shown = null; $aiPct = null;

// Delete one of this user's results
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  if (csrf_ok()) {
    $id = (int)($_POST['id'] ?? 0);
    $st = $db->prepare("SELECT filename FROM results WHERE id=? AND user_id=?");
    $st->bind_param("ii", $id, $uid); $st->execute(); $st->bind_result($fn);
    if ($st->fetch()) {
      $st->close();
      @unlink(__DIR__ . "/uploads/" . basename($fn));
      $st = $db->prepare("DELETE FROM results WHERE id=? AND user_id=?");
      $st->bind_param("ii", $id, $uid); $st->execute();
    }
  }
  header("Location: index.php"); exit;
}

// Upload and analyze
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
  $f = $_FILES['image'];
  $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

  $st = $db->prepare("SELECT COUNT(*) FROM results WHERE user_id=? AND created_at > NOW() - INTERVAL 1 MINUTE");
  $st->bind_param("i", $uid); $st->execute(); $st->bind_result($recent); $st->fetch(); $st->close();

  if (!csrf_ok()) $error = "Your session expired. Reload the page and try again.";
  elseif ($recent >= MAX_PER_MIN) $error = "Too many requests. Wait a minute and try again.";
  elseif ($f['error'] !== UPLOAD_ERR_OK) $error = "Upload failed. The file may be too large or damaged.";
  elseif ($f['size'] > MAX_BYTES) $error = "That file is over 3 MB. Choose a smaller image.";
  else {
    $mime = mime_content_type($f['tmp_name']);
    if (!isset($types[$mime])) $error = "Only JPG, PNG or WEBP images are allowed.";
    else {
      $name = bin2hex(random_bytes(8)) . "." . $types[$mime];
      $path = __DIR__ . "/uploads/$name";
      move_uploaded_file($f['tmp_name'], $path);
      $out = shell_exec("python3 /var/www/detect.py " . escapeshellarg($path) . " 2>/dev/null");
      $result = json_decode($out, true);
      if (!$result) { $error = "The detector could not read that image. Try another one."; }
      else {
        $conf = (float)$result['confidence'];
        $aiPct = $result['verdict'] === 'AI-generated' ? $conf : 100 - $conf;
        $size = @getimagesize($path);
        $w = $size ? (int)$size[0] : null;
        $ht = $size ? (int)$size[1] : null;
        $orig = substr(basename($f['name']), 0, 255);
        $st = $db->prepare("INSERT INTO results (user_id, filename, original_name, width, height, ai_prob, verdict, confidence) VALUES (?,?,?,?,?,?,?,?)");
        $st->bind_param("issiidsd", $uid, $name, $orig, $w, $ht, $aiPct, $result['verdict'], $conf);
        $st->execute();
        $shown = $name;
      }
    }
  }
}

// History with filter tabs and filename search
$filter = $_GET['f'] ?? 'all';
if (!in_array($filter, ['all','ai','real','unsure'], true)) $filter = 'all';
$q = trim($_GET['q'] ?? '');
$sql = "SELECT id, filename, original_name, width, height, ai_prob, created_at FROM results WHERE user_id=?";
$bt = "i"; $args = [$uid];
if ($filter === 'ai') $sql .= " AND ai_prob >= 60";
elseif ($filter === 'real') $sql .= " AND ai_prob <= 40";
elseif ($filter === 'unsure') $sql .= " AND ai_prob > 40 AND ai_prob < 60";
if ($q !== '') { $sql .= " AND original_name LIKE ?"; $bt .= "s"; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
$sql .= " ORDER BY id DESC LIMIT 50";
$st = $db->prepare($sql);
$st->bind_param($bt, ...$args);
$st->execute();
$history = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();
$tabs = ['all'=>'All checks','ai'=>'AI-generated','real'=>'Real','unsure'=>'Inconclusive'];
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Image Detector</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . "/style.css") ?>">
</head>
<body>
<header class="topbar">
  <span class="brand">AI Image Detector</span>
  <div class="who">
    <span>Signed in as <strong><?= htmlspecialchars($_SESSION['user']) ?></strong></span>
    <a href="logout.php">Log out</a>
  </div>
</header>
<main>
  <section class="card">
    <h1>Check an image</h1>
    <p class="lede">Upload a JPG, PNG or WEBP up to 3 MB. The detector estimates whether an AI image generator made it.</p>
    <?php if ($error): ?><div class="alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <label class="drop" id="drop">
        <img class="preview" id="preview" alt="Preview of the selected image">
        <strong id="dropTitle">Choose an image</strong>
        <span class="hint" id="dropHint">or drag it here</span>
        <input type="file" name="image" id="file" accept="image/jpeg,image/png,image/webp" required>
      </label>
      <div class="actions" style="margin-top:18px">
        <button class="btn" id="go" type="submit" disabled>Analyze image</button>
      </div>
      <p class="hint" id="wait" hidden>Analyzing. The first image can take up to 30 seconds while the model loads.</p>
    </form>
  </section>

<?php if ($result && !$error): [$label, $cls] = band($aiPct); ?>
  <section class="card result" aria-live="polite">
    <img src="uploads/<?= htmlspecialchars($shown) ?>" alt="The image you analyzed">
    <div class="verdict <?= $cls ?>">
      <h2><?= $label ?></h2>
      <p class="conf">AI probability: <?= round($aiPct, 1) ?>%</p>
      <div class="meter" role="img" aria-label="Estimated chance this image is AI-generated: <?= round($aiPct) ?> percent">
        <span class="marker" style="--pos:<?= round($aiPct, 1) ?>%"></span>
      </div>
      <div class="scale"><span>Real</span><span>AI-generated</span></div>
      <?php if ($cls === 'unsure'): ?>
        <p class="note">The detector is unsure about this one. Try a larger, less compressed version of the image.</p>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

  <section class="card history">
    <h2>Your checks</h2>
    <div class="toolbar">
      <nav class="tabs" aria-label="Filter results">
<?php foreach ($tabs as $k => $tl): ?>
        <a href="?f=<?= $k ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= $tl ?></a>
<?php endforeach; ?>
      </nav>
      <form class="search" method="get">
        <input type="hidden" name="f" value="<?= $filter ?>">
        <input type="search" name="q" placeholder="Search filename" aria-label="Search filename" value="<?= htmlspecialchars($q) ?>">
        <button class="btn secondary" type="submit">Search</button>
      </form>
    </div>
<?php if (!$history): ?>
    <p class="lede">No checks to show yet.</p>
<?php else: ?>
    <div class="tablewrap"><table>
      <thead><tr><th>Preview</th><th>Image name</th><th>Resolution</th><th>AI probability</th><th>Verdict</th><th>Checked (UTC)</th><th>Actions</th></tr></thead>
      <tbody>
<?php foreach ($history as $r): [$label, $cls] = band((float)$r['ai_prob']); ?>
        <tr>
          <td><img class="thumb" width="56" height="56" src="uploads/<?= htmlspecialchars($r['filename']) ?>" alt=""></td>
          <td class="name"><?= htmlspecialchars($r['original_name'] ?? $r['filename']) ?></td>
          <td><?= (int)$r['width'] ?> × <?= (int)$r['height'] ?></td>
          <td><?= round((float)$r['ai_prob'], 1) ?>%</td>
          <td><span class="badge <?= $cls ?>"><?= $label ?></span></td>
          <td class="when"><?= htmlspecialchars($r['created_at']) ?></td>
          <td>
            <div class="row-actions">
              <a class="btn secondary sm" href="uploads/<?= htmlspecialchars($r['filename']) ?>" target="_blank" rel="noopener">View</a>
              <form method="post" onsubmit="return confirm('Delete this result and its image?')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn danger sm" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table></div>
<?php endif; ?>
  </section>
</main>
<script>
const file=document.getElementById('file'), drop=document.getElementById('drop'),
      preview=document.getElementById('preview'), title=document.getElementById('dropTitle'),
      hint=document.getElementById('dropHint'), go=document.getElementById('go'),
      form=document.getElementById('form'), wait=document.getElementById('wait');
function show(){
  const f=file.files[0];
  if(!f){drop.classList.remove('has-file');go.disabled=true;return;}
  title.textContent=f.name;
  hint.textContent=(f.size/1048576).toFixed(1)+' MB. Click to choose a different image.';
  preview.src=URL.createObjectURL(f);
  drop.classList.add('has-file');
  go.disabled=false;
}
file.addEventListener('change',show);
['dragenter','dragover'].forEach(n=>drop.addEventListener(n,e=>{e.preventDefault();drop.classList.add('over');}));
['dragleave','drop'].forEach(n=>drop.addEventListener(n,e=>{e.preventDefault();drop.classList.remove('over');}));
drop.addEventListener('drop',e=>{if(e.dataTransfer.files.length){file.files=e.dataTransfer.files;show();}});
form.addEventListener('submit',()=>{go.disabled=true;go.textContent='Analyzing...';wait.hidden=false;});
</script>
</body></html>
