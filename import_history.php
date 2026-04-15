<?php
require_once 'config.php';
require_once 'fetch_helpers.php';
session_start();

ensureImportHistoryTables($pdo);
$action = (string)($_POST['action'] ?? '');
$message = '';
$messageType = '';

if ($action === 'undo_batch') {
    $batchId = intval($_POST['batch_id'] ?? 0);
    if ($batchId <= 0) {
        $message = '無効な履歴が指定されました。';
        $messageType = 'error';
    } else {
        $result = undoImportBatch($pdo, $batchId);
        if ($result['success']) {
            $message = sprintf('取り消し完了: %d 曲を削除しました。', $result['deleted']);
            $messageType = 'success';
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
}

$stmt = $pdo->query("SELECT id, created_at, artist_count, song_count, status FROM import_fetch_batches ORDER BY created_at DESC LIMIT 100");
$batches = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>取り込み履歴</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
    <div class="page-head">
        <h1>取り込み履歴</h1>
        <div class="theme-switch">
            <span>テーマ:</span>
            <select id="theme-select" class="theme-select">
                <option value="theme-neon">ネオン</option>
                <option value="theme-sunset">サンセット</option>
                <option value="theme-mint">ミント</option>
                <option value="theme-cream-a">クリームA</option>
                <option value="theme-cream-b">クリームB</option>
                <option value="theme-cream-c">クリームC</option>
            </select>
        </div>
    </div>
    <nav class="top-nav">
        <a href="index.php">トップ</a>
        <a href="artists.php">アーティスト一覧</a>
        <a href="builder.php">曲を増やす！</a>
        <a href="import_history.php" class="is-active">取り込み履歴</a>
    </nav>

    <?php if ($message): ?>
        <div class="<?php echo $messageType === 'success' ? 'success-box' : 'error-box'; ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <section class="panel-card">
        <h2>最新の取り込み</h2>
        <?php if (!$batches): ?>
            <p>まだ取り込み履歴がありません。</p>
            <p><a class="link-button" href="builder.php">Builder に戻る</a></p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>日時</th>
                        <th>アーティスト数</th>
                        <th>曲数</th>
                        <th>状態</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><?= (int)$batch['id'] ?></td>
                            <td><?= htmlspecialchars($batch['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$batch['artist_count'] ?></td>
                            <td><?= (int)$batch['song_count'] ?></td>
                            <td><?= htmlspecialchars($batch['status'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($batch['status'] === 'completed'): ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="undo_batch">
                                        <input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
                                        <button type="submit" class="link-button">取り消す</button>
                                    </form>
                                <?php else: ?>
                                    取り消済み
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
var themeSelect = document.getElementById('theme-select');
themeSelect.addEventListener('change', function () {
    var theme = themeSelect.value;
    document.body.classList.remove('theme-neon', 'theme-sunset', 'theme-mint', 'theme-cream-a', 'theme-cream-b', 'theme-cream-c');
    document.body.classList.add(theme);
    localStorage.setItem('songsTheme', theme);
});
var savedTheme = localStorage.getItem('songsTheme') || 'theme-neon';
document.body.classList.remove('theme-neon', 'theme-sunset', 'theme-mint', 'theme-cream-a', 'theme-cream-b', 'theme-cream-c');
document.body.classList.add(savedTheme);
themeSelect.value = savedTheme;
</script>
</body>
</html>
