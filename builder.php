<?php
require_once 'config.php';
require_once 'fetch_helpers.php';
$me = require_admin();

$keyword = trim((string)($_POST['filter_keyword'] ?? $_GET['q'] ?? ''));
$selectedTagsInitial = array_values(array_filter(array_unique(explode('|', trim((string)($_POST['selected_tags'] ?? ''))))));
$selectedArtistIds = array_map('intval', $_POST['artist_ids'] ?? []);
$action = (string)($_POST['action'] ?? '');
$runPrepare = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'prepare_fetch';
$runCommit = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'commit_fetch';
$runClearSession = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'clear_fetch_session';
$runAddArtist  = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_artist';
$runAddInvite  = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_invite';
$runDelInvite  = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'del_invite';
$results = [];
$importCandidates = $_SESSION['builder_fetch_candidates'] ?? [];
$errorMessage = '';
$successMessage = '';
$modalMessage = '';
$modalMessageType = '';
$fetchSummary = null;

if ($runPrepare) {
    if (!$selectedArtistIds) {
        $errorMessage = 'アーティストを1件以上選択してください。';
    } else {
        $importCandidates = [];
        $totalCandidates = 0;
        foreach ($selectedArtistIds as $artistId) {
            $candidate = fetchCandidateSongsForArtist($pdo, $artistId);
            $totalCandidates += count($candidate['candidates']);
            $importCandidates[$artistId] = $candidate;
        }
        $_SESSION['builder_fetch_candidates'] = $importCandidates;
        $fetchSummary = [
            'artists' => count($importCandidates),
            'songs' => $totalCandidates,
        ];
    }
}

if ($runCommit) {
    if (empty($_SESSION['builder_fetch_candidates'])) {
        $errorMessage = '取り込み候補が見つかりません。まずはアーティストを選択して候補を取得してください。';
    } else {
        $commitResult = commitCandidateImport($pdo, $_SESSION['builder_fetch_candidates']);
        unset($_SESSION['builder_fetch_candidates']);
        $importCandidates = [];
        $successMessage = sprintf('取り込み完了: %d 曲を %d アーティストから登録しました。', $commitResult['inserted'], $commitResult['artist_count']);
    }
}

if ($runClearSession) {
    unset($_SESSION['builder_fetch_candidates']);
    $importCandidates = [];
    $successMessage = '候補をクリアしました。';
}

if ($fetchSummary === null && $importCandidates) {
    $songCount = 0;
    foreach ($importCandidates as $candidateGroup) {
        $songCount += count($candidateGroup['candidates']);
    }
    $fetchSummary = [
        'artists' => count($importCandidates),
        'songs' => $songCount,
    ];
}

$where = [];
$params = [];
$sql = "SELECT
            a.id,
            a.name,
            a.fetch_attempts,
            a.last_fetch_at,
            a.fetch_failed,
            GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ' / ') AS tags
        FROM artists a
        LEFT JOIN artist_tags at ON a.id = at.artist_id
        LEFT JOIN tags t ON at.tag_id = t.id";
