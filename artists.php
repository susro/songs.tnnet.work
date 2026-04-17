<?php
require_once 'config.php';

$keyword = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];

function artistsUrlWithout(string $key): string {
    $query = $_GET;
    unset($query[$key]);
    return 'artists.php' . ($query ? '?' . http_build_query($query) : '');
}

$sql = "
SELECT a.*, GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ' / ') AS tags
FROM artists a
LEFT JOIN artist_tags at ON a.id = at.artist_id
LEFT JOIN tags t ON at.tag_id = t.id
";

if ($keyword !== '') {
    $where[] = 'a.name LIKE ?';
    $params[] = "%{$keyword}%";
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' GROUP BY a.id ORDER BY a.name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$artists = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Artists</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="hero-card">
    <h1>Songs.TNNET</h1>
    <p class="hero-lead">マイソングブック</p>
</header>
<div class="container">
    <nav class="top-nav">
        <a href="index.php">曲一覧</a>
        <a href="artists.php" class="is-active">アーティスト一覧</a>
        <a href="add.php">曲を追加</a>
    </nav>

    <form method="get" class="search-form">
        <label>検索: <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="アーティスト名"></label>
        <button type="submit">検索</button>
        <a href="artists.php" class="link-button">クリア</a>
    </form>

    <?php if ($keyword !== ''): ?>
        <div class="active-filters">
            <span>現在の条件:</span>
            <span class="chip">キーワード: <?= htmlspecialchars($keyword) ?> <a href="<?= htmlspecialchars(artistsUrlWithout('q')) ?>">解除</a></span>
            <a href="artists.php">全解除</a>
        </div>
    <?php endif; ?>

    <h2>アーティスト一覧</h2>
    <p class="result-meta">検索結果: <?= count($artists) ?> 件<?= $keyword !== '' ? '（条件に一致）' : '（全件）' ?></p>

    <?php if (!$artists): ?>
        <p>該当するアーティストが見つかりませんでした。</p>
    <?php else: ?>
        <ul class="artist-list">
        <?php foreach ($artists as $a): ?>
            <li>
                <strong><a href="index.php?q=<?= urlencode($a['name']) ?>"><?= htmlspecialchars($a['name']) ?></a></strong>
                <?php if (!empty($a['tags'])): ?>
                    — タグ:
                    <?php foreach (explode(' / ', $a['tags']) as $tagName): ?>
                        <a href="index.php?tag=<?= urlencode($tagName) ?>"><?= htmlspecialchars($tagName) ?></a>
                    <?php endforeach; ?>
                <?php else: ?>
                    — <em>タグなし</em>
                <?php endif; ?>
                — <a href="fetch.php?artist_id=<?= $a['id'] ?>">Fetch</a>
                — <a href="artist_clear.php?artist_id=<?= $a['id'] ?>" class="link-button">曲を全削除</a>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
</body>
</html>
