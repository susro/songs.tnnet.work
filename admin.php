<?php
require_once 'config.php';

$keyword = trim((string)($_GET['q'] ?? ''));
$selectedArtistIds = array_map('intval', $_POST['artist_ids'] ?? []);
$action = (string)($_POST['action'] ?? '');
$runFetch = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bulk_fetch';
$runAddArtist = $_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_artist';
$results = [];
$errorMessage = '';
$successMessage = '';

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
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$artists = $stmt->fetchAll();
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

if ($runFetch) {
    if (!$selectedArtistIds) {
        $errorMessage = 'アーティストを1件以上選択してください。';
    } else {
        foreach ($selectedArtistIds as $artistId) {
            $results[] = fetchSongsForArtist($pdo, $artistId);
        }
    }
}

if ($runAddArtist) {
    $newArtistName = trim((string)($_POST['new_artist_name'] ?? ''));
    if ($newArtistName === '') {
        $errorMessage = '追加するアーティスト名を入力してください。';
    } else {
        $existsStmt = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
        $existsStmt->execute([$newArtistName]);
        $existsId = (int)$existsStmt->fetchColumn();
        if ($existsId > 0) {
            $successMessage = 'すでに登録済みのアーティストです。';
        } else {
            $insertStmt = $pdo->prepare("INSERT INTO artists (name) VALUES (?)");
            $insertStmt->execute([$newArtistName]);
            $successMessage = 'アーティストを追加しました。';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $artists = $stmt->fetchAll();
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
    <h1>曲を増やす！</h1>
    <nav class="top-nav">
        <a href="index.php">トップ</a>
        <a href="artists.php">アーティスト一覧</a>
        <a href="admin.php" class="is-active">曲を増やす！</a>
    </nav>

    <section class="panel-card">
        <h2>まとめて楽曲Get！</h2>
        <p class="panel-note">タグ複数選択 + テキスト検索でリアルタイムに絞り込み、マトリクスで選択できます。</p>

        <div class="search-form">
            <label>アーティスト検索: <input type="text" id="artist-filter-input" value="<?= htmlspecialchars($keyword) ?>" placeholder="アーティスト名"></label>
            <button type="button" id="open-add-artist-modal">未登録アーティストを追加</button>
            <a class="link-button" href="admin.php">クリア</a>
        </div>

        <div class="active-filters">
            <span>タグで絞り込み:</span>
            <button type="button" class="chip action-chip tag-filter is-active" data-tag="">すべて</button>
            <?php foreach ($allTags as $tagName): ?>
                <button type="button" class="chip action-chip tag-filter" data-tag="<?= htmlspecialchars($tagName) ?>">
                    <?= htmlspecialchars($tagName) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div class="error-box"><p class="error-text"><?= htmlspecialchars($errorMessage) ?></p></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="success-box"><p><?= htmlspecialchars($successMessage) ?></p></div>
        <?php endif; ?>

        <form method="post" id="bulk-get-form">
            <input type="hidden" name="action" value="bulk_fetch">
            <div id="selected-artist-inputs"></div>
            <div class="select-tools">
                <button type="button" id="toggle-visible-selection">表示中を選択</button>
                <button type="submit" class="launch-button">まとめて楽曲Get！</button>
            </div>

            <p class="result-meta">
                対象: <span id="visible-count"><?= count($artists) ?></span> / <?= count($artists) ?> 件（最大 300 件表示）
                ・選択中: <span id="selected-count"><?= count($selectedArtistIds) ?></span> 件
            </p>
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
                                <?php if (!empty($artist['last_fetch_at'])): ?>
                                    <span class="fetch-badge">Fetch済み</span>
                                <?php else: ?>
                                    <span class="fetch-badge fetch-badge-pending">未Fetch</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($artist['tags'])): ?>
                                <small>タグ: <?= htmlspecialchars($artist['tags']) ?></small>
                            <?php else: ?>
                                <small>タグ: なし</small>
                            <?php endif; ?>
                            <small>最終Fetch: <?= !empty($artist['last_fetch_at']) ? htmlspecialchars((string)$artist['last_fetch_at']) : '未実行' ?></small>
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
        <div class="select-tools">
            <button type="submit">追加する</button>
            <button type="button" id="close-add-artist-modal">閉じる</button>
        </div>
    </form>
</dialog>

<script>
var selectedTags = new Set();
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
  var keyword = normalizeText(document.getElementById('artist-filter-input').value);
  var visible = 0;
  document.querySelectorAll('.admin-card').forEach(function (card) {
    var name = normalizeText(card.dataset.name || '');
    var tags = (card.dataset.tags || '').split('|').filter(Boolean);
    var keywordMatch = keyword === '' || name.indexOf(keyword) !== -1;
    var tagMatch = selectedTags.size === 0 || tags.some(function (tag) { return selectedTags.has(tag); });
    var show = keywordMatch && tagMatch;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('visible-count').textContent = String(visible);
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
});

document.getElementById('artist-filter-input').addEventListener('input', applyArtistFilters);
document.querySelectorAll('.tag-filter').forEach(function (button) {
  button.addEventListener('click', function () {
    var tag = button.dataset.tag || '';
    if (tag === '') {
      selectedTags.clear();
      document.querySelectorAll('.tag-filter').forEach(function (b) { b.classList.remove('is-active'); });
      button.classList.add('is-active');
      applyArtistFilters();
      return;
    }
    document.querySelector('.tag-filter[data-tag=""]').classList.remove('is-active');
    if (selectedTags.has(tag)) {
      selectedTags.delete(tag);
      button.classList.remove('is-active');
    } else {
      selectedTags.add(tag);
      button.classList.add('is-active');
    }
    if (selectedTags.size === 0) {
      document.querySelector('.tag-filter[data-tag=""]').classList.add('is-active');
    }
    applyArtistFilters();
  });
});
var addArtistDialog = document.getElementById('add-artist-dialog');
document.getElementById('open-add-artist-modal').addEventListener('click', function () {
  addArtistDialog.showModal();
});
document.getElementById('close-add-artist-modal').addEventListener('click', function () {
  addArtistDialog.close();
});
syncCardState();
syncSelectedInputs();
applyArtistFilters();
</script>
</body>
</html>
