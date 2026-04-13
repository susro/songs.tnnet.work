<?php
require_once 'config.php';

$keyword = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];

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
    <title>Artists</title>
</head>
<body>
<h1>アーティスト一覧</h1>

<form method="get" style="margin-bottom:16px;">
    <label>検索: <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="アーティスト名"></label>
    <button type="submit">検索</button>
    <a href="artists.php">クリア</a>
</form>

<ul>
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
    </li>
<?php endforeach; ?>
</ul>

</body>
</html>
