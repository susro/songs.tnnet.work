<?php
require_once 'config.php';
$me = require_login();

$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$detail = null;
$detailSongs = [];

if ($id) {
    $s = $pdo->prepare("SELECT * FROM songlists WHERE id = ? AND (user_id = ? OR list_type = 'theme')");
    $s->execute([$id, $me['id']]);
    $detail = $s->fetch();
    if ($detail) {
        if ($detail['list_type'] === 'dynamic') {
            $cfg = json_decode($detail['filter_config'] ?? '{}', true);
            $personalTag = $cfg['personal_tag'] ?? '';
            $personalTagId = 0;
            if ($personalTag) {
                $tRow = $pdo->prepare("SELECT id FROM tags WHERE name=? AND tag_category='personal'");
                $tRow->execute([$personalTag]);
                $personalTagId = (int)($tRow->fetchColumn() ?: 0);
                $ss = $pdo->prepare("
                    SELECT s.id, s.title, s.release_year, s.youtube_url, s.dam_number,
                           a.name AS artist_name
                    FROM song_tags st
                    JOIN tags t  ON st.tag_id = t.id
                    JOIN songs s ON st.song_id = s.id
                    LEFT JOIN artists a ON s.artist_id = a.id
                    WHERE t.name = ? AND st.user_id = ?
                    ORDER BY s.id DESC
                ");
                $ss->execute([$personalTag, $me['id']]);
                $detailSongs = $ss->fetchAll();
            }
        } else {
            $ss = $pdo->prepare("
                SELECT s.id, s.title, s.release_year, s.youtube_url, s.dam_number,
                       a.name AS artist_name
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
}

if (!$detail) {
    $listStmt = $pdo->prepare("
        SELECT sl.id, sl.name, sl.memo, sl.updated_at,
               sl.list_type, sl.filter_config,
               COUNT(ss.song_id) AS song_count
        FROM songlists sl
        LEFT JOIN songlist_songs ss ON sl.id = ss.songlist_id AND sl.list_type IN ('static','theme')
        WHERE sl.user_id = ? OR sl.list_type = 'theme'
        GROUP BY sl.id ORDER BY sl.list_type DESC, sl.updated_at DESC
    ");
    $listStmt->execute([$me['id']]);
    $allLists = $listStmt->fetchAll();
    $lists = []; $themeLists = [];
    foreach ($allLists as &$sl) {
        if ($sl['list_type'] === 'dynamic') {
            $cfg = json_decode($sl['filter_config'] ?? '{}', true);
            if (!empty($cfg['personal_tag'])) {
                $c = $pdo->prepare("SELECT COUNT(*) FROM song_tags st JOIN tags t ON st.tag_id=t.id WHERE t.name=? AND st.user_id=?");
                $c->execute([$cfg['personal_tag'], $me['id']]);
                $sl['song_count'] = (int)$c->fetchColumn();
            }
        }
        if ($sl['list_type'] === 'theme') $themeLists[] = $sl;
        else $lists[] = $sl;
    }
    unset($sl);
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

      <?php if ($detail['list_type'] === 'static'): ?>
        <a href="songs.php" class="add-songs-link">＋ 曲を追加する →</a>
      <?php elseif ($detail['list_type'] === 'dynamic'): ?>
        <div class="dynamic-list-note">マイタグ「<?= htmlspecialchars(json_decode($detail['filter_config'],true)['personal_tag'] ?? '') ?>」のついた曲が自動表示されます</div>
      <?php else: ?>
        <div class="theme-list-note">
          <span>📌 テーマリスト（閲覧専用）</span>
          <button class="btn-copy-theme" id="copy-theme-btn" data-id="<?= $detail['id'] ?>" data-name="<?= htmlspecialchars($detail['name']) ?>の copy">
            このリストをMyリストにコピー
          </button>
        </div>
      <?php endif; ?>

      <?php if (!$detailSongs): ?>
        <div class="list-msg"><?= $detail['list_type']==='dynamic' ? 'まだタグをつけた曲がありません。楽曲詳細ページからタグを追加してください。' : '曲がまだありません。「曲を追加する」から追加してください。' ?></div>
      <?php else: ?>
        <div class="list-card-wrap" id="detail-song-list">
          <?php foreach ($detailSongs as $i => $s): ?>
            <div class="song-card" data-id="<?= $s['id'] ?>">
              <span class="song-card-num"><?= $i + 1 ?></span>
              <a class="song-card-body" href="song_detail.php?id=<?= $s['id'] ?>">
                <div class="song-title"><?= htmlspecialchars($s['title']) ?></div>
                <div class="song-meta">
                  <?= htmlspecialchars($s['artist_name'] ?? '—') ?>
                  <?= $s['release_year'] ? ' · ' . $s['release_year'] : '' ?>
                  <?php if (!empty($s['dam_number'])): ?>
                    <span class="dam-num">DAM●<?= htmlspecialchars($s['dam_number']) ?></span>
                  <?php endif; ?>
                </div>
              </a>
              <?php if ($s['youtube_url']): ?>
                <a class="yt-btn" href="<?= htmlspecialchars($s['youtube_url']) ?>" target="_blank" rel="noopener">▶</a>
              <?php endif; ?>
              <?php if ($detail['list_type'] === 'static'): ?>
                <button class="remove-btn" data-list="<?= $detail['id'] ?>" data-song="<?= $s['id'] ?>">✕</button>
              <?php endif; ?>
              <?php if ($detail['list_type'] === 'dynamic' && $personalTagId): ?>
                <button class="remove-btn dynamic-untag-btn" data-tag-id="<?= $personalTagId ?>" data-song-id="<?= $s['id'] ?>">✕</button>
              <?php endif; ?>
              <?php if ($detail['list_type'] === 'theme' && !empty($me['is_admin'])): ?>
                <button class="remove-btn theme-remove-btn" data-list="<?= $detail['id'] ?>" data-song="<?= $s['id'] ?>">✕</button>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($detail['list_type'] === 'static'): ?>
        <div class="danger-zone">
          <button class="btn-danger" id="delete-list-btn" data-id="<?= $detail['id'] ?>">
            このリストを削除
          </button>
        </div>
        <?php endif; ?>
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
              <span class="list-card-icon"><?= $sl['list_type']==='dynamic' ? '🔄' : '📋' ?></span>
              <div class="list-card-body">
                <div class="list-card-name">
                  <?= htmlspecialchars($sl['name']) ?>
                  <?php if ($sl['list_type']==='dynamic'): ?>
                    <span class="list-type-badge">自動</span>
                  <?php endif; ?>
                </div>
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

      <?php if (!empty($themeLists)): ?>
        <div class="theme-section-head">テーマリスト</div>
        <div class="list-card-wrap">
          <?php foreach ($themeLists as $sl): ?>
            <a href="songlists.php?id=<?= $sl['id'] ?>" class="list-card list-card-theme">
              <span class="list-card-icon">📌</span>
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
  localStorage.setItem('activeList_<?= (int)$me['id'] ?>', JSON.stringify({
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

/* テーマリスト: Myリストにコピー */
document.getElementById('copy-theme-btn')?.addEventListener('click', async e => {
  const btn = e.currentTarget;
  const name = prompt('コピー先のリスト名を入力してください', btn.dataset.name);
  if (!name) return;
  const fd = new FormData();
  fd.append('action', 'copy');
  fd.append('id', btn.dataset.id);
  fd.append('name', name);
  const data = await fetch('api/songlist.php', { method:'POST', body:fd }).then(r => r.json());
  if (data.ok) {
    alert('「' + name + '」としてMyリストにコピーしました');
    location.href = 'songlists.php?id=' + data.data.id;
  }
});

/* 動的リスト: タグを外して削除 */
document.getElementById('detail-song-list')?.addEventListener('click', async e => {
  const btn = e.target.closest('.dynamic-untag-btn');
  if (!btn) return;
  if (!confirm('このタグを外しますか？（曲はリストから消えます）')) return;
  const fd = new FormData();
  fd.append('song_id', btn.dataset.songId);
  fd.append('tag_id',  btn.dataset.tagId);
  const data = await fetch('api/song_tag.php', { method:'POST', body:fd }).then(r => r.json());
  if (!data.tagged) btn.closest('.song-card').remove();
});

/* テーマリスト: 管理者による曲削除 */
document.getElementById('detail-song-list')?.addEventListener('click', async e => {
  const btn = e.target.closest('.theme-remove-btn');
  if (!btn) return;
  if (!confirm('このリストから削除しますか？')) return;
  const fd = new FormData();
  fd.append('action','theme_remove_song');
  fd.append('songlist_id', btn.dataset.list);
  fd.append('song_id', btn.dataset.song);
  const data = await fetch('api/songlist.php', { method:'POST', body:fd }).then(r => r.json());
  if (data.ok) btn.closest('.song-card').remove();
});
</script>
</body>
</html>
