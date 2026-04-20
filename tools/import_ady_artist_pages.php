<?php
require_once '../config.php';
$me = require_admin();
set_time_limit(180);

$BASE = 'https://www.ady.co.jp/song-chord/';

$MAIN_FILES = ['a.htm','i.htm','ue.htm','o.htm','kaki.htm','kukeko.htm','sa.htm','si.htm',
               'suseso.htm','tati.htm','tuteto.htm','naninuneno.htm','ha.htm','hi.htm',
               'huheho.htm','ma.htm','mimumemo.htm','yayuyo.htm','rawa.htm',
               'artist.htm','index.htm','index.html','first.htm','set.htm',
               'mailform.htm','chorditiran.htm'];

$isDry   = isset($_GET['dry']);
$srcFile = preg_replace('/[^a-z0-9\'._-]/i', '', trim($_GET['file'] ?? ''));

/* ── fetch ── */
function apf_fetch($url) {
    static $cache = [];
    if (isset($cache[$url])) return $cache[$url];
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0']]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) { $cache[$url] = null; return null; }
    $r = mb_convert_encoding($raw, 'UTF-8', 'CP932');
    $cache[$url] = $r;
    return $r;
}

/* ── メインページをスキャンして 特設ページ一覧を構築 ── */
function find_special_pages($base, $mainFiles) {
    $mainSet = array_flip($mainFiles);
    $special = []; // file => artistName

    foreach ($mainFiles as $mf) {
        if (!preg_match('/^[a-z]+\.htm$/', $mf)) continue;
        $html = apf_fetch($base . $mf);
        if (!$html) continue;

        preg_match_all('/<TR\b[^>]*>(.*?)<\/TR>/si', $html, $trs);
        $curArtist = null;
        foreach ($trs[1] as $row) {
            if (stripos($row, '#ffff71') !== false && preg_match('/<A\s+name="([^"]+)"/i', $row, $am)) {
                $curArtist = html_entity_decode(trim($am[1]), ENT_QUOTES, 'UTF-8');
                continue;
            }
            if ($curArtist && preg_match('/href="([a-z0-9\'._-]+\.htm)"/i', $row, $lm)) {
                $lf = $lm[1];
                if (!isset($mainSet[$lf]) && !preg_match('#^(kessai|set)/#', $lf)) {
                    $special[$lf] = $curArtist;
                    $curArtist = null;
                }
            }
        }
    }
    ksort($special);
    return $special;
}

/* ── 特設ページをパース → ['albumName' => [songs]] ── */
function parse_special_page($html) {
    $albums = [];

    // 2カラムレイアウト対応: ネストしていない innermost TABLE 単位でパース
    preg_match_all('/<TABLE\b[^>]*>((?:(?!<TABLE\b)[\s\S])*?)<\/TABLE>/i', $html, $tm);

    foreach ($tm[0] as $tableHtml) {
        if (stripos($tableHtml, '#ffff71') === false) continue;

        $curAlbum = null;
        preg_match_all('/<TR\b[^>]*>(.*?)<\/TR>/si', $tableHtml, $trs);
        foreach ($trs[1] as $row) {
            // アルバムヘッダ: #ffff71 かつ <A name= なし
            if (stripos($row, '#ffff71') !== false && !preg_match('/<A\s+name=/i', $row)) {
                if (preg_match('/<B>([^<]+)<\/B>/i', $row, $am)) {
                    $curAlbum = trim(html_entity_decode($am[1], ENT_QUOTES, 'UTF-8'));
                    if (!isset($albums[$curAlbum])) $albums[$curAlbum] = [];
                }
                continue;
            }
            if ($curAlbum === null) continue;

            // 楽曲行: #ddffff を含む
            if (stripos($row, '#ddffff') === false) continue;
            preg_match_all('/<TD\b[^>]*bgcolor="#ddffff"[^>]*>(.*?)<\/TD>/si', $row, $cm);
            if (empty($cm[1])) continue;
            $title = trim(strip_tags($cm[1][0]));
            if ($title === '') continue;

            $chordUrl = '';
            if (preg_match('/href="(kessai\/[^"]+\.htm)"/i', $row, $hm)) $chordUrl = $hm[1];

            $difficulty = '';
            preg_match_all('/<TD\b([^>]*)>(.*?)<\/TD>/si', $row, $allTds, PREG_SET_ORDER);
            foreach (array_reverse($allTds) as $td) {
                if (stripos($td[1], 'bgcolor') !== false) continue;
                $t = trim(strip_tags($td[2]));
                if (strlen($t) === 1 && in_array($t, ['A','B','C'])) { $difficulty = $t; break; }
            }

            $albums[$curAlbum][] = ['title' => $title, 'difficulty' => $difficulty ?: null, 'chord_url' => $chordUrl ?: null];
        }
    }
    return array_filter($albums, function($s) { return count($s) > 0; });
}

