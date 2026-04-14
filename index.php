<?php
require_once 'config.php';

$keyword = trim((string)($_GET['q'] ?? ''));
$year = trim((string)($_GET['year'] ?? ''));
$tag = trim((string)($_GET['tag'] ?? ''));
$yearError = '';

function indexUrlWithout(string $key): string {
    $query = $_GET;
    unset($query[$key]);
    return 'index.php' . ($query ? '?' . http_build_query($query) : '');
}

$where = [];
$params = [];

if ($keyword !== '') {
    $where[] = '(songs.title LIKE ? OR artists.name LIKE ?)';
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

if ($year !== '') {
    if (ctype_digit($year)) {
        $where[] = 'songs.release_year = ?';
        $params[] = $year;
    } else {
        $yearError = '年は数字で入力してください（例: 1995）';
    }
}

$sql = "
SELECT DISTINCT songs.*, artists.name AS artist_name
FROM songs
LEFT JOIN artists ON songs.artist_id = artists.id
LEFT JOIN artist_tags ON artists.id = artist_tags.artist_id
LEFT JOIN tags ON artist_tags.tag_id = tags.id
";

if ($tag !== '') {
    $where[] = 'tags.name = ?';
    $params[] = $tag;
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY songs.id DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$songs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Song Book</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
    <h1>My Song Book</h1>

    <nav class="top-nav">
        <a href="index.php" class="is-active">曲一覧</a>
        <a href="artists.php">アーティスト一覧</a>
        <a href="add.php">曲を追加</a>
    </nav>

    <form method="get" class="search-form">
        <label>検索: <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="曲名／アーティスト"></label>
        <label>年: <input type="text" name="year" value="<?= htmlspecialchars($year) ?>" placeholder="例: 1995"></label>
        <label>タグ: <input type="text" name="tag" value="<?= htmlspecialchars($tag) ?>" placeholder="ロック／J-POP"></label>
        <button type="submit">検索</button>
        <a href="index.php" class="link-button">クリア</a>
    </form>

    <?php if ($keyword !== '' || $year !== '' || $tag !== ''): ?>
        <div class="active-filters">
            <span>現在の条件:</span>
            <?php if ($keyword !== ''): ?>
                <span class="chip">キーワード: <?= htmlspecialchars($keyword) ?> <a href="<?= htmlspecialchars(indexUrlWithout('q')) ?>">解除</a></span>
            <?php endif; ?>
            <?php if ($year !== ''): ?>
                <span class="chip">年: <?= htmlspecialchars($year) ?> <a href="<?= htmlspecialchars(indexUrlWithout('year')) ?>">解除</a></span>
            <?php endif; ?>
            <?php if ($tag !== ''): ?>
                <span class="chip">タグ: <?= htmlspecialchars($tag) ?> <a href="<?= htmlspecialchars(indexUrlWithout('tag')) ?>">解除</a></span>
            <?php endif; ?>
            <a href="index.php">全解除</a>
        </div>
    <?php endif; ?>

    <?php if ($yearError !== ''): ?>
        <p class="error-text"><?= htmlspecialchars($yearError) ?></p>
    <?php endif; ?>

    <?php if (!$songs): ?>
        <p class="result-meta">検索結果: 0 件<?= $keyword || $year || $tag ? '（条件に一致）' : '（最新 100 件）' ?></p>
        <p>該当する曲が見つかりませんでした。</p>
    <?php else: ?>
        <p class="result-meta">検索結果: <?= count($songs) ?> 件<?= $keyword || $year || $tag ? '（条件に一致）' : '（最新 100 件）' ?></p>
        <div class="table-wrap">
            <table class="data-table">
            <thead>
                <tr>
                    <th>曲名</th>
                    <th>アーティスト</th>
                    <th>年</th>
                    <th>補助情報</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($songs as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['title']) ?></td>
                    <td><?= htmlspecialchars($row['artist_name'] ?? 'アーティスト不明') ?></td>
                    <td><?= !empty($row['release_year']) ? htmlspecialchars((string)$row['release_year']) : '-' ?></td>
                    <td>
                        <?php if (!empty($row['youtube_url'])): ?>
                            <a href="<?= htmlspecialchars($row['youtube_url']) ?>" target="_blank" rel="noopener noreferrer">試聴</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
