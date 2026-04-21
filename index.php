<?php
require_once 'config.php';
$me = require_login();

$recentSongs = $pdo->query("
    SELECT s.id, s.title, s.release_year, s.dam_number, s.joysound_number,
           a.name AS artist_name
    FROM songs s LEFT JOIN artists a ON s.artist_id = a.id
    ORDER BY s.id DESC LIMIT 10
")->fetchAll();

$stats = $pdo->query("
    SELECT
      (SELECT COUNT(*)               FROM songs)      AS song_count,
      (SELECT COUNT(*)               FROM artists)    AS artist_count,
      (SELECT COUNT(*)               FROM songlists)  AS list_count,
      (SELECT COUNT(DISTINCT song_id) FROM song_tags) AS tagged_count
")->fetch();

/* ── SVGアイコン（action tiles用） ── */
function tile_icon(string $color, string $paths): string {
    return '<span class="aicon aicon-' . $color . '">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>
    </span>';
}
$ico = [
  'search'  => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
  'user'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
  'list'    => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
  'star'    => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
  'bug'     => '<path d="M8 2l1.5 1.5"/><path d="M14.5 3.5L16 2"/><path d="M9 9c-1 1-1 2.6-1 4a4 4 0 0 0 8 0c0-1.4 0-3-1-4"/><path d="M12 13v5"/><path d="M7.3 7.3A2 2 0 0 0 6 9v1a2 2 0 0 1-2 2H2"/><path d="M16.7 7.3A2 2 0 0 1 18 9v1a2 2 0 0 0 2 2h2"/><path d="M6 20a2 2 0 0 1-2-2v-1"/><path d="M18 20a2 2 0 0 0 2-2v-1"/>',
  'plus'    => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
];
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

      <!-- ════════════════════════════════════
           DESKTOP レイアウト (768px+)
           ════════════════════════════════════ -->
      <div class="desk-home">
        <div class="desk-home-grid">

          <!-- 左カラム -->
          <div>
            <!-- アクティブなソングリスト -->
            <div class="dh-card">
              <div class="dh-card-head">アクティブなソングリスト</div>
              <div class="dh-card-body" style="display:flex;align-items:center;gap:14px;padding:14px 16px">
                <div style="width:40px;height:40px;border-radius:8px;background:var(--accent-lt);color:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </div>
                <div id="dh-active-list-body" style="flex:1">
                  <div id="dh-active-list-name" style="font-size:15px;font-weight:800">リスト未選択</div>
                  <div id="dh-active-list-meta" style="font-size:11px;color:var(--text-muted);margin-top:2px">ソングリストを選んでください</div>
                </div>
                <a id="dh-active-list-link" href="songlists.php" class="dh-btn-primary">開く</a>
              </div>
            </div>

            <!-- 最近追加された曲 -->
            <div class="dh-card">
              <div class="dh-card-head">
                最近追加された曲
                <a href="songs.php" style="margin-left:auto;font-size:11px;font-weight:600;color:var(--accent)">すべて表示 ›</a>
              </div>
              <div style="overflow-x:auto">
                <table class="dh-table">
                  <thead>
                    <tr>
                      <th style="width:32px">#</th>
                      <th>曲名</th>
                      <th>アーティスト</th>
                      <th style="width:54px">年</th>
                      <th style="width:88px">DAM</th>
                      <th style="width:80px">JOY</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach (array_slice($recentSongs, 0, 7) as $i => $s): ?>
                    <tr>
                      <td style="color:var(--text-muted);font-variant-numeric:tabular-nums"><?= $i + 1 ?></td>
                      <td><a href="song_detail.php?id=<?= $s['id'] ?>" style="font-weight:600;color:var(--text);text-decoration:none"><?= htmlspecialchars($s['title']) ?></a></td>
                      <td style="color:var(--text-sub)"><?= htmlspecialchars($s['artist_name'] ?? '—') ?></td>
                      <td style="color:var(--text-muted);font-variant-numeric:tabular-nums"><?= $s['release_year'] ?: '—' ?></td>
                      <td style="font-family:var(--font-mono);font-variant-numeric:tabular-nums"><?= htmlspecialchars($s['dam_number'] ?: '—') ?></td>
                      <td style="font-family:var(--font-mono);font-variant-numeric:tabular-nums"><?= htmlspecialchars($s['joysound_number'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- 右カラム -->
          <div>
            <!-- データベース統計 -->
            <div class="dh-card">
              <div class="dh-card-head">データベース</div>
              <div class="dh-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 14px">
                <?php foreach ([
                  ['n' => number_format((int)$stats['song_count']),   'l' => '登録曲数'],
                  ['n' => number_format((int)$stats['artist_count']), 'l' => 'アーティスト'],
                  ['n' => (int)$stats['list_count'],                  'l' => 'リスト'],
                  ['n' => number_format((int)$stats['tagged_count']), 'l' => 'マイタグ済'],
                ] as $s): ?>
                <div class="dh-stat-tile">
                  <div class="dh-stat-num"><?= $s['n'] ?></div>
                  <div class="dh-stat-label"><?= $s['l'] ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- クイック操作 -->
            <div class="dh-card">
              <div class="dh-card-head">クイック操作</div>
              <div class="dh-card-body" style="display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:10px 12px">
                <?php foreach ([
                  ['＋ 曲を追加',      'songs.php'],
                  ['＋ アーティスト',  'builder.php#artists'],
                  ['＋ リスト作成',    'songlists.php'],
                  ['Builder 起動',     'builder.php'],
                ] as [$label, $href]): ?>
                <a href="<?= $href ?>" class="dh-quick-btn"><?= $label ?></a>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- 最近追加（アクティビティ風） -->
            <div class="dh-card">
              <div class="dh-card-head">最近の追加</div>
              <div style="padding:8px 14px;font-size:12px">
                <?php foreach (array_slice($recentSongs, 0, 5) as $s): ?>
                <div style="padding:7px 0;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:baseline">
                  <div style="flex:1;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($s['title']) ?></div>
                  <div style="color:var(--text-muted);flex-shrink:0"><?= htmlspecialchars($s['artist_name'] ?? '—') ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- ════════════════════════════════════
           MOBILE レイアウト (~767px)
           ════════════════════════════════════ -->
      <div class="mob-home">

        <!-- アクティブリストカード -->
        <a id="home-active-list" class="active-list-card no-list" href="songlists.php">
          <span class="active-list-card-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="28" height="28">
              <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
          </span>
          <div class="active-list-card-body">
            <div class="active-list-card-name">リストが選択されていません</div>
            <div class="active-list-card-meta">タップしてリストを選ぶ</div>
          </div>
          <span class="active-list-card-arrow">›</span>
        </a>

        <!-- アクションタイル -->
        <p class="section-title">メニュー</p>
        <div class="action-grid">
          <a href="songs.php" class="action-tile">
            <?= tile_icon('blue', $ico['search']) ?>
            <span class="action-label">曲を探す</span>
            <span class="action-sub">検索・フィルター・追加</span>
          </a>
          <a href="artists.php" class="action-tile">
            <?= tile_icon('purple', $ico['user']) ?>
            <span class="action-label">アーティストを探す</span>
            <span class="action-sub">一覧・タグ絞込</span>
          </a>
          <a href="songlists.php" class="action-tile">
            <?= tile_icon('green', $ico['list']) ?>
            <span class="action-label">ソングリスト</span>
            <span class="action-sub">作成・編集・切替</span>
          </a>
          <a href="songs.php?mytag=1" class="action-tile">
            <?= tile_icon('orange', $ico['star']) ?>
            <span class="action-label">マイタグ</span>
            <span class="action-sub">お気に入り・練習中</span>
          </a>
          <a href="builder.php" class="action-tile">
            <?= tile_icon('teal', $ico['bug']) ?>
            <span class="action-label">Builder</span>
            <span class="action-sub">楽曲を採集しに行く</span>
          </a>
          <div class="action-tile action-tile-empty">
            <?= tile_icon('muted', $ico['plus']) ?>
            <span class="action-label">準備中</span>
            <span class="action-sub">—</span>
          </div>
        </div>

        <!-- 統計 -->
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

        <!-- 最近追加 -->
        <p class="section-title">最近追加</p>
        <div class="recent-list">
          <?php foreach (array_slice($recentSongs, 0, 5) as $s): ?>
            <a href="song_detail.php?id=<?= $s['id'] ?>" class="recent-item">
              <svg class="recent-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
                <path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>
              </svg>
              <div>
                <div class="recent-item-title"><?= htmlspecialchars($s['title']) ?></div>
                <div class="recent-item-meta"><?= htmlspecialchars($s['artist_name'] ?? '—') ?><?= $s['release_year'] ? ' · ' . $s['release_year'] : '' ?></div>
              </div>
            </a>
          <?php endforeach; ?>
          <?php if (!$recentSongs): ?>
            <div class="recent-item"><span class="recent-item-meta">まだ曲が登録されていません</span></div>
          <?php endif; ?>
        </div>

      </div><!-- /mob-home -->

    </div>
  </div>
</div>

<script>
(function () {
  try {
    const key = 'activeList_<?= (int)$me['id'] ?>';
    const s = JSON.parse(localStorage.getItem(key) || 'null');
    if (!s || !s.id) return;

    // モバイルカード
    const card = document.getElementById('home-active-list');
    if (card) {
      card.classList.replace('no-list', 'has-list');
      card.querySelector('.active-list-card-name').textContent = s.name;
      card.querySelector('.active-list-card-meta').textContent = (s.count || 0) + '曲';
      card.href = 'songlists.php?id=' + s.id;
    }

    // デスクトップカード
    const dhName = document.getElementById('dh-active-list-name');
    const dhMeta = document.getElementById('dh-active-list-meta');
    const dhLink = document.getElementById('dh-active-list-link');
    if (dhName) dhName.textContent = s.name;
    if (dhMeta) dhMeta.textContent = (s.count || 0) + '曲';
    if (dhLink) dhLink.href = 'songlists.php?id=' + s.id;
  } catch {}
})();
</script>
</body>
</html>
