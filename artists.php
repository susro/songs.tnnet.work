<?php
require_once 'config.php';

$stmt = $pdo->query("SELECT * FROM artists ORDER BY name ASC");
$artists = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Artists</title></head>
<body>
<h1>アーティスト一覧</h1>

<ul>
<?php foreach ($artists as $a): ?>
    <li>
        <?= htmlspecialchars($a['name']) ?>
        — <a href="fetch.php?artist_id=<?= $a['id'] ?>">Fetch</a>
    </li>
<?php endforeach; ?>
</ul>

</body>
</html>
