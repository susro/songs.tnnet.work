<?php
require_once 'config.php';

$stmt = $pdo->query("
    SELECT sl.id, sl.name, sl.memo, sl.updated_at,
           COUNT(ss.song_id) AS song_count
    FROM songlists sl
    LEFT JOIN songlist_songs ss ON sl.id = ss.songlist_id
    GROUP BY sl.id ORDER BY sl.updated_at DESC
");
$lists = $stmt->fetchAll();

// リスト詳細（?id=X の場合）
$detail = null;
$detailSongs = [];
if (!empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $s = $pdo->prepare("SELECT * FROM songlists WHERE id = ?");
    $s->execute([$id]);
    $detail = $s->fetch();
    if ($detail) {
        $ss = $pdo->prepare("
            SELECT s.id, s.title, s.release_year, s.youtube_url,
                   a.name AS artist_name, sl.position
            FROM songlist_songs sl
            JOIN songs s ON sl.song_id = s.id
            LEFT JOIN artists a ON s.artist_id = a.id
            WHERE sl.songlist_id = ?
            ORDER BY sl.position ASC, sl.added_at ASC
        ");
        $ss->execute([$id]);
        $detailSongs = $ss->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ソングリスト – Songs.TNNET</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="app-layout">

<header class="app-bar">
  <?php if ($detail): ?>
    <a href="songlists.php" class="app-bar-back">←</a>
    <span class="app-bar-title"><?= htmlspecialchars($detail['name']) ?></span>
  <?php else: ?>
    <span class="app-bar-title">ソングリスト</span>
  <?php endif; ?>
</header>

<main class="song-list-area">

<?php if ($detail): ?>
  <!-- ── リスト詳細 ── -->
  <div class="list-detail-header panel-card">
    <div class="list-detail-name"><?= htmlspecialchars($detail['name']) ?></div>
    <?php if ($detail['memo']): ?>
      <div class="list-detail-memo"><?= htmlspecialchars($detail['memo']) ?></div>
    <?php endif; ?>
    <div class="list-detail-meta"><?= count($detailSongs) ?>曲</div>
    <div class="list-detail-actions">
      <button class="set-active-btn" data-id="<?= $detail['id'] ?>" data-name="<?= htmlspecialchars($detail['name']) ?>" data-count="<?= count($detailSongs) ?>">
        このリストをアクティブに設定
      </button>
    </div>
  </div>

  <?php if (!$detailSongs): ?>
    <div class="list-msg">曲がまだありません。<a href="index.php">曲一覧</a>から追加してください。</div>
  <?php else: ?>
    <div id="detail-song-list">
    <?php foreach ($detailSongs as $s): ?>
      <div class="song-card" data-id="<?= $s['id'] ?>">
        <div class="song-card-body">
          <div class="song-title"><?= htmlspecialchars($s['title']) ?></div>
          <div class="song-meta">
            <?= htmlspecialchars($s['artist_name'] ?? '—') ?>
            <?= $s['release_year'] ? ' · ' . $s['release_year'] : '' ?>
          </div>
        </div>
        <?php if ($s['youtube_url']): ?>
          <a class="yt-btn" href="<?= htmlspecialchars($s['youtube_url']) ?>" target="_blank" rel="noopener" aria-label="試聴">▶</a>
        <?php endif; ?>
        <button class="remove-btn" data-list="<?= $detail['id'] ?>" data-song="<?= $s['id'] ?>" aria-label="削除">✕</button>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
  <!-- ── リスト一覧 ── -->
  <div class="list-toolbar">
    <button id="new-list-btn" class="btn-primary">＋ 新しいリスト</button>
  </div>

  <div id="new-list-form" class="panel-card" hidden>
    <label class="form-label">リスト名
      <input type="text" id="new-list-name" placeholder="例: カラオケ夏2025" maxlength="100">
    </label>
    <div class="form-row">
      <button id="create-list-btn" class="btn-primary">作成</button>
      <button id="cancel-new-btn" class="btn-secondary">キャンセル</button>
    </div>
  </div>

  <?php if (!$lists): ?>
    <div class="list-msg">まだリストがありません。上のボタンから作成してください。</div>
  <?php else: ?>
    <div id="list-cards">
    <?php foreach ($lists as $sl): ?>
      <a href="songlists.php?id=<?= $sl['id'] ?>" class="list-card">
        <div class="list-card-body">
          <div class="list-card-name"><?= htmlspecialchars($sl['name']) ?></div>
          <div class="list-card-meta"><?= (int)$sl['song_count'] ?>曲
            · <?= date('m/d', strtotime($sl['updated_at'])) ?>更新
          </div>
        </div>
        <span class="list-card-arrow">›</span>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>
</main>

<nav class="bottom-nav">
  <a href="index.php" class="bottom-nav-item">
    <span class="nav-icon">♪</span>
    <span class="nav-label">曲一覧</span>
  </a>
  <a href="songlists.php" class="bottom-nav-item is-active">
    <span class="nav-icon">📋</span>
    <span class="nav-label">リスト</span>
  </a>
  <a href="admin.php" class="bottom-nav-item">
    <span class="nav-icon">⚙</span>
    <span class="nav-label">管理</span>
  </a>
</nav>

<script>
// ── 新規リスト作成 ──
document.getElementById('new-list-btn')?.addEventListener('click', () => {
  document.getElementById('new-list-form').hidden = false;
  document.getElementById('new-list-name').focus();
});
document.getElementById('cancel-new-btn')?.addEventListener('click', () => {
  document.getElementById('new-list-form').hidden = true;
});
document.getElementById('create-list-btn')?.addEventListener('click', async () => {
  const name = document.getElementById('new-list-name').value.trim();
  if (!name) return;
  const fd = new FormData();
  fd.append('action', 'create'); fd.append('name', name);
  const res  = await fetch('api/songlist.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) location.href = 'songlists.php?id=' + data.data.id;
});

// ── 曲を削除（詳細画面）──
document.getElementById('detail-song-list')?.addEventListener('click', async e => {
  const btn = e.target.closest('.remove-btn');
  if (!btn) return;
  if (!confirm('このリストから削除しますか？')) return;
  const fd = new FormData();
  fd.append('action',      'remove_song');
  fd.append('songlist_id', btn.dataset.list);
  fd.append('song_id',     btn.dataset.song);
  const res  = await fetch('api/songlist.php', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) btn.closest('.song-card').remove();
});

// ── アクティブリストに設定 ──
document.querySelector('.set-active-btn')?.addEventListener('click', e => {
  const btn = e.currentTarget;
  localStorage.setItem('activeList', JSON.stringify({
    id: parseInt(btn.dataset.id),
    name: btn.dataset.name,
    count: parseInt(btn.dataset.count),
  }));
  btn.textContent = '✓ アクティブに設定しました';
  btn.disabled = true;
  setTimeout(() => location.href = 'index.php', 800);
});
</script>
</body>
</html>
