<?php
// DB接続
$dsn = 'mysql:host=localhost;dbname=tnnet_songs;charset=utf8mb4';
$user = 'tnnet_songs';
$pass = '2469Songs';

try {
    $pdo = new PDO($dsn, $user, $pass);
} catch (PDOException $e) {
    exit("DB接続エラー: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Songs DB</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        h1 { margin-bottom: 10px; }
    </style>
</head>
<body>
    <h1>Songs DB</h1>
    <p>ここに曲一覧を表示していくよ。</p>
</body>
</html>
