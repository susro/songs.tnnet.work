<?php
require_once 'config.php';
require_once 'auth.php';

/* ── すでにログイン済みならホームへ ── */
if (current_user()) {
    header('Location: index.php');
    exit;
}

$next      = preg_replace('/[^\/\w\-\.\?=&%]/', '', $_GET['next'] ?? 'index.php');
$preCode   = preg_replace('/[^\d]/', '', $_GET['code'] ?? '');
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['invite_code'] ?? '');
    $name = mb_substr(trim($_POST['name'] ?? ''), 0, 50);

    if ($code === '' || $name === '') {
        $error = '招待コードとニックネームを入力してください';
    } else {
        $user = login_with_invite($pdo, $code, $name);
        if (!$user) {
            $error = '招待コードが正しくありません';
        } else {
            header('Location: ' . $next);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>参加登録 – Songs.TNNET</title>
<link rel="stylesheet" href="assets/app.css">
<style>
  .register-wrap {
    min-height: 100dvh;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    padding: 24px 20px;
    background: var(--bg);
  }
  .register-card {
    width: 100%; max-width: 380px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 32px 28px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  }
  .register-logo {
    text-align: center;
    margin-bottom: 24px;
  }
  .register-logo-mark {
    display: inline-flex; align-items: center; justify-content: center;
    width: 56px; height: 56px;
    background: linear-gradient(135deg, var(--blue) 0%, #4488ee 100%);
    border-radius: 16px;
    margin-bottom: 10px;
  }
  .register-logo-mark svg { width: 28px; height: 28px; color: #fff; }
  .register-title {
    font-size: 20px; font-weight: 800;
    color: var(--text);
    margin: 0 0 4px;
    text-align: center;
  }
  .register-sub {
    font-size: 12px; color: var(--text-muted);
    text-align: center; margin: 0 0 28px;
  }
  .reg-label {
    display: block;
    font-size: 12px; font-weight: 700;
    color: var(--text-sub);
    margin-bottom: 5px;
    letter-spacing: 0.04em;
  }
  .reg-input {
    display: block; width: 100%;
    height: 44px;
    border: 1.5px solid var(--border-dark);
    border-radius: 8px;
    padding: 0 14px;
    font-size: 15px;
    background: var(--bg);
    color: var(--text);
    outline: none;
    margin-bottom: 16px;
  }
  .reg-input:focus { border-color: var(--blue); background: #fff; box-shadow: 0 0 0 3px rgba(0,85,200,0.1); }
  .reg-btn {
    display: block; width: 100%;
    height: 46px;
    background: var(--blue); color: #fff;
    border: none; border-radius: 8px;
    font-size: 15px; font-weight: 800;
    cursor: pointer;
    margin-top: 4px;
  }
  .reg-btn:hover { background: var(--blue-dark); }
  .reg-error {
    background: var(--red-bg);
    border: 1px solid #f0a0b0;
    border-radius: 6px;
    padding: 10px 12px;
    margin-bottom: 16px;
    font-size: 13px;
    color: var(--red);
  }
</style>
</head>
<body>
<div class="register-wrap">
  <div class="register-card">
    <div class="register-logo">
      <div class="register-logo-mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>
        </svg>
      </div>
    </div>
    <h1 class="register-title">Songs.TNNET</h1>
    <p class="register-sub">招待コードでログイン</p>

    <?php if ($error): ?>
      <div class="reg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

      <label class="reg-label">ニックネーム</label>
      <input type="text" name="name" class="reg-input"
             placeholder="自分の名前を入力" maxlength="50" required
             autocomplete="username"
             value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

      <label class="reg-label">招待コード</label>
      <input type="password" name="invite_code" class="reg-input"
             placeholder="招待コード" maxlength="64" required
             autocomplete="current-password"
             value="<?= htmlspecialchars($preCode ?: ($_POST['invite_code'] ?? '')) ?>"
             <?= $preCode ? 'readonly style="background:#f0f3f8;color:var(--text-muted)"' : '' ?>>

      <button type="submit" class="reg-btn">参加する</button>
    </form>
  </div>
</div>
</body>
</html>
