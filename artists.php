<?php
require_once 'config.php';

$keyword  = trim((string)($_GET['q']     ?? ''));
$focusId  = (int)($_GET['focus'] ?? 0);  // songs.php からの折り返し用

$sql = "
SELECT a.id, a.name,
       COUNT(DISTINCT s.id)   AS song_count,
       GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR '|') AS tags
FROM artists a
LEFT JOIN songs s        ON s.artist_id = a.id
LEFT JOIN artist_tags at2 ON a.id = at2.artist_id
LEFT JOIN tags t          ON at2.tag_id = t.id
";

$params = [];
if ($keyword !== '') {
    $sql   .= ' WHERE a.name LIKE ?';
    $params[] = "%{$keyword}%";
}
$sql .= ' GROUP BY a.id, a.name ORDER BY a.name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$artists = $stmt->fetchAll();

/* 頭文字グループ（かな50音 / アルファベット / その他） */
$groups = [];
foreach ($artists as $a) {
    $first = mb_substr($a['name'], 0, 1, 'UTF-8');
    $groups[$first][] = $a;
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

    <!-- 検索バー -->
    <div class="search-bar artist-search-bar">
      <form method="get" class="search-input-row" id="artist-search-form">
        <input type="search" name="q" id="artist-q" class="search-input"
               placeholder="アーティスト名を検索" autocomplete="off"
               value="<?= htmlspecialchars($keyword) ?>">
        <?php if ($keyword !== ''): ?>
          <a href="artists.php" class="search-clear" aria-label="クリア">✕</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- アーティストリスト -->
    <main class="artist-list-scroll" id="artist-area">
      <?php if (!$artists): ?>
        <div class="list-msg">アーティストが見つかりません</div>
      <?php else: ?>
        <?php foreach ($groups as $initial => $group): ?>
          <div class="artist-group">
            <div class="artist-group-head"><?= htmlspecialchars($initial) ?></div>
            <?php foreach ($group as $a): ?>
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
                      <span class="artist-tag-chip"><?= htmlspecialchars($tag) ?></span>
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
/* ── リアルタイム検索（GET送信） ── */
(function () {
  const inp = document.getElementById('artist-q');
  const frm = document.getElementById('artist-search-form');
  let timer;
  inp.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => frm.submit(), 400);
  });

  /* focus アーティストへスクロール */
  const focusId = <?= json_encode($focusId ?: null) ?>;
  if (focusId) {
    const el = document.getElementById('artist-' + focusId);
    if (el) el.scrollIntoView({ block: 'center' });
  }
})();
</script>
</body>
</html>