/* ── 年を付けないアルバム名パターン ── */
function album_skip_year($albumName) {
    return preg_match('/^(シングル|その他)/u', $albumName);
}

/* ── iTunes API でアルバム発表年を取得 ── */
function get_album_year($artistName, $albumName) {
    $url = 'https://itunes.apple.com/search?' . http_build_query([
        'term' => $artistName . ' ' . $albumName, 'country' => 'jp', 'entity' => 'album', 'limit' => 3,
    ]);
    $json = @file_get_contents($url);
    if (!$json) return null;
    $data = json_decode($json, true);
    foreach ($data['results'] ?? [] as $r) {
        if (!empty($r['releaseDate'])) return (int)substr($r['releaseDate'], 0, 4);
    }
    return null;
}

/* ── メイン ── */
$specialPages = find_special_pages($BASE, $MAIN_FILES);
$previewData  = null;
$importResult = null;
$tagAllResult = null;

/* ── 全員にどメジャータグ一括付与 ── */
if (isset($_GET['tag_all'])) {
    $dmRow = $pdo->prepare("SELECT id FROM tags WHERE name='どメジャー' LIMIT 1");
    $dmRow->execute();
    $dmTagId = (int)($dmRow->fetchColumn() ?: 0);
    if (!$dmTagId) {
        $pdo->prepare("INSERT INTO tags (name, tag_category, type) VALUES ('どメジャー','system','artist')")->execute();
        $dmTagId = (int)$pdo->lastInsertId();
    }
    $tagged = 0; $notFound = [];
    foreach ($specialPages as $artistName) {
        $aRow = $pdo->prepare("SELECT id FROM artists WHERE name=?");
        $aRow->execute([$artistName]);
        $aid = (int)($aRow->fetchColumn() ?: 0);
        if ($aid) {
            $pdo->prepare("INSERT IGNORE INTO artist_tags (artist_id,tag_id) VALUES (?,?)")->execute([$aid, $dmTagId]);
            $tagged++;
        } else {
            $notFound[] = $artistName;
        }
    }
    $tagAllResult = ['tagged' => $tagged, 'not_found' => $notFound];
}

