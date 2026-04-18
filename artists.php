<?php
require_once 'config.php';
require_login();

$keyword  = trim((string)($_GET['q']      ?? ''));
$tagFilter = trim((string)($_GET['tag']   ?? ''));
$focusId  = (int)($_GET['focus'] ?? 0);

function searchVariants(string $q): array {
    $list = [$q];
    $a = mb_convert_kana($q, 'as',  'UTF-8'); if ($a !== $q)           $list[] = $a;
    $k = mb_convert_kana($q, 'KV',  'UTF-8'); if ($k !== $q)           $list[] = $k;
    $h = mb_convert_kana($k, 'c',   'UTF-8'); if (!in_array($h,$list)) $list[] = $h;
    $c = mb_convert_kana($q, 'C',   'UTF-8'); if (!in_array($c,$list)) $list[] = $c;
    return array_unique($list);
}

/* ── 50音行マッピング ── */
function kanaRow(string $reading): string {
    if ($reading === '') return 'その他';
    $c = mb_substr($reading, 0, 1, 'UTF-8');
    static $map = [
        'ア'=>'ア','イ'=>'ア','ウ'=>'ア','エ'=>'ア','オ'=>'ア',
        'カ'=>'カ','キ'=>'カ','ク'=>'カ','ケ'=>'カ','コ'=>'カ',
        'ガ'=>'カ','ギ'=>'カ','グ'=>'カ','ゲ'=>'カ','ゴ'=>'カ',
        'サ'=>'サ','シ'=>'サ','ス'=>'サ','セ'=>'サ','ソ'=>'サ',
        'ザ'=>'サ','ジ'=>'サ','ズ'=>'サ','ゼ'=>'サ','ゾ'=>'サ',
        'タ'=>'タ','チ'=>'タ','ツ'=>'タ','テ'=>'タ','ト'=>'タ',
        'ダ'=>'タ','ヂ'=>'タ','ヅ'=>'タ','デ'=>'タ','ド'=>'タ',
        'ナ'=>'ナ','ニ'=>'ナ','ヌ'=>'ナ','ネ'=>'ナ','ノ'=>'ナ',
        'ハ'=>'ハ','ヒ'=>'ハ','フ'=>'ハ','ヘ'=>'ハ','ホ'=>'ハ',
        'バ'=>'ハ','ビ'=>'ハ','ブ'=>'ハ','ベ'=>'ハ','ボ'=>'ハ',
        'パ'=>'ハ','ピ'=>'ハ','プ'=>'ハ','ペ'=>'ハ','ポ'=>'ハ',
        'マ'=>'マ','ミ'=>'マ','ム'=>'マ','メ'=>'マ','モ'=>'マ',
        'ヤ'=>'ヤ','ユ'=>'ヤ','ヨ'=>'ヤ',
        'ラ'=>'ラ','リ'=>'ラ','ル'=>'ラ','レ'=>'ラ','ロ'=>'ラ',
        'ワ'=>'ワ','ヲ'=>'ワ','ン'=>'ワ',
    ];
    return $map[$c] ?? 'その他';
}
const ROW_ORDER = ['ア','カ','サ','タ','ナ','ハ','マ','ヤ','ラ','ワ','その他'];

/* ── SQL ── */
$sql = "
SELECT a.id, a.name, a.reading,
       COUNT(DISTINCT s.id) AS song_count,
       GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR '|') AS tags
FROM artists a
LEFT JOIN songs s         ON s.artist_id = a.id
LEFT JOIN artist_tags at2 ON a.id = at2.artist_id
LEFT JOIN tags t          ON at2.tag_id = t.id
";
$where = [];
$params = [];

