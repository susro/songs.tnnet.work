<?php
require_once 'config.php';

$sql = "
SELECT songs.*, artists.name AS artist_name
FROM songs
LEFT JOIN artists ON songs.artist_id = artists.id
ORDER BY songs.id DESC
LIMIT 50
";

$stmt = $pdo->query($sql);
$songs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>My Song Book</title></head>
<body>
<h1>My Song Book</h1>

<?php if (empty($songs)): ?>
    <p>まだ曲が登録されていませんの。</p>
<?php else: ?>
    <ul>
    <?php foreach ($songs as $row): ?>
        <li><?= htmlspecialchars($row['title']) ?> - <?= htmlspecialchars($row['artist_name']) ?></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>

</body>
</html>