if ($srcFile && isset($specialPages[$srcFile])) {
    $artistName = $specialPages[$srcFile];
    $html = apf_fetch($BASE . $srcFile);

    if ($html) {
        $albums = parse_special_page($html);

        // DB上のアーティストID確認
        $aRow = $pdo->prepare("SELECT id FROM artists WHERE name=?");
        $aRow->execute([$artistName]);
        $artistId = $aRow->fetchColumn() ?: null;

        // アルバムごとに iTunes で年を取得（シングル・その他はスキップ）
        $albumYears = [];
        foreach (array_keys($albums) as $albumName) {
            if (album_skip_year($albumName)) { $albumYears[$albumName] = null; continue; }
            $albumYears[$albumName] = get_album_year($artistName, $albumName);
            usleep(200000);
        }

        // 各曲が既存かチェック
        $previewData = [
            'artistName' => $artistName,
            'artistId'   => $artistId,
            'file'       => $srcFile,
            'albums'     => [],
        ];
        foreach ($albums as $albumName => $songs) {
            $year = $albumYears[$albumName] ?? null;
            $songRows = [];
            foreach ($songs as $s) {
                $exists = false;
                if ($artistId) {
                    $chk = $pdo->prepare("SELECT id FROM songs WHERE title=? AND artist_id=?");
                    $chk->execute([$s['title'], $artistId]);
                    $exists = (bool)$chk->fetchColumn();
                }
                $songRows[] = array_merge($s, ['exists' => $exists, 'year' => $year]);
            }
            $previewData['albums'][$albumName] = ['year' => $year, 'songs' => $songRows];
        }

        // 実行モード
        if (!$isDry) {
            $newArtist = false;
            // どメジャータグID（なければ作成）
            $dmRow = $pdo->prepare("SELECT id FROM tags WHERE name='どメジャー' LIMIT 1");
            $dmRow->execute();
            $dmTagId = (int)($dmRow->fetchColumn() ?: 0);
            if (!$dmTagId) {
                $pdo->prepare("INSERT INTO tags (name, tag_category, type) VALUES ('どメジャー','system','artist')")->execute();
                $dmTagId = (int)$pdo->lastInsertId();
            }
            if (!$artistId) {
                $pdo->prepare("INSERT INTO artists (name) VALUES (?)")->execute([$artistName]);
                $artistId = (int)$pdo->lastInsertId();
                // 邦楽タグ
                $tRow = $pdo->prepare("SELECT id FROM tags WHERE name='邦楽' LIMIT 1");
                $tRow->execute();
                $tagId = (int)($tRow->fetchColumn() ?: 0);
                if ($tagId) $pdo->prepare("INSERT IGNORE INTO artist_tags (artist_id,tag_id) VALUES (?,?)")->execute([$artistId,$tagId]);
                $newArtist = true;
            }
            // どメジャータグ付与（新規・既存アーティスト両方）
            if ($dmTagId) $pdo->prepare("INSERT IGNORE INTO artist_tags (artist_id,tag_id) VALUES (?,?)")->execute([$artistId,$dmTagId]);
            $inserted = 0; $skipped = 0;
            foreach ($albums as $albumName => $songs) {
                $year = $albumYears[$albumName] ?? null;
                foreach ($songs as $s) {
                    $chk = $pdo->prepare("SELECT id FROM songs WHERE title=? AND artist_id=?");
                    $chk->execute([$s['title'], $artistId]);
                    if ($chk->fetchColumn()) { $skipped++; continue; }
                    $pdo->prepare("INSERT INTO songs (title, artist_id, album_name, release_year, chord_difficulty, chord_url) VALUES (?,?,?,?,?,?)")
                        ->execute([$s['title'], $artistId, $albumName, $year, $s['difficulty'], $s['chord_url']]);
                    $inserted++;
                }
            }
            $importResult = ['new_artist' => $newArtist, 'inserted' => $inserted, 'skipped' => $skipped];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ady 特設アーティストページ インポート</title>
<link rel="stylesheet" href="../assets/app.css">
<style>
body { background:#f5f5f5; }
.wrap { max-width:900px; margin:0 auto; padding:20px 16px; }
h1 { font-size:18px; margin:0 0 16px; }
h2 { font-size:15px; margin:16px 0 8px; border-bottom:1px solid #ddd; padding-bottom:4px; }
.artist-grid { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:20px; }
.ap { display:flex; gap:3px; }
.ap a { display:inline-block; padding:5px 10px; border-radius:4px; font-size:13px; text-decoration:none; white-space:nowrap; }
.ap a.run  { border:1px solid var(--blue-dark); background:#fff; color:var(--blue); font-weight:700; }
.ap a.run:hover { background:var(--blue); color:#fff; }
.ap a.dry  { border:1px solid #aaa; background:#f4f4f4; color:#666; font-size:11px; }
.ap a.dry:hover { background:#e0e0e0; }
.summary { background:#fff; border:2px solid var(--blue); border-radius:6px; padding:12px 16px; margin-bottom:16px; font-size:14px; }
.summary p { margin:3px 0; }
.album-block { background:#fff; border:1px solid #ddd; border-radius:6px; padding:10px 14px; margin-bottom:10px; }
.album-head { font-weight:700; font-size:14px; margin-bottom:6px; display:flex; gap:12px; align-items:center; }
.year-badge { background:#e8f4fd; color:#0066cc; border-radius:3px; padding:1px 8px; font-size:12px; font-weight:400; }
.year-none  { color:#aaa; font-size:12px; font-weight:400; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th,td { border:1px solid #eee; padding:3px 8px; }
th { background:#f5f5f5; }
.new  { color:#080; font-weight:700; }
.skip { color:#bbb; }
.no-artist { background:#fff3cd; border:1px solid #ffc107; border-radius:4px; padding:8px 12px; font-size:13px; margin-bottom:10px; }
.badge-dry { background:#ff8; border:1px solid #aa0; border-radius:3px; padding:1px 8px; font-size:12px; color:#660; margin-left:8px; }
.diff-A { color:#155724; font-weight:700; }
.diff-B { color:#856404; font-weight:700; }
.diff-C { color:#721c24; font-weight:700; }
</style>
</head>
<body>
<div class="wrap">
<h1>ady 特設アーティストページ インポート
  <?php if ($isDry && $srcFile): ?><span class="badge-dry">ドライラン</span><?php endif; ?>
</h1>

<?php if ($tagAllResult): ?>
  <div class="summary">
    <p>✓ <strong>どメジャー</strong>タグ付与完了：<strong class="new"><?= $tagAllResult['tagged'] ?></strong>名</p>
    <?php if ($tagAllResult['not_found']): ?>
      <p style="color:#a00">DB未登録（スキップ）：<?= implode('、', array_map('htmlspecialchars', $tagAllResult['not_found'])) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($importResult): ?>
  <div class="summary">
    <p>✓ <strong><?= htmlspecialchars($specialPages[$srcFile] ?? $srcFile) ?></strong> インポート完了</p>
    <?php if ($importResult['new_artist']): ?><p>アーティストを新規追加しました（邦楽タグ付与）</p><?php endif; ?>
    <p>新規追加: <strong class="new"><?= $importResult['inserted'] ?></strong>曲 ／ スキップ（既存）: <?= $importResult['skipped'] ?>曲</p>
  </div>
<?php endif; ?>

<?php if (!$srcFile || (!$isDry && !$importResult)): ?>
  <h2>特設アーティスト一覧（<?= count($specialPages) ?>組）</h2>
  <p style="margin:0 0 10px">
    <a href="?tag_all=1" class="ap" style="display:inline-flex"
       onclick="return confirm('DB登録済みの全特設アーティストに「どメジャー」タグを付与します。よろしいですか？')"
       ><span style="display:inline-block;padding:5px 14px;border-radius:4px;background:var(--blue);color:#fff;font-size:13px;font-weight:700;text-decoration:none">全員にどメジャータグ付与</span></a>
  </p>
  <div class="artist-grid">
    <?php foreach ($specialPages as $file => $name): ?>
      <div class="ap">
        <a href="?file=<?= urlencode($file) ?>&dry=1" class="run"><?= htmlspecialchars($name) ?></a>
        <a href="?file=<?= urlencode($file) ?>&dry=1" class="dry">dry</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($previewData): ?>
  <h2>プレビュー：<?= htmlspecialchars($previewData['artistName']) ?></h2>

  <?php if (!$previewData['artistId']): ?>
    <div class="no-artist">⚠ このアーティストはDBに未登録です。実行時に新規追加されます。</div>
  <?php endif; ?>

  <?php
    $totalNew = 0; $totalExist = 0;
    foreach ($previewData['albums'] as $a) {
      foreach ($a['songs'] as $s) { $s['exists'] ? $totalExist++ : $totalNew++; }
    }
  ?>
  <div class="summary">
    <p>アルバム <?= count($previewData['albums']) ?>枚 ／ 新規追加予定: <strong class="new"><?= $totalNew ?></strong>曲 ／ 既存スキップ: <?= $totalExist ?>曲</p>
    <p style="margin-top:8px">
      <?php if ($isDry): ?>
        <a href="?file=<?= urlencode($srcFile) ?>" style="display:inline-block;padding:6px 18px;background:var(--blue);color:#fff;border-radius:4px;text-decoration:none;font-weight:700">
          このまま実行
        </a>
      <?php endif; ?>
      <a href="?" style="display:inline-block;padding:6px 14px;border:1px solid #aaa;border-radius:4px;text-decoration:none;color:#555;margin-left:8px">← 一覧に戻る</a>
    </p>
  </div>

  <?php foreach ($previewData['albums'] as $albumName => $albumData): ?>
    <div class="album-block">
      <div class="album-head">
        <?= htmlspecialchars($albumName) ?>
        <?php if ($albumData['year']): ?>
          <span class="year-badge"><?= $albumData['year'] ?>年</span>
        <?php else: ?>
          <span class="year-none">年不明</span>
        <?php endif; ?>
        <span style="color:#999;font-size:12px;font-weight:400"><?= count($albumData['songs']) ?>曲</span>
      </div>
      <table>
        <thead><tr><th>曲名</th><th>難易度</th><th>状態</th></tr></thead>
        <tbody>
        <?php foreach ($albumData['songs'] as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['title']) ?></td>
            <td class="diff-<?= htmlspecialchars($s['difficulty'] ?? '') ?>"><?= htmlspecialchars($s['difficulty'] ?? '—') ?></td>
            <td class="<?= $s['exists'] ? 'skip' : 'new' ?>"><?= $s['exists'] ? 'スキップ' : '新規' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</body>
</html>
