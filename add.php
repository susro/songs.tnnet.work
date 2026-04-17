<?php
require_once 'config.php';

$title = '';
$artist = '';
$year = '';
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $artist = trim((string)($_POST['artist'] ?? ''));
    $year = trim((string)($_POST['year'] ?? ''));

    if ($title === '') {
        $errors[] = '曲名は必須です。';
    }
    if ($artist === '') {
        $errors[] = 'アーティスト名は必須です。';
    }

    $releaseYear = null;
    if ($year !== '') {
        if (!ctype_digit($year)) {
            $errors[] = '発売年は数字で入力してください（例: 1995）。';
        } else {
            $releaseYear = (int)$year;
            $maxYear = (int)date('Y') + 1;
            if ($releaseYear < 1900 || $releaseYear > $maxYear) {
                $errors[] = "発売年は 1900〜{$maxYear} の範囲で入力してください。";
            }
        }
    }

    if (!$errors) {
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
        $stmt->execute([$title, $artist_id, $releaseYear]);

        $successMessage = '登録しました。続けて入力するか、一覧画面へ戻れます。';
        $title = '';
        $artist = '';
        $year = '';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>曲追加</title>
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
        <a href="artists.php">アーティスト一覧</a>
        <a href="add.php" class="is-active">曲を追加</a>
    </nav>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $error): ?>
                <p class="error-text"><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="success-box">
            <p><?= htmlspecialchars($successMessage) ?></p>
            <p><a href="index.php">曲一覧へ戻る</a></p>
        </div>
    <?php endif; ?>

    <h2>曲を追加</h2>
    <form method="post" class="search-form">
        <label>曲名: <input type="text" name="title" required value="<?= htmlspecialchars($title) ?>"></label>
        <label>アーティスト: <input type="text" name="artist" required value="<?= htmlspecialchars($artist) ?>"></label>
        <label>発売年: <input type="text" name="year" inputmode="numeric" placeholder="例: 1995" value="<?= htmlspecialchars($year) ?>"></label>
        <button type="submit">登録</button>
    </form>
</div>
</body>
</html>
