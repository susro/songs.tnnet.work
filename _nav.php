<?php
/* 使い方: 各ページで $activePage = 'home'|'songs'|'lists'|'admin' をセットしてinclude */
$_active = $activePage ?? '';
function _nav_cls(string $page, string $current): string {
    return $page === $current ? ' is-active' : '';
}
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <span class="sidebar-logo">♪</span>Songs.TNNET
  </div>
  <nav class="sidebar-nav">
    <a href="index.php"     class="sidebar-item<?= _nav_cls('home',  $_active) ?>"><span class="sidebar-icon">🏠</span>ホーム</a>
    <a href="songs.php"     class="sidebar-item<?= _nav_cls('songs', $_active) ?>"><span class="sidebar-icon">🔍</span>曲を探す</a>
    <a href="songlists.php" class="sidebar-item<?= _nav_cls('lists', $_active) ?>"><span class="sidebar-icon">📋</span>ソングリスト</a>
    <hr class="sidebar-divider">
  </nav>
  <div class="sidebar-footer">
    <a href="admin.php" class="sidebar-item<?= _nav_cls('admin', $_active) ?>"><span class="sidebar-icon">⚙</span>管理</a>
  </div>
</aside>

<nav class="bottom-nav">
  <a href="index.php"     class="bottom-nav-item<?= _nav_cls('home',  $_active) ?>"><span class="nav-icon">🏠</span><span class="nav-label">ホーム</span></a>
  <a href="songs.php"     class="bottom-nav-item<?= _nav_cls('songs', $_active) ?>"><span class="nav-icon">🔍</span><span class="nav-label">曲を探す</span></a>
  <a href="songlists.php" class="bottom-nav-item<?= _nav_cls('lists', $_active) ?>"><span class="nav-icon">📋</span><span class="nav-label">リスト</span></a>
  <a href="admin.php"     class="bottom-nav-item<?= _nav_cls('admin', $_active) ?>"><span class="nav-icon">⚙</span><span class="nav-label">管理</span></a>
</nav>
