<?php
require_once 'config.php';

// artist_id が必要
if (!isset($_GET['artist_id'])) {
    die("artist_id が指定されていません");
}

$artist_id = intval($_GET['artist_id']);

// 正規名取得
$stmt = $pdo->prepare("SELECT name FROM artists WHERE id = ?");
$stmt->execute([$artist_id]);
$artist_name = $stmt->fetchColumn();

if (!$artist_name) {
    die("アーティストが見つかりません");
}

// alias 取得
$stmt = $pdo->prepare("SELECT alias FROM artist_aliases WHERE artist_id = ?");
$stmt->execute([$artist_id]);
$aliases = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 検索ワード一覧
$keywords = array_merge([$artist_name], $aliases);

echo "Fetch: " . htmlspecialchars($artist_name) . "<br>";
echo "検索ワード: " . implode(', ', $keywords) . "<br><br>";

foreach ($keywords as $keyword) {

    $url = "https://itunes.apple.com/search?term=" . urlencode($keyword) . "&country=jp&entity=song&limit=200";
    $json = json_decode(file_get_contents($url), true);

    if (!$json || empty($json['results'])) continue;

    foreach ($json['results'] as $song) {

        $title = $song['trackName'];
        $year = substr($song['releaseDate'], 0, 4);

        // 重複チェック
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM songs WHERE title = ? AND artist_id = ?");
        $stmt->execute([$title, $artist_id]);
        if ($stmt->fetchColumn() > 0) continue;

        // 曲登録
        $stmt = $pdo->prepare("INSERT INTO songs (title, artist_id, release_year) VALUES (?, ?, ?)");
        $stmt->execute([$title, $artist_id, $year]);

        echo "登録: {$title}<br>";
    }
}

echo "<br>完了！";
