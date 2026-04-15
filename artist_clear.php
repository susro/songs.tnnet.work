<?php
require_once 'config.php';

$artist_id = intval($_REQUEST['artist_id'] ?? 0);
if ($artist_id <= 0) {
    die("artist_id が指定されていません");
}

$stmt = $pdo->prepare("SELECT name FROM artists WHERE id = ?");
$stmt->execute([$artist_id]);
$artist_name = $stmt->fetchColumn();
if (!$artist_name) {
    die("アーティストが見つかりません");
}

$message = '';
$messageType = '';
$deletedCount = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_clear') {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM songs WHERE artist_id = ?");
    $countStmt->execute([$artist_id]);
    $songCount = (int)$countStmt->fetchColumn();

    $deleteStmt = $pdo->prepare("DELETE FROM songs WHERE artist_id = ?");
    $deleteStmt->execute([$artist_id]);
    $deletedCount = $deleteStmt->rowCount();

    $message = sprintf('アーティスト「%s」の曲を %d 件削除しました。', $artist_name, $deletedCount);
    $messageType = 'success';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>アーティスト曲の全削除</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
    <h1>アーティスト曲の全削除</h1>

    <nav class="top-nav">
        <a href="index.php">曲一覧</a>
        <a href="artists.php">アーティスト一覧</a>
        <a href="builder.php">曲を増やす！</a>
        <a href="import_history.php">取り込み履歴</a>
    </nav>

    <?php if ($message): ?>
        <div class="success-box"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="panel-card">
        <h2>アーティスト: <?= htmlspecialchars($artist_name, ENT_QUOTES, 'UTF-8') ?></h2>
        <p>この操作は、このアーティストに紐づく全曲をデータベースから削除します。</p>
        <?php if ($deletedCount === null): ?>
            <form method="post" onsubmit="return confirm('本当にこのアーティストの曲を全て削除しますか？ この操作は元に戻せません。');">
                <input type="hidden" name="action" value="confirm_clear">
                <button type="submit" class="launch-button">全曲を削除する</button>
                <a class="link-button" href="artists.php">戻る</a>
            </form>
        <?php else: ?>
            <p>削除件数: <?= (int)$deletedCount ?> 件</p>
            <a class="link-button" href="artists.php">アーティスト一覧へ戻る</a>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
  var theme = localStorage.getItem('songsTheme') || 'theme-neon';
  document.body.classList.add(theme);
})();
</script>
</body>
</html>
