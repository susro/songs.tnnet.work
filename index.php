<?php
require_once 'config.php';

// 最近追加した曲（5件）
$recentStmt = $pdo->query("
    SELECT s.id, s.title, s.release_year, a.name AS artist_name
    FROM songs s
    LEFT JOIN artists a ON s.artist_id = a.id
    ORDER BY s.id DESC LIMIT 5
");
$recentSongs = $recentStmt->fetchAll();

// 統計
$stats = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM songs)    AS song_count,
      (SELECT COUNT(*) FROM artists)  AS artist_count,
      (SELECT COUNT(*) FROM songlists) AS list_count
")->fetch();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Songs.TNNET</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
  <?php $activePage = 'home'; include '_nav.php'; ?>

  <div class="main-wrap">
    <header class="page-header">
      <span class="page-header-brand">Songs.TNNET</span>
      <span class="page-title">ホーム</span>
    </header>

    <div class="page-body">

      <!-- ── アクティブリストカード ── -->
      <div id="home-active-list" class="active-list-card no-list" onclick="location.href='songlists.php'">
        <span class="active-list-card-icon">📋</span>
        <div class="active-list-card-body">
          <div class="active-list-card-name">リストが選択されていません</div>
          <div class="active-list-card-meta">タップしてリストを選ぶ</div>
        </div>
        <span class="active-list-card-arrow">›</span>
      </div>

      <!-- ── アクションタイル（デンモク風）── -->
      <div class="action-grid">
        <a href="songs.php" class="action-tile">
          <span class="action-icon">🔍</span>
          <span class="action-label">曲を探す</span>
          <span class="action-sub">検索・フィルター・追加</span>
        </a>
        <a href="songlists.php" class="action-tile">
          <span class="action-icon">📋</span>
          <span class="action-label">リスト管理</span>
          <span class="action-sub">作成・編集・切替</span>
        </a>
        <a href="songs.php?mytag=1" class="action-tile">
          <span class="action-icon">⭐</span>
          <span class="action-label">マイタグ</span>
          <span class="action-sub">お気に入り・練習中</span>
        </a>
        <a href="admin.php" class="action-tile">
          <span class="action-icon">⚙</span>
          <span class="action-label">管理</span>
          <span class="action-sub">楽曲・アーティスト追加</span>
        </a>
      </div>

      <!-- ── 統計バー ── -->
      <div class="stats-bar">
        <div class="stat-item">
          <span class="stat-num"><?= number_format((int)$stats['song_count']) ?></span>
          <span class="stat-label">曲</span>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
          <span class="stat-num"><?= number_format((int)$stats['artist_count']) ?></span>
          <span class="stat-label">アーティスト</span>
        </div>
        <div class="stat-divider"></div>
        <div class="stat-item">
          <span class="stat-num"><?= (int)$stats['list_count'] ?></span>
          <span class="stat-label">リスト</span>
        </div>
      </div>

      <!-- ── 最近追加した曲 ── -->
      <p class="section-title">最近追加</p>
      <div class="recent-list">
        <?php foreach ($recentSongs as $s): ?>
          <a href="songs.php?q=<?= urlencode($s['title']) ?>" class="recent-item">
            <span class="recent-item-icon">♪</span>
            <div>
              <div class="recent-item-title"><?= htmlspecialchars($s['title']) ?></div>
              <div class="recent-item-meta">
                <?= htmlspecialchars($s['artist_name'] ?? '—') ?>
                <?= $s['release_year'] ? ' · ' . $s['release_year'] : '' ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
        <?php if (!$recentSongs): ?>
          <div class="recent-item"><span class="recent-item-meta">まだ曲が登録されていません</span></div>
        <?php endif; ?>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-wrap -->
</div><!-- /app-shell -->

<script>
(function () {
  try {
    const s = JSON.parse(localStorage.getItem('activeList') || 'null');
    if (!s || !s.id) return;
    const card = document.getElementById('home-active-list');
    card.classList.replace('no-list', 'has-list');
    card.querySelector('.active-list-card-name').textContent = s.name;
    card.querySelector('.active-list-card-meta').textContent = (s.count || 0) + '曲';
    card.onclick = () => location.href = 'songlists.php?id=' + s.id;
  } catch {}
})();
</script>
</body>
</html>
