<?php
require_once 'config.php';

$keyword = trim((string)($_GET['q'] ?? ''));
$year = trim((string)($_GET['year'] ?? ''));

$where = [];
$params = [];

if ($keyword !== '') {
    $where[] = '(songs.title LIKE ? OR artists.name LIKE ?)';
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

if ($year !== '' && ctype_digit($year)) {
    $where[] = 'songs.release_year = ?';
    $params[] = $year;
}

$sql = "
SELECT songs.*, artists.name AS artist_name
FROM songs
LEFT JOIN artists ON songs.artist_id = artists.id
";

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
    <title>My Song Book</title>
</head>
<body>
<h1>My Song Book</h1>

<form method="get" style="margin-bottom:16px;">
    <label>検索: <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="曲名／アーティスト"></label>
    <label>年: <input type="text" name="year" value="<?= htmlspecialchars($year) ?>" placeholder="例: 1995" style="width:80px;"></label>
    <button type="submit">検索</button>
    <a href="index.php">クリア</a>
</form>

<p>
    <a href="add.php">曲を追加</a> |
    <a href="artists.php">アーティスト一覧</a>
</p>

<?php if (!$songs): ?>
    <p>該当する曲が見つかりませんでした。</p>
<?php else: ?>
    <p>検索結果: <?= count($songs) ?> 件<?= $keyword || $year ? '（条件に一致）' : '（最新 100 件）' ?></p>
    <ul>
    <?php foreach ($songs as $row): ?>
        <li>
            <?= htmlspecialchars($row['title']) ?> - <?= htmlspecialchars($row['artist_name'] ?? 'アーティスト不明') ?>
            <?php if (!empty($row['release_year'])): ?>
                (<?= htmlspecialchars($row['release_year']) ?>)
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

</body>
</html>
