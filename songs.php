<?php
require_once 'config.php';

// フィルターバー用タグ
$tagStmt = $pdo->query("
    SELECT t.name, COUNT(DISTINCT at2.artist_id) AS cnt
    FROM tags t
    JOIN artist_tags at2 ON at2.tag_id = t.id
    WHERE t.name NOT REGEXP '^[0-9]{4}年代$'
    GROUP BY t.id, t.name ORDER BY cnt DESC LIMIT 20
");
$filterTags = $tagStmt->fetchAll();

$initQ   = trim($_GET['q']   ?? '');
$initTag = trim($_GET['tag'] ?? '');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>曲を探す – Songs.TNNET</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
  <?php $activePage = 'songs'; include '_nav.php'; ?>

  <div class="main-wrap songs-mode">
    <header class="page-header">
      <span class="page-header-brand">Songs.TNNET</span>
      <span class="page-title">曲を探す</span>
      <div class="page-header-right">
        <span id="result-count" class="result-badge"></span>
      </div>
    </header>

    <!-- 検索・タグバー -->
    <div class="search-bar">
      <div class="search-input-row">
        <input type="search" id="q" class="search-input"
               placeholder="曲名・アーティスト名" autocomplete="off" enterkeyhint="search"
               value="<?= htmlspecialchars($initQ) ?>">
        <button id="clear-btn" class="search-clear" hidden aria-label="クリア">✕</button>
      </div>
      <div class="tag-scroll-wrap">
        <div class="tag-scroll" id="tag-bar">
          <button class="tag-pill<?= $initTag === '' ? ' is-active' : '' ?>" data-tag="">すべて</button>
          <?php foreach ($filterTags as $ft): ?>
            <button class="tag-pill<?= $initTag === $ft['name'] ? ' is-active' : '' ?>"
                    data-tag="<?= htmlspecialchars($ft['name']) ?>">
              <?= htmlspecialchars($ft['name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- アクティブリストバー -->
    <div class="active-bar" id="active-bar" hidden>
      <span class="active-bar-icon">📋</span>
      <span id="active-bar-name" class="active-bar-name"></span>
      <span id="active-bar-count" class="active-bar-count"></span>
      <a href="songlists.php" class="active-bar-link">開く →</a>
    </div>

    <!-- 曲リスト -->
    <main class="song-list-scroll" id="song-area">
      <div id="song-list"><div class="list-msg">読み込み中…</div></div>
      <div id="pagination"></div>
    </main>

  </div><!-- /main-wrap -->
</div><!-- /app-shell -->

<!-- リスト選択ダイアログ -->
<dialog id="list-picker" class="list-picker-dialog">
  <div class="dialog-card">
    <h3 class="dialog-title">追加するリストを選択</h3>
    <div id="picker-lists" class="picker-list"></div>
    <div class="picker-new">
      <input type="text" id="picker-new-name" placeholder="新しいリスト名" maxlength="100">
      <button id="picker-new-btn">作成</button>
    </div>
    <button class="dialog-cancel" id="picker-cancel">キャンセル</button>
  </div>
</dialog>

<script>
const state = {
  q:    <?= json_encode($initQ) ?>,
  tag:  <?= json_encode($initTag) ?>,
  page: 1,
  activeListId:    null,
  activeListName:  null,
  activeListCount: 0,
};

/* ── アクティブリスト ── */
function initActiveList() {
  try {
    const s = JSON.parse(localStorage.getItem('activeList') || 'null');
    if (s && s.id) {
      state.activeListId    = s.id;
      state.activeListName  = s.name;
      state.activeListCount = s.count || 0;
      updateActiveBar();
    }
  } catch {}
}
function saveActiveList() {
  localStorage.setItem('activeList', JSON.stringify({
    id: state.activeListId, name: state.activeListName, count: state.activeListCount,
  }));
}
function updateActiveBar() {
  const bar = document.getElementById('active-bar');
  if (state.activeListId) {
    document.getElementById('active-bar-name').textContent  = state.activeListName;
    document.getElementById('active-bar-count').textContent = state.activeListCount + '曲';
    bar.hidden = false;
  } else {
    bar.hidden = true;
  }
}

/* ── ユーティリティ ── */
const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const addedKey  = () => 'added_' + state.activeListId;
const isAdded   = id => { try { return JSON.parse(localStorage.getItem(addedKey()) || '[]').includes(id); } catch { return false; } };
const markAdded = id => { try { const k = addedKey(), a = JSON.parse(localStorage.getItem(k) || '[]'); if (!a.includes(id)) { a.push(id); localStorage.setItem(k, JSON.stringify(a)); } } catch {} };

/* ── 曲取得・描画 ── */
async function fetchSongs() {
  const p = new URLSearchParams({ page: state.page });
  if (state.q)   p.set('q',   state.q);
  if (state.tag) p.set('tag', state.tag);

  document.getElementById('song-list').innerHTML = '<div class="list-msg">読み込み中…</div>';
  document.getElementById('result-count').textContent = '';

  const data = await fetch('api/songs.php?' + p).then(r => r.json());
  renderSongs(data);
}

function renderSongs(data) {
  document.getElementById('result-count').textContent = data.total + '曲';

  const el = document.getElementById('song-list');
  if (!data.songs.length) {
    el.innerHTML = '<div class="list-msg">曲が見つかりません</div>';
    document.getElementById('pagination').innerHTML = '';
    return;
  }

  const offset = (data.page - 1) * 50;
  el.innerHTML = data.songs.map((s, i) => {
    const added = isAdded(s.id);
    const meta  = [esc(s.artist_name || '—'), s.release_year].filter(Boolean).join(' · ');
    const ytBtn = s.youtube_url
      ? `<a class="yt-btn" href="${esc(s.youtube_url)}" target="_blank" rel="noopener" aria-label="試聴">▶</a>`
      : '';
    return `<div class="song-card">
      <span class="song-card-num">${offset + i + 1}</span>
      <div class="song-card-body">
        <div class="song-title">${esc(s.title)}</div>
        <div class="song-meta">${meta}</div>
      </div>
      ${ytBtn}
      <button class="add-btn${added ? ' is-added' : ''}" data-id="${s.id}" aria-label="リストに追加">${added ? '✓' : '＋'}</button>
    </div>`;
  }).join('');

  /* ページング */
  const pg = document.getElementById('pagination');
  if (data.pages <= 1) { pg.innerHTML = ''; return; }
  let h = '<div class="paging">';
  if (data.page > 1)          h += `<button onclick="gotoPage(${data.page-1})">◀ 前</button>`;
  h += `<span>${data.page} / ${data.pages}ページ</span>`;
  if (data.page < data.pages) h += `<button onclick="gotoPage(${data.page+1})">次 ▶</button>`;
  pg.innerHTML = h + '</div>';
}

function gotoPage(n) {
  state.page = n;
  fetchSongs();
  document.getElementById('song-area').scrollTop = 0;
}

/* ── リストに追加 ── */
async function addSong(songId) {
  if (!state.activeListId) { openPicker(songId); return; }
  const fd = new FormData();
  fd.append('action', 'add_song');
  fd.append('songlist_id', state.activeListId);
  fd.append('song_id', songId);
  const data = await fetch('api/songlist.php', { method: 'POST', body: fd }).then(r => r.json());
  if (!data.ok) return;
  state.activeListCount = data.data.count;
  saveActiveList(); updateActiveBar(); markAdded(songId);
  const btn = document.querySelector(`.add-btn[data-id="${songId}"]`);
  if (btn) { btn.textContent = '✓'; btn.classList.add('is-added'); }
}

document.getElementById('song-list').addEventListener('click', e => {
  const btn = e.target.closest('.add-btn');
  if (btn) addSong(parseInt(btn.dataset.id));
});

/* ── 検索 ── */
const qEl = document.getElementById('q');
qEl.addEventListener('input', debounce(e => {
  state.q = e.target.value.trim();
  state.page = 1;
  document.getElementById('clear-btn').hidden = !state.q;
  fetchSongs();
}, 300));
document.getElementById('clear-btn').addEventListener('click', () => {
  state.q = ''; qEl.value = '';
  document.getElementById('clear-btn').hidden = true;
  fetchSongs();
});
if (state.q) document.getElementById('clear-btn').hidden = false;

/* ── タグ ── */
document.getElementById('tag-bar').addEventListener('click', e => {
  const pill = e.target.closest('.tag-pill');
  if (!pill) return;
  document.querySelectorAll('.tag-pill').forEach(p => p.classList.remove('is-active'));
  pill.classList.add('is-active');
  state.tag = pill.dataset.tag;
  state.page = 1;
  fetchSongs();
});

/* ── リスト選択ダイアログ ── */
let pendingSongId = null;
async function openPicker(songId) {
  pendingSongId = songId;
  const data = await fetch('api/songlist.php?action=list').then(r => r.json());
  document.getElementById('picker-lists').innerHTML = data.data.length
    ? data.data.map(sl => `<button class="picker-item" data-id="${sl.id}" data-name="${esc(sl.name)}" data-count="${sl.song_count}">
        <span class="picker-name">${esc(sl.name)}</span><span class="picker-count">${sl.song_count}曲</span></button>`).join('')
    : '<p class="picker-empty">まだリストがありません</p>';
  document.getElementById('picker-new-name').value = '';
  document.getElementById('list-picker').showModal();
}
document.getElementById('picker-lists').addEventListener('click', e => {
  const btn = e.target.closest('.picker-item');
  if (!btn) return;
  state.activeListId    = parseInt(btn.dataset.id);
  state.activeListName  = btn.dataset.name;
  state.activeListCount = parseInt(btn.dataset.count);
  saveActiveList(); updateActiveBar();
  document.getElementById('list-picker').close();
  if (pendingSongId) { addSong(pendingSongId); pendingSongId = null; }
});
document.getElementById('picker-new-btn').addEventListener('click', async () => {
  const name = document.getElementById('picker-new-name').value.trim();
  if (!name) return;
  const fd = new FormData(); fd.append('action','create'); fd.append('name', name);
  const data = await fetch('api/songlist.php', { method:'POST', body:fd }).then(r => r.json());
  if (!data.ok) return;
  state.activeListId = data.data.id; state.activeListName = data.data.name; state.activeListCount = 0;
  saveActiveList(); updateActiveBar();
  document.getElementById('list-picker').close();
  if (pendingSongId) { addSong(pendingSongId); pendingSongId = null; }
});
document.getElementById('picker-cancel').addEventListener('click', () => document.getElementById('list-picker').close());

/* ── 初期化 ── */
initActiveList();
fetchSongs();
</script>
</body>
</html>
