<?php
require_once 'config.php';
require_once 'fetch_helpers.php';
session_start();

$importCandidates = $_SESSION['builder_fetch_candidates'] ?? [];
$action = (string)($_POST['action'] ?? '');
$errorMessage = '';
$successMessage = '';
$selectedCount = 0;

if ($action === 'commit_selected') {
    $selectedKeys = array_map('strval', $_POST['selected_candidate_keys'] ?? []);
    if (!$importCandidates) {
        $errorMessage = '取り込み候補がありません。まずは Builder で候補を取得してください。';
    } elseif (!$selectedKeys) {
        $errorMessage = '取り込む曲を1件以上選択してください。';
    } else {
        $selectedCandidates = [];
        foreach ($importCandidates as $candidateGroup) {
            foreach ($candidateGroup['candidates'] as $candidate) {
                if (in_array($candidate['key'], $selectedKeys, true)) {
                    $artistId = $candidateGroup['artist_id'];
                    if (!isset($selectedCandidates[$artistId])) {
                        $selectedCandidates[$artistId] = [
                            'artist_id' => $artistId,
                            'artist_name' => $candidateGroup['artist_name'],
                            'candidates' => [],
                        ];
                    }
                    $selectedCandidates[$artistId]['candidates'][] = $candidate;
                }
            }
        }

        $selectedCount = 0;
        foreach ($selectedCandidates as $group) {
            $selectedCount += count($group['candidates']);
        }

        if ($selectedCount === 0) {
            $errorMessage = '選択された曲は候補一覧に見つかりませんでした。';
        } else {
            $commitResult = commitCandidateImport($pdo, $selectedCandidates);
            unset($_SESSION['builder_fetch_candidates']);
            $successMessage = sprintf('取り込み完了: %d 曲を登録しました。', $commitResult['inserted']);
        }
    }
}

$totalCandidates = 0;
foreach ($importCandidates as $candidateGroup) {
    $totalCandidates += count($candidateGroup['candidates']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>選択取込み</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
    <div class="page-head">
        <h1>選択取込み</h1>
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
        <a href="import_history.php">取り込み履歴</a>
    </nav>

    <?php if ($errorMessage): ?>
        <div class="error-box"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($successMessage): ?>
        <div class="success-box"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($successMessage || !$importCandidates): ?>
        <section class="panel-card">
            <p><a class="link-button" href="builder.php">Builder に戻る</a></p>
        </section>
    <?php else: ?>
        <section class="panel-card">
            <h2>候補一覧</h2>
            <p>候補アーティスト: <?= (int)count($importCandidates) ?>件</p>
            <p>候補曲数: <?= (int)$totalCandidates ?>曲</p>
            <form method="post">
                <input type="hidden" name="action" value="commit_selected">
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>選択</th>
                            <th>アーティスト</th>
                            <th>曲名</th>
                            <th>リリース年</th>
                            <th>検索語</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($importCandidates as $candidateGroup): ?>
                            <?php foreach ($candidateGroup['candidates'] as $candidate): ?>
                                <tr>
                                    <td><input type="checkbox" name="selected_candidate_keys[]" value="<?= htmlspecialchars($candidate['key'], ENT_QUOTES, 'UTF-8') ?>" checked></td>
                                    <td><?= htmlspecialchars($candidateGroup['artist_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($candidate['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $candidate['release_year'] !== null ? (int)$candidate['release_year'] : '-' ?></td>
                                    <td><?= htmlspecialchars($candidate['search_term'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="select-tools" style="margin-top: 16px; gap: 10px;">
                    <button type="submit" class="launch-button">選択した曲を取り込む</button>
                    <a class="link-button" href="builder.php">戻る</a>
                </div>
            </form>
        </section>
    <?php endif; ?>
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
