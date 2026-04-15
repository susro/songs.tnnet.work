<?php
require_once 'config.php';
require_once 'fetch_helpers.php';
session_start();

$artist_id = intval($_GET['artist_id'] ?? 0);
if ($artist_id <= 0) {
    die("artist_id が指定されていません");
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
$message = '';
$messageType = '';

if ($action === 'commit_fetch') {
    $candidates = $_SESSION['builder_fetch_candidates'] ?? [];
    if (empty($candidates) || !isset($candidates[$artist_id])) {
        $message = '取り込み候補が見つかりません。まずは候補の再取得をしてください。';
        $messageType = 'error';
    } else {
        $commitResult = commitCandidateImport($pdo, [$candidates[$artist_id]]);
        unset($_SESSION['builder_fetch_candidates'][$artist_id]);
        if (empty($_SESSION['builder_fetch_candidates'])) {
            unset($_SESSION['builder_fetch_candidates']);
        }
        $message = sprintf('取り込み完了: %d 曲を登録しました。', $commitResult['inserted']);
        $messageType = 'success';
    }
}

if ($action === 'clear_fetch_session') {
    unset($_SESSION['builder_fetch_candidates'][$artist_id]);
    if (empty($_SESSION['builder_fetch_candidates'])) {
        unset($_SESSION['builder_fetch_candidates']);
    }
    $message = '候補をクリアしました。';
    $messageType = 'success';
}

$candidate = fetchCandidateSongsForArtist($pdo, $artist_id);
if (!empty($candidate['error'])) {
    $message = $candidate['error'];
    $messageType = 'error';
}

if (!empty($candidate['candidates'])) {
    $_SESSION['builder_fetch_candidates'] = [
        $artist_id => $candidate,
    ];
}

$artistName = htmlspecialchars($candidate['artist_name'], ENT_QUOTES, 'UTF-8');
$candidateCount = count($candidate['candidates']);
$existingCount = $candidate['existing'];
$apiTermCount = $candidate['api_term_count'];
$hasCandidates = $candidateCount > 0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fetch: <?= $artistName ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
    <h1>Fetch: <?= $artistName ?></h1>

    <nav class="top-nav">
        <a href="index.php">曲一覧</a>
        <a href="artists.php">アーティスト一覧</a>
        <a href="builder.php">曲を増やす！</a>
        <a href="import_history.php">取り込み履歴</a>
    </nav>

    <?php if ($message): ?>
        <div class="<?php echo $messageType === 'success' ? 'success-box' : 'error-box'; ?>">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <section class="panel-card">
        <h2>候補確認</h2>
        <p>アーティスト: <strong><?= $artistName ?></strong></p>
        <p>既存登録曲: <?= (int)$existingCount ?> 曲</p>
        <p>候補検索語数: <?= (int)$apiTermCount ?> 件</p>
        <p>候補曲数: <?= (int)$candidateCount ?> 曲</p>

        <?php if (!$hasCandidates): ?>
            <p>候補が見つかりませんでした。別の候補語や後で再度試してください。</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="commit_fetch">
                <button type="submit" class="launch-button">このまま一括取込み</button>
                <a class="link-button" href="builder_select.php">選択取込みへ進む</a>
                <button type="submit" name="action" value="clear_fetch_session" class="link-button">候補をクリア</button>
            </form>
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