if ($keyword !== '') {
    $where[] = 'a.name LIKE ?';
    $params[] = "%{$keyword}%";
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY a.id ORDER BY a.name ASC LIMIT 300';
$buildTagGroups = function (array $artists): array {
    $allTags = [];
    foreach ($artists as $artist) {
        if (!empty($artist['tags'])) {
            foreach (explode(' / ', $artist['tags']) as $tagName) {
                $tagName = trim($tagName);
                if ($tagName !== '') {
                    $allTags[$tagName] = true;
                }
            }
        }
    }
    $allTags = array_keys($allTags);
    sort($allTags, SORT_NATURAL);
    $tagGroups = [
        'decade' => [],
        'genre' => [],
        'other' => [],
    ];
    foreach ($allTags as $tagName) {
        if (preg_match('/^[0-9]{4}年代$/u', $tagName)) {
            $tagGroups['decade'][] = $tagName;
        } elseif (preg_match('/(ロック|ポップ|アニソン|ボカロ|アイドル|ジャズ|クラシック|R&B|HIPHOP|V系|系)$/u', $tagName)) {
            $tagGroups['genre'][] = $tagName;
        } else {
            $tagGroups['other'][] = $tagName;
        }
    }
    $tagGroups['decade'][] = '年代タグなし';
    $tagGroups['genre'][] = 'ジャンルタグなし';
    $tagGroups['other'][] = 'タグなし';
    return $tagGroups;
};

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$artists = $stmt->fetchAll();
$tagGroups = $buildTagGroups($artists);

/* ── 招待コード追加 ── */
if ($runAddInvite) {
    $invName = mb_substr(trim((string)($_POST['inv_name'] ?? '')), 0, 50);
    $invCode = trim((string)($_POST['inv_code'] ?? ''));
    if ($invName !== '' && preg_match('/^[\w\-]{4,32}$/', $invCode)) {
        try {
            $pdo->prepare("INSERT INTO users (name, invite_code) VALUES (?, ?)")->execute([$invName, $invCode]);
            $successMessage = "「{$invName}」を招待しました。";
        } catch (PDOException $e) {
            $errorMessage = 'そのコードはすでに使われています。';
        }
    } else {
        $errorMessage = '名前（必須）とコード（英数字・ハイフン 4〜32文字）を入力してください。';
    }
}

/* ── 招待コード削除 ── */
if ($runDelInvite) {
    $delId = (int)($_POST['del_id'] ?? 0);
    if ($delId && $delId !== (int)$me['id']) {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND is_admin = 0")->execute([$delId]);
        $successMessage = 'ユーザーを削除しました。';
    }
}

/* ── ユーザー一覧（招待管理用） ── */
$userList = $pdo->query("SELECT id, name, invite_code, is_admin, created_at FROM users ORDER BY id ASC")->fetchAll();

if ($runAddArtist) {
    $newArtistName = trim((string)($_POST['new_artist_name'] ?? ''));
    $confirmVariant = (string)($_POST['confirm_variant'] ?? '') === '1';
    if ($newArtistName === '') {
        $modalMessage = '追加するアーティスト名を入力してください。';
        $modalMessageType = 'error';
    } else {
        $existsStmt = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
        $existsStmt->execute([$newArtistName]);
        $existsId = (int)$existsStmt->fetchColumn();
        if ($existsId > 0) {
            $modalMessage = 'すでに登録済みのアーティストです。';
            $modalMessageType = 'info';
        } else {
            $suggestUrl = "https://itunes.apple.com/search?term=" . urlencode($newArtistName) . "&country=jp&entity=musicArtist&limit=1";
            $suggestJson = json_decode((string)@file_get_contents($suggestUrl), true);
            $suggestedName = trim((string)($suggestJson['results'][0]['artistName'] ?? ''));
            if ($suggestedName !== '' && $suggestedName !== $newArtistName && !$confirmVariant) {
                $existsSuggestStmt = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
                $existsSuggestStmt->execute([$suggestedName]);
                if ((int)$existsSuggestStmt->fetchColumn() > 0) {
                    $modalMessage = "既存の候補「{$suggestedName}」があります。このアーティストなら確認にチェックしてください。";
                    $modalMessageType = 'error';
                }
            }
        }
        if ($modalMessage === '' && $existsId === 0) {
            $insertStmt = $pdo->prepare("INSERT INTO artists (name) VALUES (?)");
            $insertStmt->execute([$newArtistName]);
            $modalMessage = $newArtistName . ' を追加しました。';
            $modalMessageType = 'success';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $artists = $stmt->fetchAll();
            $tagGroups = $buildTagGroups($artists);
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>曲を増やす！</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
    <div class="page-head">
        <h1>曲を増やす！</h1>
        <div class="theme-switch">
            <span>テーマ:</span>
            <select id="theme-select" class="theme-select">
                <option value="theme-neon">ネオン</option>
                <option value="theme-sunset">サンセット</option>
                <option value="theme-mint">ミント</option>
                <option value="theme-cream-a">クリームA</option>
                <option value="theme-cream-b">クリームB</option>
                <option value="theme-cream-c">クリームC</option>
            </select>
        </div>
    </div>
    <nav class="top-nav">
        <a href="index.php">トップ</a>
        <a href="artists.php">アーティスト一覧</a>
        <a href="builder.php" class="is-active">曲を増やす！</a>
        <a href="import_history.php">取り込み履歴</a>
    </nav>

    <!-- ── 招待コード管理 ── -->
    <section class="panel-card" style="margin-bottom:16px">
        <h2>メンバー管理</h2>
        <?php if ($errorMessage): ?>
            <div class="error-box"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>
        <?php if ($successMessage): ?>
            <div class="success-box"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>

        <table class="data-table" style="margin-bottom:14px">
            <thead><tr><th>名前</th><th>招待コード</th><th>権限</th><th>登録日</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($userList as $u): ?>
                <tr>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td><code><?= htmlspecialchars($u['invite_code']) ?></code></td>
                    <td><?= $u['is_admin'] ? '管理者' : 'メンバー' ?></td>
                    <td><?= date('Y/m/d', strtotime($u['created_at'])) ?></td>
                    <td>
                        <?php if (!$u['is_admin']): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('削除しますか？')">
                            <input type="hidden" name="action"  value="del_invite">
                            <input type="hidden" name="del_id" value="<?= $u['id'] ?>">
                            <button class="btn-danger" style="height:28px;font-size:12px;padding:0 10px">削除</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" class="search-form">
            <input type="hidden" name="action" value="add_invite">
            <input type="text"   name="inv_name" placeholder="名前"   maxlength="50" style="width:140px" required>
            <input type="text"   name="inv_code" placeholder="招待コード（英数字・ハイフン）" maxlength="32" style="width:220px" required autocomplete="off">
            <button type="submit" class="search-form button" style="height:30px;padding:0 14px;background:var(--blue);color:#fff;border:none;border-radius:3px;font-weight:700">招待追加</button>
        </form>
    </section>

    <section class="panel-card">
        <h2>まとめて楽曲Get！</h2>

        <?php if (!$errorMessage && !$successMessage): // 上で表示済みのため ?>
        <?php endif; ?>

        <div class="search-form">
            <input type="text" id="artist-filter-input" value="<?= htmlspecialchars($keyword) ?>" placeholder="アーティスト名で絞り込み">
            <a class="link-button" href="builder.php">クリア</a>
        </div>

        <div class="tag-clear-slot">
            <button type="button" class="chip action-chip" id="clear-tag-filters" hidden>タグをクリア</button>
        </div>
        <div class="tag-groups-grid">
            <?php foreach ($tagGroups as $tags): ?>
                <?php if (!$tags) continue; ?>
                <div class="tag-group">
                    <div class="quick-chip-grid">
                        <?php foreach ($tags as $tagName): ?>
                            <button type="button" class="chip action-chip tag-filter jelly-chip" data-tag="<?= htmlspecialchars($tagName) ?>">
                                <?= htmlspecialchars($tagName) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" id="bulk-get-form">
            <input type="hidden" name="action" value="prepare_fetch">
            <input type="hidden" name="filter_keyword" id="filter_keyword_input" value="<?= htmlspecialchars($keyword) ?>">
            <input type="hidden" name="selected_tags" id="selected_tags_input" value="<?= htmlspecialchars(implode('|', $selectedTagsInitial)) ?>">
            <div id="selected-artist-inputs"></div>
            <div class="select-tools">
                <button type="button" id="toggle-visible-selection">表示中を選択</button>
                <button type="submit" class="launch-button">まとめて楽曲Get！</button>
            </div>

            <?php if ($fetchSummary !== null): ?>
                <div class="panel-card" style="margin-top: 16px;">
                    <h3>取り込み候補</h3>
                    <p>候補アーティスト: <?= (int)$fetchSummary['artists'] ?>件</p>
                    <p>候補曲数: <?= (int)$fetchSummary['songs'] ?>曲</p>
                    <?php if ((int)$fetchSummary['songs'] > 0): ?>
                        <div class="select-tools">
                            <button type="submit" name="action" value="commit_fetch" class="launch-button">このまま一括取込み</button>
                            <a class="link-button" href="builder_select.php">選択取込みへ進む</a>
                            <button type="submit" name="action" value="clear_fetch_session" class="link-button">候補をクリア</button>
                        </div>
                    <?php else: ?>
                        <p>候補曲が見つかりませんでした。検索条件やアーティスト選択を見直してください。</p>
                        <div class="select-tools">
                            <button type="submit" name="action" value="clear_fetch_session" class="link-button">候補をクリア</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="meta-row">
                <p class="result-meta">
                    対象: <span id="visible-count"><?= count($artists) ?></span> / <?= count($artists) ?> 件（最大 300 件表示）
                    ・選択中: <span id="selected-count"><?= count($selectedArtistIds) ?></span> 件
                </p>
                <div class="admin-side-tools">
                    <button type="button" id="open-add-artist-modal">未登録アーティストを追加</button>
                </div>
            </div>
            <div class="admin-matrix" id="admin-matrix">
                <?php foreach ($artists as $artist): ?>
                    <?php $tagList = !empty($artist['tags']) ? explode(' / ', $artist['tags']) : []; ?>
                    <label class="admin-card"
                           data-artist-id="<?= (int)$artist['id'] ?>"
                           data-name="<?= htmlspecialchars(strtolower($artist['name']), ENT_QUOTES, 'UTF-8') ?>"
                           data-tags="<?= htmlspecialchars(implode('|', $tagList), ENT_QUOTES, 'UTF-8') ?>"
                           data-selected="<?= in_array((int)$artist['id'], $selectedArtistIds, true) ? '1' : '0' ?>">
                        <div class="admin-card-body">
                            <div class="admin-card-header">
                                <strong><?= htmlspecialchars($artist['name']) ?></strong>
                                <?php if (empty($artist['last_fetch_at'])): ?>
                                    <span class="fetch-badge fetch-badge-pending">未Get</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($artist['tags'])): ?>
                                <small>タグ: <?= htmlspecialchars($artist['tags']) ?></small>
                            <?php else: ?>
                                <small>タグ: なし</small>
                            <?php endif; ?>
                            <small>最終採集日: <?= !empty($artist['last_fetch_at']) ? htmlspecialchars(substr((string)$artist['last_fetch_at'], 0, 10)) : '未実行' ?></small>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </form>
    </section>

    <?php if ($results): ?>
        <section class="panel-card">
            <h2>実行結果</h2>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>アーティスト</th>
                        <th>追加</th>
                        <th>既存</th>
                        <th>状態</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['artist_name']) ?></td>
                            <td><?= (int)$row['inserted'] ?></td>
                            <td><?= (int)$row['skipped'] ?></td>
                            <td><?= $row['error'] !== '' ? htmlspecialchars($row['error']) : 'OK' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<dialog id="add-artist-dialog" class="add-artist-dialog">
    <form method="post" class="panel-card">
        <input type="hidden" name="action" value="add_artist">
        <h2>未登録アーティストを追加</h2>
        <p class="panel-note">まずは名前だけ登録します。必要なら後で別名やタグを追加できます。</p>
        <label>アーティスト名: <input type="text" name="new_artist_name" required></label>
        <label class="inline-check"><input type="checkbox" name="confirm_variant" value="1"> 表記ゆれの可能性があっても追加する</label>
        <div class="select-tools">
            <button type="submit">追加する</button>
            <button type="button" id="close-add-artist-modal">閉じる</button>
        </div>
    </form>
</dialog>

<dialog id="add-artist-result-dialog" class="add-artist-dialog">
    <div class="panel-card">
        <h2>アーティスト追加</h2>
        <p id="add-artist-result-text"></p>
        <div class="select-tools">
            <button type="button" id="close-add-artist-result">OK</button>
        </div>
    </div>
</dialog>

<dialog id="fetch-result-dialog" class="add-artist-dialog">
    <div class="panel-card">
        <h2>採集完了</h2>
        <p id="fetch-result-summary"></p>
        <div class="select-tools">
            <button type="button" id="close-fetch-result">OK</button>
        </div>
    </div>
</dialog>

<div id="loading-overlay" class="loading-overlay" hidden>
    <div class="loading-box">
        <div class="spinner"></div>
        <p>採集中！しばらくお待ちください...</p>
    </div>
</div>

<script>
var selectedTags = new Set(<?= json_encode(array_values($selectedTagsInitial), JSON_UNESCAPED_UNICODE) ?>);
var selectedArtistIds = new Set([<?= implode(',', array_map('intval', $selectedArtistIds)) ?>]);
var clearTagFiltersButton = document.getElementById('clear-tag-filters');
var toggleVisibleSelectionButton = document.getElementById('toggle-visible-selection');

function syncSelectedInputs() {
  var box = document.getElementById('selected-artist-inputs');
  box.innerHTML = '';
  Array.from(selectedArtistIds).forEach(function (id) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'artist_ids[]';
    input.value = String(id);
    box.appendChild(input);
  });
  document.getElementById('selected-count').textContent = String(selectedArtistIds.size);
  document.getElementById('selected_tags_input').value = Array.from(selectedTags).join('|');
  document.getElementById('filter_keyword_input').value = document.getElementById('artist-filter-input').value;
}

function syncCardState() {
  document.querySelectorAll('.admin-card').forEach(function (card) {
    var id = Number(card.dataset.artistId || 0);
    var selected = selectedArtistIds.has(id);
    card.classList.toggle('is-selected', selected);
    card.dataset.selected = selected ? '1' : '0';
  });
}

function updateToggleVisibleButton() {
  var visibleCards = Array.from(document.querySelectorAll('.admin-card')).filter(function (card) {
    return card.style.display !== 'none';
  });
  var allVisibleSelected = visibleCards.length > 0 && visibleCards.every(function (card) {
    return selectedArtistIds.has(Number(card.dataset.artistId || 0));
  });
  toggleVisibleSelectionButton.textContent = allVisibleSelected ? '表示中を解除' : '表示中を選択';
}

function updateClearTagButton() {
  clearTagFiltersButton.hidden = selectedTags.size === 0;
}

function toggleVisibleSelectionByState(selectVisible) {
  document.querySelectorAll('.admin-card').forEach(function (card) {
    if (card.style.display === 'none') return;
    var id = Number(card.dataset.artistId || 0);
    if (!id) return;
    if (selectVisible) {
      selectedArtistIds.add(id);
    } else {
      selectedArtistIds.delete(id);
    }
  });
  syncCardState();
  syncSelectedInputs();
  updateToggleVisibleButton();
}

function normalizeText(value) {
  return (value || '').toLowerCase();
}

function applyArtistFilters() {
  var prevScrollY = window.scrollY;
  var keyword = normalizeText(document.getElementById('artist-filter-input').value);
  var visible = 0;
  document.querySelectorAll('.admin-card').forEach(function (card) {
    var name = normalizeText(card.dataset.name || '');
    var tags = (card.dataset.tags || '').split('|').filter(Boolean);
    var keywordMatch = keyword === '' || name.indexOf(keyword) !== -1;
    var hasDecadeTag = tags.some(function (tag) { return /^[0-9]{4}年代$/.test(tag); });
    var hasGenreTag = tags.some(function (tag) { return /(ロック|ポップ|アニソン|ボカロ|アイドル|ジャズ|クラシック|R&B|HIPHOP|V系|系)$/.test(tag); });
    var tagMatch = selectedTags.size === 0 || Array.from(selectedTags).some(function (selectedTag) {
      if (selectedTag === 'タグなし') return tags.length === 0;
      if (selectedTag === '年代タグなし') return !hasDecadeTag;
      if (selectedTag === 'ジャンルタグなし') return !hasGenreTag;
      return tags.indexOf(selectedTag) !== -1;
    });
    var show = keywordMatch && tagMatch;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('visible-count').textContent = String(visible);
  updateClearTagButton();
  updateToggleVisibleButton();
  window.scrollTo(0, prevScrollY);
}

clearTagFiltersButton.addEventListener('click', function () {
  selectedTags.clear();
  document.querySelectorAll('.tag-filter.is-active').forEach(function (tagButton) {
    tagButton.classList.remove('is-active');
  });
  applyArtistFilters();
  syncSelectedInputs();
});

document.querySelectorAll('.admin-card').forEach(function (card) {
  card.addEventListener('click', function (event) {
    event.preventDefault();
    var id = Number(card.dataset.artistId || 0);
    if (!id) return;
    if (selectedArtistIds.has(id)) {
      selectedArtistIds.delete(id);
    } else {
      selectedArtistIds.add(id);
    }
    syncCardState();
    syncSelectedInputs();
  });
});

toggleVisibleSelectionButton.addEventListener('click', function () {
  var visibleCards = Array.from(document.querySelectorAll('.admin-card')).filter(function (card) {
    return card.style.display !== 'none';
  });
  var allVisibleSelected = visibleCards.length > 0 && visibleCards.every(function (card) {
    return selectedArtistIds.has(Number(card.dataset.artistId || 0));
  });
  toggleVisibleSelectionByState(!allVisibleSelected);
});

document.getElementById('bulk-get-form').addEventListener('submit', function () {
  syncSelectedInputs();
  document.getElementById('loading-overlay').hidden = false;
});

document.getElementById('artist-filter-input').addEventListener('input', applyArtistFilters);
document.querySelectorAll('.tag-filter').forEach(function (button) {
  if (selectedTags.has(button.dataset.tag || '')) {
    button.classList.add('is-active');
  }
  button.addEventListener('click', function () {
    var prevScrollY = window.scrollY;
    var tag = button.dataset.tag || '';
    if (selectedTags.has(tag)) {
      selectedTags.delete(tag);
      button.classList.remove('is-active');
    } else {
      selectedTags.add(tag);
      button.classList.add('is-active');
    }
    window.scrollTo(0, prevScrollY);
    applyArtistFilters();
    syncSelectedInputs();
  });
});
var addArtistDialog = document.getElementById('add-artist-dialog');
document.getElementById('open-add-artist-modal').addEventListener('click', function () {
  addArtistDialog.showModal();
});
document.getElementById('close-add-artist-modal').addEventListener('click', function () {
  addArtistDialog.close();
});
var fetchResultDialog = document.getElementById('fetch-result-dialog');
var closeFetchResult = document.getElementById('close-fetch-result');
if (closeFetchResult) {
  closeFetchResult.addEventListener('click', function () {
    fetchResultDialog.close();
  });
}
var themeSelect = document.getElementById('theme-select');
themeSelect.addEventListener('change', function () {
  var theme = themeSelect.value;
  document.body.classList.remove('theme-neon', 'theme-sunset', 'theme-mint', 'theme-cream-a', 'theme-cream-b', 'theme-cream-c');
  document.body.classList.add(theme);
  localStorage.setItem('songsTheme', theme);
});
var savedTheme = localStorage.getItem('songsTheme') || 'theme-neon';
document.body.classList.remove('theme-neon', 'theme-sunset', 'theme-mint', 'theme-cream-a', 'theme-cream-b', 'theme-cream-c');
document.body.classList.add(savedTheme);
themeSelect.value = savedTheme;
syncCardState();
syncSelectedInputs();
document.getElementById('loading-overlay').hidden = true;
applyArtistFilters();
<?php if ($runAddArtist && $modalMessage !== ''): ?>
addArtistDialog.close();
document.getElementById('add-artist-result-text').textContent = "<?= htmlspecialchars($modalMessage, ENT_QUOTES, 'UTF-8') ?>";
document.getElementById('add-artist-result-dialog').showModal();
<?php endif; ?>
document.getElementById('close-add-artist-result').addEventListener('click', function () {
  document.getElementById('add-artist-result-dialog').close();
});
</script>
</body>
</html>
