<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'];
    $artist = $_POST['artist'];
    $year = $_POST['year'];

    // アーティスト登録（存在しなければ）
    $stmt = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
    $stmt->execute([$artist]);
    $artist_id = $stmt->fetchColumn();

    if (!$artist_id) {
        $stmt = $pdo->prepare("INSERT INTO artists (name) VALUES (?)");
        $stmt->execute([$artist]);
        $artist_id = $pdo->lastInsertId();
    }

    // 曲登録
    $stmt = $pdo->prepare("INSERT INTO songs (title, artist_id, release_year) VALUES (?, ?, ?)");
    $stmt->execute([$title, $artist_id, $year]);

    echo "登録しました！<br><a href='index.php'>戻る</a>";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>曲追加</title></head>
<body>
<h1>曲を追加</h1>
<form method="post">
    曲名: <input type="text" name="title" required><br>
    アーティスト: <input type="text" name="artist" required><br>
    発売年: <input type="number" name="year"><br>
    <button type="submit">登録</button>
</form>
</body>
</html>
