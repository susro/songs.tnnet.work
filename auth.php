<?php
/* ============================================
   auth.php – セッション管理・認証ヘルパー
   config.php の後に require する
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 90, // 90日
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ── 現在のユーザーを返す。未ログインなら null ── */
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/* ── ログイン済みでなければ register.php へ ── */
function require_login(): array {
    $u = current_user();
    if (!$u) {
        $dest = urlencode($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: register.php?next=' . $dest);
        exit;
    }
    return $u;
}

/* ── 管理者でなければ 403 ── */
function require_admin(): array {
    $u = require_login();
    if (empty($u['is_admin'])) {
        http_response_code(403);
        exit('権限がありません');
    }
    return $u;
}

/* ── 招待コードで登録 or ログイン ── */
function login_with_invite(PDO $pdo, string $code, string $name): ?array {
    // 既存ユーザー（コード一致）
    $stmt = $pdo->prepare("SELECT * FROM users WHERE invite_code = ?");
    $stmt->execute([$code]);
    $user = $stmt->fetch();

    if (!$user) return null; // コード無効

    // 名前を更新（初回登録 or 変更）
    $name = mb_substr(trim($name), 0, 50);
    if ($name !== '') {
        $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$name, $user['id']]);
        $user['name'] = $name;
    }

    // 初回登録時：デフォルト動的リストを作成
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM songlists WHERE user_id = ?");
    $stmt->execute([$user['id']]);
    if ((int)$stmt->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO songlists (name, user_id, list_type, filter_config) VALUES (?, ?, 'dynamic', ?)");
        foreach ([
            ['お気に入り', '{"personal_tag":"お気に入り★"}'],
            ['練習中',     '{"personal_tag":"練習中"}'],
            ['My定番',     '{"personal_tag":"My定番"}'],
        ] as [$listName, $config]) {
            $ins->execute([$listName, $user['id'], $config]);
        }
    }

    $_SESSION['user'] = [
        'id'       => (int)$user['id'],
        'name'     => $user['name'],
        'is_admin' => (bool)$user['is_admin'],
    ];
    return $_SESSION['user'];
}

/* ── ログアウト ── */
function logout(): void {
    $_SESSION = [];
    session_destroy();
}