if ($keyword !== '') {
    $variants = searchVariants($keyword);
    $orClauses = [];
    foreach ($variants as $v) {
        $orClauses[] = '(a.name LIKE ? OR a.reading LIKE ? OR EXISTS (SELECT 1 FROM artist_aliases aa WHERE aa.artist_id=a.id AND aa.alias LIKE ?))';
        $params[] = "%{$v}%";
        $params[] = "%{$v}%";
        $params[] = "%{$v}%";
    }
    $where[] = '(' . implode(' OR ', $orClauses) . ')';
}
if ($tagFilter !== '') {
    $where[]  = 'EXISTS (SELECT 1 FROM artist_tags atf JOIN tags tf ON atf.tag_id=tf.id WHERE atf.artist_id=a.id AND tf.name=?)';
    $params[] = $tagFilter;
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' GROUP BY a.id, a.name, a.reading
          ORDER BY
            CASE WHEN a.reading IS NULL OR a.reading="" THEN 1 ELSE 0 END,
            a.reading,
            a.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$artists = $stmt->fetchAll();

/* ── 50音行グループ ── */
$groups = array_fill_keys(ROW_ORDER, []);
foreach ($artists as $a) {
    $row = kanaRow((string)($a['reading'] ?? ''));
    $groups[$row][] = $a;
}
$groups = array_filter($groups);  // 空行を除去

/* クエリ文字列ヘルパー */
function qs(array $merge): string {
    $base = array_filter(['q' => $_GET['q'] ?? '', 'tag' => $_GET['tag'] ?? '']);
    return http_build_query(array_merge($base, $merge));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>アーティスト – Songs.TNNET</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
  <?php $activePage = 'artists'; include '_nav.php'; ?>

  <div class="main-wrap artists-mode">
    <header class="page-header">
      <span class="page-header-brand">Songs.TNNET</span>
      <span class="page-title">アーティスト</span>
      <div class="page-header-right">
        <span class="result-badge"><?= count($artists) ?>人</span>
      </div>
    </header>

    <!-- 邦楽/洋楽 フィルタータブ -->
    <div class="artist-filter-tabs">
      <a href="artists.php<?= $keyword ? '?q='.urlencode($keyword) : '' ?>"
         class="artist-filter-tab<?= $tagFilter==='' ? ' is-active' : '' ?>">すべて</a>
      <a href="artists.php?<?= qs(['tag'=>'邦楽']) ?>"
         class="artist-filter-tab tag-jpop<?= $tagFilter==='邦楽' ? ' is-active' : '' ?>">邦楽</a>
      <a href="artists.php?<?= qs(['tag'=>'洋楽']) ?>"
         class="artist-filter-tab tag-western<?= $tagFilter==='洋楽' ? ' is-active' : '' ?>">洋楽</a>
    </div>

    <!-- 検索バー -->
    <div class="search-bar artist-search-bar">
      <form method="get" class="search-input-row" id="artist-search-form">
        <?php if ($tagFilter): ?>
          <input type="hidden" name="tag" value="<?= htmlspecialchars($tagFilter) ?>">
        <?php endif; ?>
        <input type="search" name="q" id="artist-q" class="search-input"
               placeholder="アーティスト名を検索" autocomplete="off"
               value="<?= htmlspecialchars($keyword) ?>">
        <?php if ($keyword !== ''): ?>
          <a href="artists.php<?= $tagFilter ? '?tag='.urlencode($tagFilter) : '' ?>" class="search-clear" aria-label="クリア">✕</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- 50音ジャンプバー -->
    <?php if (!$keyword && $groups): ?>
    <div class="kana-jump-bar" id="kana-jump-bar">
      <?php foreach (ROW_ORDER as $row): ?>
        <?php if (!empty($groups[$row])): ?>
          <a href="#row-<?= $row ?>" class="kana-jump-btn" data-row="<?= $row ?>"><?= $row ?></a>
        <?php else: ?>
          <span class="kana-jump-btn is-empty" data-row="<?= $row ?>"><?= $row ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- アーティストリスト -->
    <main class="artist-list-scroll" id="artist-area">
      <?php if (!$artists): ?>
        <div class="list-msg">アーティストが見つかりません</div>
      <?php else: ?>
        <?php foreach (ROW_ORDER as $row): ?>
          <?php if (empty($groups[$row])) continue; ?>
          <div class="artist-group" id="row-<?= $row ?>">
            <div class="artist-group-head"><?= $row ?>行</div>
            <?php foreach ($groups[$row] as $a): ?>
              <?php
                $tagList = $a['tags'] ? array_slice(explode('|', $a['tags']), 0, 3) : [];
                $isFocus = ($focusId === (int)$a['id']);
              ?>
              <a href="songs.php?artist_id=<?= $a['id'] ?>"
                 class="artist-card<?= $isFocus ? ' is-focus' : '' ?>"
                 id="artist-<?= $a['id'] ?>">
                <span class="artist-card-avatar" aria-hidden="true">
                  <?= htmlspecialchars(mb_substr($a['name'], 0, 1, 'UTF-8')) ?>
                </span>
                <div class="artist-card-body">
                  <div class="artist-card-name"><?= htmlspecialchars($a['name']) ?></div>
                  <div class="artist-card-meta">
                    <span class="artist-song-count"><?= (int)$a['song_count'] ?>曲</span>
                    <?php foreach ($tagList as $tag): ?>
                      <span class="artist-tag-chip" data-tag="<?= htmlspecialchars($tag) ?>"><?= htmlspecialchars($tag) ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
                <span class="artist-card-arrow">›</span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </main>

  </div><!-- /main-wrap -->
</div><!-- /app-shell -->

<script>
(function () {
  const ROW_ORDER = ['ア','カ','サ','タ','ナ','ハ','マ','ヤ','ラ','ワ','その他'];
  const KANA_MAP = {
    'ア':'ア','イ':'ア','ウ':'ア','エ':'ア','オ':'ア',
    'カ':'カ','キ':'カ','ク':'カ','ケ':'カ','コ':'カ','ガ':'カ','ギ':'カ','グ':'カ','ゲ':'カ','ゴ':'カ',
    'サ':'サ','シ':'サ','ス':'サ','セ':'サ','ソ':'サ','ザ':'サ','ジ':'サ','ズ':'サ','ゼ':'サ','ゾ':'サ',
    'タ':'タ','チ':'タ','ツ':'タ','テ':'タ','ト':'タ','ダ':'タ','ヂ':'タ','ヅ':'タ','デ':'タ','ド':'タ',
    'ナ':'ナ','ニ':'ナ','ヌ':'ナ','ネ':'ナ','ノ':'ナ',
    'ハ':'ハ','ヒ':'ハ','フ':'ハ','ヘ':'ハ','ホ':'ハ','バ':'ハ','ビ':'ハ','ブ':'ハ','ベ':'ハ','ボ':'ハ','パ':'ハ','ピ':'ハ','プ':'ハ','ペ':'ハ','ポ':'ハ',
    'マ':'マ','ミ':'マ','ム':'マ','メ':'マ','モ':'マ',
    'ヤ':'ヤ','ユ':'ヤ','ヨ':'ヤ',
    'ラ':'ラ','リ':'ラ','ル':'ラ','レ':'ラ','ロ':'ラ',
    'ワ':'ワ','ヲ':'ワ','ン':'ワ',
  };
  function kanaRow(reading) {
    if (!reading) return 'その他';
    return KANA_MAP[[...reading][0]] || 'その他';
  }
  function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  const inp    = document.getElementById('artist-q');
  const area   = document.getElementById('artist-area');
  const jumpBar = document.getElementById('kana-jump-bar');
  let currentTag = <?= json_encode($tagFilter) ?>;
  let timer;

  /* ── フォームのサブミットを完全に止める ── */
  document.getElementById('artist-search-form').addEventListener('submit', e => e.preventDefault());

  /* ── フィルタータブはURLで動作（ページ遷移OK） ── */

  /* ── アーティスト一覧を描画 ── */
  function renderArtists(artists) {
    if (!artists.length) {
      area.innerHTML = '<div class="list-msg">アーティストが見つかりません</div>';
      if (jumpBar) jumpBar.style.display = 'none';
      return;
    }

    const groups = {};
    ROW_ORDER.forEach(r => groups[r] = []);
    artists.forEach(a => groups[kanaRow(a.reading || '')].push(a));

    let html = '';
    ROW_ORDER.forEach(row => {
      if (!groups[row].length) return;
      html += `<div class="artist-group" id="row-${row}"><div class="artist-group-head">${esc(row)}行</div>`;
      groups[row].forEach(a => {
        const tags = a.tags ? a.tags.split('|').slice(0,3) : [];
        const chips = tags.map(t => `<span class="artist-tag-chip" data-tag="${esc(t)}">${esc(t)}</span>`).join('');
        html += `<a href="songs.php?artist_id=${a.id}" class="artist-card" id="artist-${a.id}">
          <span class="artist-card-avatar" aria-hidden="true">${esc([...a.name][0])}</span>
          <div class="artist-card-body">
            <div class="artist-card-name">${esc(a.name)}</div>
            <div class="artist-card-meta"><span class="artist-song-count">${a.song_count}曲</span>${chips}</div>
          </div>
          <span class="artist-card-arrow">›</span></a>`;
      });
      html += '</div>';
    });
    area.innerHTML = html;

    /* ジャンプバー更新 */
    if (jumpBar) {
      const q = inp.value.trim();
      jumpBar.style.display = q ? 'none' : '';
      jumpBar.querySelectorAll('.kana-jump-btn').forEach(b => {
        const row = b.dataset.row;
        const exists = groups[row] && groups[row].length > 0;
        b.classList.toggle('is-empty', !exists);
        if (exists) b.setAttribute('href', '#row-' + row);
        else b.removeAttribute('href');
      });
    }

    initJumpObserver();
  }

  /* ── API取得 ── */
  async function fetchArtists() {
    const q = inp.value.trim();
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    if (currentTag) params.set('tag', currentTag);
    const res = await fetch('api/artists.php?' + params);
    const data = await res.json();
    renderArtists(data.artists);
  }

  inp.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(fetchArtists, 300);
  });

  /* クリアボタン */
  const clearBtn = document.querySelector('.search-clear');
  if (clearBtn) {
    clearBtn.addEventListener('click', e => {
      e.preventDefault();
      inp.value = '';
      inp.focus();
      fetchArtists();
    });
  }

  /* ── 50音ジャンプ IntersectionObserver ── */
  let jumpObserver;
  function initJumpObserver() {
    if (jumpObserver) jumpObserver.disconnect();
    const jumpBtns = jumpBar ? jumpBar.querySelectorAll('.kana-jump-btn[href]') : [];
    if (!jumpBtns.length) return;
    jumpObserver = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting) {
          const row = e.target.id.replace('row-', '');
          jumpBtns.forEach(b => b.classList.toggle('is-current', b.dataset.row === row));
        }
      });
    }, { threshold: 0.1, rootMargin: '-60px 0px -60% 0px' });
    document.querySelectorAll('.artist-group[id]').forEach(g => jumpObserver.observe(g));
  }

  /* ── 初期フォーカス ── */
  inp.focus();
  inp.setSelectionRange(inp.value.length, inp.value.length);

  /* ── focus アーティストへスクロール ── */
  const focusId = <?= json_encode($focusId ?: null) ?>;
  if (focusId) {
    const el = document.getElementById('artist-' + focusId);
    if (el) el.scrollIntoView({ block: 'center' });
  }

  initJumpObserver();
})();
</script>
</body>
</html>
