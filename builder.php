<?php
require_once 'config.php';

$keyword = trim((string)($_POST['filter_keyword'] ?? $_GET['q'] ?? ''));
$selectedTagsInitial = array_values(array_filter(array_unique(explode('|', trim((string)($_POST['selected_tags'] ?? ''))))));
$selectedArtistIds = array_map('intval', $_POST['artist_ids'] ?? []);
$action = (string)($_POST['action'] ?? '');
$runFetch = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_fetch';
$runAddArtist = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_artist';
$results = [];
$errorMessage = '';
$successMessage = '';
$modalMessage = '';
$modalMessageType = '';

function fetchSongsForArtist(PDO $pdo, int $artistId): array {
    $stmt = $pdo->prepare("SELECT name FROM artists WHERE id = ?");
    $stmt->execute([$artistId]);
    $artistName = (string)$stmt->fetchColumn();
    if ($artistName === '') {
        return ['artist_id' => $artistId, 'artist_name' => '(不明)', 'inserted' => 0, 'skipped' => 0, 'error' => 'アーティストが見つかりません'];
    }

    $stmt = $pdo->prepare("SELECT alias FROM artist_aliases WHERE artist_id = ?");
    $stmt->execute([$artistId]);
    $aliases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $keywords = array_unique(array_filter(array_merge([$artistName], $aliases)));
    $inserted = 0;
    $skipped = 0;
    $hasApiResponse = false;

    foreach ($keywords as $word) {
        $url = "https://itunes.apple.com/search?term=" . urlencode($word) . "&country=jp&entity=song&limit=200";
        $jsonText = @file_get_contents($url);
        if ($jsonText === false) {
            continue;
        }
        $json = json_decode($jsonText, true);
        if (!$json || !isset($json['results']) || !is_array($json['results'])) {
            continue;
        }
        $hasApiResponse = true;
        if (empty($json['results'])) {
            continue;
        }

        foreach ($json['results'] as $song) {
            $title = trim((string)($song['trackName'] ?? ''));
            if ($title === '') {
                continue;
            }
            $year = substr((string)($song['releaseDate'] ?? ''), 0, 4);
            $yearValue = ctype_digit($year) ? (int)$year : null;

            $dup = $pdo->prepare("SELECT COUNT(*) FROM songs WHERE title = ? AND artist_id = ?");
            $dup->execute([$title, $artistId]);
            if ((int)$dup->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            $insert = $pdo->prepare("INSERT INTO songs (title, artist_id, release_year) VALUES (?, ?, ?)");
            $insert->execute([$title, $artistId, $yearValue]);
            $inserted++;
        }
    }

    $update = $pdo->prepare("
        UPDATE artists
        SET fetch_attempts = fetch_attempts + 1,
            last_fetch_at = NOW(),
            fetch_failed = ?
        WHERE id = ?
    ");
    $update->execute([$hasApiResponse ? 0 : 1, $artistId]);

    return ['artist_id' => $artistId, 'artist_name' => $artistName, 'inserted' => $inserted, 'skipped' => $skipped, 'error' => ''];
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
        'decade' => ['年代タグなし'],
        'genre' => ['ジャンルタグなし'],
        'other' => ['タグなし'],
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
    return $tagGroups;
};

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$artists = $stmt->fetchAll();
$tagGroups = $buildTagGroups($artists);

if ($runFetch) {
    if (!$selectedArtistIds) {
        $errorMessage = 'アーティストを1件以上選択してください。';
    } else {
        foreach ($selectedArtistIds as $artistId) {
            $results[] = fetchSongsForArtist($pdo, $artistId);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $artists = $stmt->fetchAll();
        $tagGroups = $buildTagGroups($artists);
    }
}

$totalInserted = 0;
foreach ($results as $resultRow) {
    $totalInserted += (int)$resultRow['inserted'];
}

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
    </nav>

    <section class="panel-card">
        <h2>まとめて楽曲Get！</h2>

        <div class="search-form">
            <input type="text" id="artist-filter-input" value="<?= htmlspecialchars($keyword) ?>" placeholder="アーティスト名で絞り込み">
            <a class="link-button" href="builder.php">クリア</a>
        </div>

        <div class="active-filters"></div>
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
            <input type="hidden" name="action" value="bulk_fetch">
            <input type="hidden" name="filter_keyword" id="filter_keyword_input" value="<?= htmlspecialchars($keyword) ?>">
            <input type="hidden" name="selected_tags" id="selected_tags_input" value="<?= htmlspecialchars(implode('|', $selectedTagsInitial)) ?>">
            <div id="selected-artist-inputs"></div>
            <div class="select-tools">
                <button type="button" id="toggle-visible-selection">表示中を選択</button>
                <button type="submit" class="launch-button">まとめて楽曲Get！</button>
            </div>

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
  window.scrollTo(0, prevScrollY);
}

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

document.getElementById('toggle-visible-selection').addEventListener('click', function () {
  var visibleCards = Array.from(document.querySelectorAll('.admin-card')).filter(function (card) {
    return card.style.display !== 'none';
  });
  var allVisibleSelected = visibleCards.length > 0 && visibleCards.every(function (card) {
    return selectedArtistIds.has(Number(card.dataset.artistId || 0));
  });
  toggleVisibleSelectionByState(!allVisibleSelected);
  this.textContent = allVisibleSelected ? '表示中を選択' : '表示中を解除';
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
<?php if ($runFetch): ?>
document.getElementById('loading-overlay').hidden = true;
document.getElementById('fetch-result-summary').textContent = "<?= count($results) ?>アーティストで <?= (int)$totalInserted ?>曲 を取り込みました！";
fetchResultDialog.showModal();
<?php endif; ?>
document.getElementById('close-add-artist-result').addEventListener('click', function () {
  document.getElementById('add-artist-result-dialog').close();
});
</script>
</body>
</html>
