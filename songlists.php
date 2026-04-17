<?php
require_once 'config.php';

$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$detail = null;
$detailSongs = [];

if ($id) {
    $s = $pdo->prepare("SELECT * FROM songlists WHERE id = ?");
    $s->execute([$id]);
    $detail = $s->fetch();
    if ($detail) {
        $ss = $pdo->prepare("
            SELECT s.id, s.title, s.release_year, s.youtube_url, a.name AS artist_name
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

if (!$detail) {
    $listStmt = $pdo->query("
        SELECT sl.id, sl.name, sl.memo, sl.updated_at,
               COUNT(ss.song_id) AS song_count
        FROM songlists sl
        LEFT JOIN songlist_songs ss ON sl.id = ss.songlist_id
        GROUP BY sl.id ORDER BY sl.updated_at DESC
    ");
    $lists = $listStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $detail ? htmlspecialchars($detail['name']) . ' – ' : '' ?>ソングリスト – Songs.TNNET</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
  <?php $activePage = 'lists'; include '_nav.php'; ?>

  <div class="main-wrap">
    <header class="page-header">
      <span class="page-header-brand">Songs.TNNET</span>
      <?php if ($detail): ?>
        <a href="songlists.php" class="page-back">← 一覧</a>
        <span class="page-title"><?= htmlspecialchars($detail['name']) ?></span>
      <?php else: ?>
        <span class="page-title">ソングリスト</span>
      <?php endif; ?>
    </header>

    <div class="page-body">

    <?php if ($detail): ?>
      <!-- ── リスト詳細 ── -->
      <div class="detail-header-card">
        <div class="detail-header-body">
          <div class="detail-title"><?= htmlspecialchars($detail['name']) ?></div>
          <?php if ($detail['memo']): ?>
            <div class="detail-meta"><?= htmlspecialchars($detail['memo']) ?></div>
          <?php endif; ?>
          <div class="detail-meta"><?= count($detailSongs) ?>曲</div>
        </div>
        <button class="set-active-btn"
                data-id="<?= $detail['id'] ?>"
                data-name="<?= htmlspecialchars($detail['name']) ?>"
                data-count="<?= count($detailSongs) ?>">
          アクティブに設定
        </button>
      </div>

      <a href="songs.php" class="add-songs-link">＋ 曲を追加する →</a>

      <?php if (!$detailSongs): ?>
        <div class="list-msg">曲がまだありません。「曲を追加する」から追加してください。</div>
      <?php else: ?>
        <div class="list-card-wrap" id="detail-song-list">
          <?php foreach ($detailSongs as $i => $s): ?>
            <div class="song-card" data-id="<?= $s['id'] ?>">
              <span class="song-card-num"><?= $i + 1 ?></span>
              <div class="song-card-body">
                <div class="song-title"><?= htmlspecialchars($s['title']) ?></div>
                <div class="song-meta">
                  <?= htmlspecialchars($s['artist_name'] ?? '—') ?>
                  <?= $s['release_year'] ? ' · ' . $s['release_year'] : '' ?>
                </div>
              </div>
              <?php if ($s['youtube_url']): ?>
                <a class="yt-btn" href="<?= htmlspecialchars($s['youtube_url']) ?>" target="_blank" rel="noopener">▶</a>
              <?php endif; ?>
              <button class="remove-btn" data-list="<?= $detail['id'] ?>" data-song="<?= $s['id'] ?>">✕</button>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="danger-zone">
          <button class="btn-danger" id="delete-list-btn" data-id="<?= $detail['id'] ?>">
            このリストを削除
          </button>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- ── リスト一覧 ── -->
      <button class="btn-primary" id="new-list-btn">＋ 新しいリスト</button>

      <div class="new-list-form" id="new-list-form" hidden>
        <label class="form-label">リスト名
          <input type="text" id="new-list-name" placeholder="例: カラオケ夏2025" maxlength="100">
        </label>
        <div class="form-row">
          <button class="btn-primary"   id="create-list-btn">作成</button>
          <button class="btn-secondary" id="cancel-new-btn">キャンセル</button>
        </div>
      </div>

      <?php if (empty($lists)): ?>
        <div class="list-msg">まだリストがありません。上のボタンから作成してください。</div>
      <?php else: ?>
        <div class="list-card-wrap">
          <?php foreach ($lists as $sl): ?>
            <a href="songlists.php?id=<?= $sl['id'] ?>" class="list-card">
              <span class="list-card-icon">📋</span>
              <div class="list-card-body">
                <div class="list-card-name"><?= htmlspecialchars($sl['name']) ?></div>
                <div class="list-card-meta">
                  <?= (int)$sl['song_count'] ?>曲
                  · <?= date('m/d', strtotime($sl['updated_at'])) ?>更新
                </div>
              </div>
              <span class="list-card-count"><?= (int)$sl['song_count'] ?></span>
              <span class="list-card-arrow">›</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    <?php endif; ?>

    </div><!-- /page-body -->
  </div><!-- /main-wrap -->
</div><!-- /app-shell -->

<script>
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
  const fd = new FormData(); fd.append('action','create'); fd.append('name', name);
  const data = await fetch('api/songlist.php', { method:'POST', body:fd }).then(r => r.json());
  if (data.ok) location.href = 'songlists.php?id=' + data.data.id;
});

document.getElementById('detail-song-list')?.addEventListener('click', async e => {
  const btn = e.target.closest('.remove-btn');
  if (!btn) return;
  if (!confirm('このリストから削除しますか？')) return;
  const fd = new FormData();
  fd.append('action','remove_song'); fd.append('songlist_id', btn.dataset.list); fd.append('song_id', btn.dataset.song);
  const data = await fetch('api/songlist.php', { method:'POST', body:fd }).then(r => r.json());
  if (data.ok) btn.closest('.song-card').remove();
});

document.querySelector('.set-active-btn')?.addEventListener('click', e => {
  const btn = e.currentTarget;
  localStorage.setItem('activeList', JSON.stringify({
    id: parseInt(btn.dataset.id), name: btn.dataset.name, count: parseInt(btn.dataset.count),
  }));
  btn.textContent = '✓ アクティブに設定しました';
  btn.disabled = true;
  setTimeout(() => location.href = 'songs.php', 700);
});

document.getElementById('delete-list-btn')?.addEventListener('click', async e => {
  if (!confirm('このリストを削除しますか？（曲データは削除されません）')) return;
  const fd = new FormData(); fd.append('action','delete'); fd.append('id', e.currentTarget.dataset.id);
  const data = await fetch('api/songlist.php', { method:'POST', body:fd }).then(r => r.json());
  if (data.ok) location.href = 'songlists.php';
});
</script>
</body>
</html>
