<?php
$_active = $activePage ?? '';
function _nav_cls(string $page, string $current): string {
    return $page === $current ? ' is-active' : '';
}

/* ── SVG icons ────────────────────────────── */
function icon(string $name): string {
    $icons = [
        'home'   => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'music'  => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'list'   => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'star'   => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        'tool'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'plus'   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    ];
    $path = $icons[$name] ?? $icons['plus'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
?>
<!-- PC topbar + aoiro-tabs (desktop only, hidden on mobile) -->
<div class="pc-header">
  <div class="topbar">
    <?= icon('music') ?>&nbsp;Songs.TNNET
    <div class="topbar-user">
      <?php $__u = current_user(); if ($__u): ?>
      <span><?= htmlspecialchars($__u['name']) ?> さん</span>
      <a href="logout.php">ログアウト</a>
      <?php endif; ?>
    </div>
  </div>
  <nav class="aoiro-tabs">
    <a href="index.php"     class="aoiro-tab<?= _nav_cls('home',    $_active) ?>">ホーム</a>
    <a href="songs.php"     class="aoiro-tab<?= _nav_cls('songs',   $_active) ?>">曲を探す</a>
    <a href="artists.php"   class="aoiro-tab<?= _nav_cls('artists', $_active) ?>">アーティスト</a>
    <a href="songlists.php" class="aoiro-tab<?= _nav_cls('lists',   $_active) ?>">ソングリスト</a>
    <a href="builder.php"   class="aoiro-tab<?= _nav_cls('builder', $_active) ?>">Builder</a>
  </nav>
</div>

<aside class="sidebar">
  <div class="sidebar-brand">
    <span class="sidebar-logo"><?= icon('music') ?></span>Songs.TNNET
  </div>
  <nav class="sidebar-nav">
    <a href="index.php"     class="sidebar-item<?= _nav_cls('home',    $_active) ?>"><span class="sidebar-icon"><?= icon('home')   ?></span>ホーム</a>
    <a href="songs.php"     class="sidebar-item<?= _nav_cls('songs',   $_active) ?>"><span class="sidebar-icon"><?= icon('music')  ?></span>曲を探す</a>
    <a href="artists.php"   class="sidebar-item<?= _nav_cls('artists', $_active) ?>"><span class="sidebar-icon"><?= icon('user')   ?></span>アーティスト</a>
    <a href="songlists.php" class="sidebar-item<?= _nav_cls('lists',   $_active) ?>"><span class="sidebar-icon"><?= icon('list')   ?></span>ソングリスト</a>
    <hr class="sidebar-divider">
  </nav>
  <div class="sidebar-footer">
    <?php if (!empty($_active) || true): if (!isset($__u)) $__u = current_user(); ?>
    <?php if ($__u): ?>
    <div class="sidebar-user">
      <span class="sidebar-user-name"><?= htmlspecialchars($__u['name']) ?></span>
      <a href="logout.php" class="sidebar-logout">ログアウト</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <a href="builder.php" class="sidebar-item<?= _nav_cls('builder', $_active) ?>"><span class="sidebar-icon"><?= icon('tool') ?></span>Builder</a>
  </div>
</aside>

<nav class="bottom-nav">
  <a href="index.php"     class="bottom-nav-item<?= _nav_cls('home',    $_active) ?>"><?= icon('home')   ?><span class="nav-label">ホーム</span></a>
  <a href="songs.php"     class="bottom-nav-item<?= _nav_cls('songs',   $_active) ?>"><?= icon('music')  ?><span class="nav-label">曲を探す</span></a>
  <a href="artists.php"   class="bottom-nav-item<?= _nav_cls('artists', $_active) ?>"><?= icon('user')   ?><span class="nav-label">アーティスト</span></a>
  <a href="songlists.php" class="bottom-nav-item<?= _nav_cls('lists',   $_active) ?>"><?= icon('list')   ?><span class="nav-label">リスト</span></a>
  <a href="logout.php"    class="bottom-nav-item" onclick="return confirm('ログアウトしますか？')"><?= icon('logout') ?><span class="nav-label">ログアウト</span></a>
</nav>

<!-- ── Theme + Density Switcher ── -->
<details class="theme-switcher" id="theme-switcher">
  <summary>⚙ テーマ</summary>
  <div class="theme-switcher-body">
    <div class="theme-switcher-row">
      <span class="theme-switcher-label">テーマ</span>
      <div class="theme-switcher-opts" id="ts-themes">
        <button data-theme="B-biz">Biz</button>
        <button data-theme="A-dark">A-dark</button>
        <button data-theme="A-light">A-light</button>
        <button data-theme="C-light">C-light</button>
        <button data-theme="C-dark">C-dark</button>
      </div>
    </div>
    <div class="theme-switcher-row">
      <span class="theme-switcher-label">密度</span>
      <div class="theme-switcher-opts" id="ts-density">
        <button data-density="compact">コンパクト</button>
        <button data-density="cozy">標準</button>
        <button data-density="roomy">ゆったり</button>
      </div>
    </div>
  </div>
</details>

<script>
(function () {
  var LS = 'songs-tnnet-ui';
  function load() {
    try { return JSON.parse(localStorage.getItem(LS) || '{}'); } catch(e) { return {}; }
  }
  function save(obj) {
    try { localStorage.setItem(LS, JSON.stringify(obj)); } catch(e) {}
  }
  function apply(prefs) {
    if (prefs.theme)   document.documentElement.dataset.theme   = prefs.theme;
    if (prefs.density) document.documentElement.dataset.density = prefs.density;
  }

  var prefs = load();
  if (!prefs.theme)   prefs.theme   = 'B-biz';
  if (!prefs.density) prefs.density = 'cozy';
  apply(prefs);

  document.addEventListener('DOMContentLoaded', function () {
    function markActive() {
      document.querySelectorAll('#ts-themes button').forEach(function (b) {
        b.classList.toggle('ts-on', b.dataset.theme === document.documentElement.dataset.theme);
      });
      document.querySelectorAll('#ts-density button').forEach(function (b) {
        b.classList.toggle('ts-on', b.dataset.density === document.documentElement.dataset.density);
      });
    }
    document.querySelectorAll('#ts-themes button').forEach(function (b) {
      b.addEventListener('click', function () {
        prefs.theme = b.dataset.theme;
        apply(prefs); save(prefs); markActive();
      });
    });
    document.querySelectorAll('#ts-density button').forEach(function (b) {
      b.addEventListener('click', function () {
        prefs.density = b.dataset.density;
        apply(prefs); save(prefs); markActive();
      });
    });
    markActive();
  });
})();
</script>
