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

function indexUrlWith(array $overrides = [], array $removeKeys = []): string {
    $query = $_GET;
    foreach ($removeKeys as $key) {
        unset($query[$key]);
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
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

$tagStmt = $pdo->query("
SELECT t.name, COUNT(*) AS use_count
FROM tags t
JOIN artist_tags at ON at.tag_id = t.id
WHERE t.name NOT REGEXP '^[0-9]{4}年代$'
GROUP BY t.id, t.name
ORDER BY use_count DESC, t.name ASC
LIMIT 8
");
$quickTags = $tagStmt->fetchAll();

$decadeStmt = $pdo->query("
SELECT FLOOR(release_year / 10) * 10 AS decade, COUNT(*) AS song_count
FROM songs
WHERE release_year IS NOT NULL AND release_year >= 1900
GROUP BY decade
ORDER BY decade ASC
");
$quickDecades = $decadeStmt->fetchAll();
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
<div class="container retro-home">
    <header class="hero-card">
        <p class="hero-kicker">Songs.TNNET</p>
        <h1>Songs.TNNET</h1>
        <p class="hero-lead">探す・選ぶを同じ画面で。スマホでも使いやすいトップへ更新中です。</p>
    </header>

    <nav class="top-nav">
        <a href="index.php" class="is-active">トップ</a>
        <a href="artists.php">アーティスト一覧</a>
    </nav>

    <section class="home-grid">
        <article class="panel-card">
            <h2>探す</h2>
            <form method="get" class="search-form">
                <label>キーワード: <input type="text" name="q" value="<?= htmlspecialchars($keyword) ?>" placeholder="曲名／アーティスト"></label>
                <label>年: <input type="text" name="year" value="<?= htmlspecialchars($year) ?>" placeholder="例: 1995"></label>
                <label>タグ: <input type="text" name="tag" value="<?= htmlspecialchars($tag) ?>" placeholder="ロック／J-POP"></label>
                <button type="submit">検索</button>
                <a href="index.php" class="link-button">クリア</a>
            </form>
        </article>

        <article class="panel-card">
            <h2>選ぶ</h2>
            <p class="panel-note">よく使うタグ</p>
            <div class="quick-chip-grid">
                <?php foreach ($quickTags as $quickTag): ?>
                    <a class="chip action-chip" href="<?= htmlspecialchars(indexUrlWith(['tag' => $quickTag['name']], ['q', 'year'])) ?>">
                        <?= htmlspecialchars($quickTag['name']) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$quickTags): ?>
                    <span class="chip">タグ準備中</span>
                <?php endif; ?>
            </div>

            <p class="panel-note">年代から選ぶ</p>
            <div class="quick-chip-grid">
                <?php foreach ($quickDecades as $quickDecade): ?>
                    <a class="chip action-chip" href="<?= htmlspecialchars(indexUrlWith(['year' => (string)$quickDecade['decade']], ['q', 'tag'])) ?>">
                        <?= htmlspecialchars((string)$quickDecade['decade']) ?>年代
                    </a>
                <?php endforeach; ?>
                <?php if (!$quickDecades): ?>
                    <span class="chip">年代データ準備中</span>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section class="feature-tiles">
        <a class="feature-tile" href="artists.php">
            <strong>アーティスト</strong>
            <span>タグ付き一覧を見る</span>
        </a>
        <a class="feature-tile" href="<?= htmlspecialchars(indexUrlWith(['tag' => 'ロック'], ['q', 'year'])) ?>">
            <strong>人気タグへ</strong>
            <span>カテゴリから探す</span>
        </a>
        <div class="feature-tile feature-tile-placeholder">
            <strong>YouTube試聴</strong>
            <span>準備中（拡張予定）</span>
        </div>
        <div class="feature-tile feature-tile-placeholder">
            <strong>マイリスト</strong>
            <span>準備中（拡張予定）</span>
        </div>
    </section>

    <section class="panel-card admin-panel">
        <h2>管理モード</h2>
        <p class="panel-note">登録・メンテナンス系の導線です（使用モードと分離）。</p>
        <p>
            <a href="admin.php">管理ホームへ</a> /
            <a href="add.php">曲を追加</a>
        </p>
    </section>

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

    <section class="panel-card">
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
    </section>
</div>
</body>
</html>
