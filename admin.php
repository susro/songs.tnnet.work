<?php
require_once 'config.php';

$keyword = trim((string)($_GET['q'] ?? ''));
$selectedArtistIds = array_map('intval', $_POST['artist_ids'] ?? []);
$runFetch = $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'bulk_fetch');
$results = [];
$errorMessage = '';

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
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理モード</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="container">
    <h1>管理モード</h1>
    <nav class="top-nav">
        <a href="index.php">トップ</a>
        <a href="artists.php">アーティスト一覧</a>
        <a href="admin.php" class="is-active">管理モード</a>
    </nav>

    <section class="panel-card">
        <h2>一括取得（Fetch）</h2>
        <p class="panel-note">タグ複数選択 + テキスト検索でリアルタイムに絞り込み、マトリクスで選択できます。</p>

        <div class="search-form">
            <label>アーティスト検索: <input type="text" id="artist-filter-input" value="<?= htmlspecialchars($keyword) ?>" placeholder="アーティスト名"></label>
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

        <form method="post">
            <input type="hidden" name="action" value="bulk_fetch">
            <div class="select-tools">
                <button type="button" onclick="toggleArtistSelection(true)">全選択</button>
                <button type="button" onclick="toggleArtistSelection(false)">全解除</button>
                <button type="button" onclick="toggleVisibleSelection(true)">表示中を選択</button>
                <button type="button" onclick="toggleVisibleSelection(false)">表示中を解除</button>
                <button type="submit">選択アーティストで一括Fetch</button>
            </div>

            <p class="result-meta">対象: <span id="visible-count"><?= count($artists) ?></span> / <?= count($artists) ?> 件（最大 300 件表示）</p>
            <div class="admin-matrix" id="admin-matrix">
                <?php foreach ($artists as $artist): ?>
                    <?php $tagList = !empty($artist['tags']) ? explode(' / ', $artist['tags']) : []; ?>
                    <label class="admin-card"
                           data-name="<?= htmlspecialchars(strtolower($artist['name']), ENT_QUOTES, 'UTF-8') ?>"
                           data-tags="<?= htmlspecialchars(implode('|', $tagList), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="checkbox" class="artist-checkbox" name="artist_ids[]" value="<?= (int)$artist['id'] ?>"
                            <?= in_array((int)$artist['id'], $selectedArtistIds, true) ? 'checked' : '' ?>>
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
<script>
var selectedTags = new Set();

function toggleArtistSelection(checked) {
  document.querySelectorAll('.artist-checkbox').forEach(function (el) {
    el.checked = checked;
  });
}

function toggleVisibleSelection(checked) {
  document.querySelectorAll('.admin-card').forEach(function (card) {
    if (card.style.display === 'none') return;
    var box = card.querySelector('.artist-checkbox');
    if (box) box.checked = checked;
  });
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
applyArtistFilters();
</script>
</body>
</html>
