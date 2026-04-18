<?php
require_once 'config.php';
$me = require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: songs.php'); exit; }

// 曲データ取得
$stmt = $pdo->prepare("
    SELECT s.*, a.name AS artist_name, a.id AS artist_id
    FROM songs s LEFT JOIN artists a ON s.artist_id = a.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$song = $stmt->fetch();
if (!$song) { header('Location: songs.php'); exit; }

// パーソナルタグ一覧
$pTagStmt = $pdo->query("SELECT id, name FROM tags WHERE tag_category='personal' AND (type='song' OR type='both') ORDER BY id");
$personalTags = $pTagStmt->fetchAll();

// このユーザーがこの曲につけているパーソナルタグ
$myTagStmt = $pdo->prepare("SELECT tag_id FROM song_tags WHERE song_id=? AND user_id=?");
$myTagStmt->execute([$id, $me['id']]);
$myTagIds = array_column($myTagStmt->fetchAll(), 'tag_id');

// 戻り先（referrer があればそちら、なければ songs.php）
$back = $_GET['back'] ?? '';
$backUrl = in_array($back, ['songs', 'artist']) ? ($back === 'artist' && $song['artist_id'] ? "songs.php?artist_id={$song['artist_id']}" : 'songs.php') : 'songs.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($song['title']) ?> – Songs.TNNET</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
  <?php $activePage = 'songs'; include '_nav.php'; ?>

  <div class="main-wrap">
    <header class="page-header">
      <a href="<?= htmlspecialchars($backUrl) ?>" class="page-back">‹</a>
      <span class="page-title">楽曲詳細</span>
    </header>

    <main class="song-detail-wrap">

      <!-- ① 曲名・アーティスト -->
      <div class="sd-hero">
        <div class="sd-title"><?= htmlspecialchars($song['title']) ?></div>
        <div class="sd-hero-sub">
          <?php if ($song['artist_name']): ?>
            <a href="songs.php?artist_id=<?= $song['artist_id'] ?>" class="sd-artist">
              <?= htmlspecialchars($song['artist_name']) ?>
            </a>
          <?php endif; ?>
          <?php if ($song['release_year']): ?>
            <span class="sd-year"><?= (int)$song['release_year'] ?>年</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- ② カラオケ番号（最重要） -->
      <?php if ($song['dam_number'] || $song['joysound_number']): ?>
      <div class="sd-karaoke-block">
        <div class="sd-section-label">カラオケ番号 — タップでコピー</div>
        <div class="sd-karaoke-nums">
          <?php if ($song['dam_number']): ?>
            <button class="sd-num-card" onclick="copyNum('<?= htmlspecialchars($song['dam_number']) ?>', this)">
              <span class="sd-num-service">DAM</span>
              <span class="sd-num-value"><?= htmlspecialchars($song['dam_number']) ?></span>
              <span class="sd-num-copy-hint">タップでコピー</span>
            </button>
          <?php endif; ?>
          <?php if ($song['joysound_number']): ?>
            <button class="sd-num-card" onclick="copyNum('<?= htmlspecialchars($song['joysound_number']) ?>', this)">
              <span class="sd-num-service">JOYSOUND</span>
              <span class="sd-num-value"><?= htmlspecialchars($song['joysound_number']) ?></span>
              <span class="sd-num-copy-hint">タップでコピー</span>
            </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ③ マイタグ -->
      <?php if ($personalTags): ?>
      <div class="sd-section">
        <div class="sd-section-label">マイタグ</div>
        <div class="sd-tag-row" id="tag-row">
          <?php foreach ($personalTags as $pt): ?>
            <?php $active = in_array($pt['id'], $myTagIds); ?>
            <button class="sd-tag-btn<?= $active ? ' is-active' : '' ?>"
                    data-tag-id="<?= $pt['id'] ?>"
                    data-song-id="<?= $id ?>">
              <?= htmlspecialchars($pt['name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- ④ YouTube -->
      <?php if ($song['youtube_url']): ?>
      <div class="sd-section">
        <a href="<?= htmlspecialchars($song['youtube_url']) ?>" target="_blank" rel="noopener" class="sd-yt-btn">
          ▶ YouTubeで試聴
        </a>
      </div>
      <?php endif; ?>

      <!-- ⑤ ソングリストへ追加 -->
      <div class="sd-section">
        <div class="sd-section-label">ソングリストへ追加</div>
        <div id="sd-list-area"><div class="list-msg" style="padding:8px 0">読み込み中…</div></div>
      </div>

    </main>
  </div>
</div>

<script>
const SONG_ID  = <?= $id ?>;
const USER_ID  = <?= (int)$me['id'] ?>;
const ACTIVE_KEY = 'activeList_' + USER_ID;

/* ── マイタグ toggle ── */
document.getElementById('tag-row')?.addEventListener('click', async e => {
  const btn = e.target.closest('.sd-tag-btn');
  if (!btn) return;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('song_id', btn.dataset.songId);
  fd.append('tag_id',  btn.dataset.tagId);
  const res = await fetch('api/song_tag.php', { method: 'POST', body: fd });
  const data = await res.json();
  btn.classList.toggle('is-active', data.tagged);
  btn.disabled = false;
});

/* ── 番号コピー ── */
function copyNum(num, btn) {
  navigator.clipboard.writeText(num).then(() => {
    const hint = btn.querySelector('.sd-num-copy-hint');
    btn.classList.add('copied');
    hint.textContent = 'コピーしました ✓';
    setTimeout(() => { btn.classList.remove('copied'); hint.textContent = 'タップでコピー'; }, 1800);
  });
}

/* ── ソングリスト一覧 ── */
async function loadLists() {
  const res = await fetch('api/songlist.php?action=list');
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch(e) {
    document.getElementById('sd-list-area').innerHTML = '<pre style="font-size:11px;color:red;white-space:pre-wrap">' + text.substring(0, 500) + '</pre>';
    return;
  }
  const area = document.getElementById('sd-list-area');
  const lists = data.data ?? data.lists ?? [];
  if (!lists.length) {
    area.innerHTML = '<p class="list-msg" style="padding:8px 0">リストがありません。<a href="songlists.php">作成する</a></p>';
    return;
  }
  area.innerHTML = lists.map(l =>
    `<button class="sd-list-btn" data-list-id="${l.id}" data-list-name="${l.name.replace(/"/g,'&quot;')}">
      <span class="sd-list-name">${l.name}</span>
      <span class="sd-list-count">${l.song_count}曲</span>
    </button>`
  ).join('');
  area.querySelectorAll('.sd-list-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const fd = new FormData();
      fd.append('action', 'add_song');
      fd.append('songlist_id', btn.dataset.listId);
      fd.append('song_id', SONG_ID);
      const res = await fetch('api/songlist.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok || data.already) {
        btn.classList.add('is-added');
        btn.querySelector('.sd-list-count').textContent = '追加済 ✓';
      }
      btn.disabled = false;
    });
  });
}
loadLists();
</script>
</body>
</html>
