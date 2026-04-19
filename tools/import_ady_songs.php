<?php
require_once '../config.php';
$me = require_admin();
set_time_limit(180);

$BASE    = 'https://www.ady.co.jp/song-chord/';
$isDry   = isset($_GET['dry']);
$srcFile = preg_replace('/[^a-z0-9._-]/i', '', trim($_GET['file'] ?? ''));

/* ── HTTP取得（Shift_JIS→UTF-8） ── */
function ady_fetch($url) {
    $ctx = stream_context_create(['http' => [
        'timeout'    => 30,
        'user_agent' => 'Mozilla/5.0 (compatible; personal-use)',
        'header'     => "Accept-Charset: Shift_JIS\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    return mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
}

/* ── artist.htm から .htm ファイル名一覧を取得 ── */
function get_song_files($baseUrl) {
    $html = ady_fetch($baseUrl . 'artist.htm');
    if (!$html) return [];
    preg_match_all('/<a\s[^>]*href="([a-z0-9]+\.htm)#/i', $html, $m);
    return array_values(array_unique($m[1]));
}

/* ── 楽曲リストページをパース ── */
function parse_song_page($html) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $results = [];       // artistName => [songs]
    $currentArtist = null;

    foreach ($xpath->query('//tr') as $tr) {
        /* アーティストヘッダ行: bgcolor="#ffff71" のTDを含む */
        if ($xpath->query('.//td[@bgcolor="#ffff71"]', $tr)->length > 0) {
            $anchor = $xpath->query('.//a[@name]', $tr)->item(0);
            if ($anchor) {
                $currentArtist = trim($anchor->getAttribute('name'));
                if (!isset($results[$currentArtist])) {
                    $results[$currentArtist] = [];
                }
            }
            continue;
        }

        if ($currentArtist === null) continue;

        /* 楽曲行: bgcolor="#ddffff" のTDが2つ以上 */
        $cyanTds = $xpath->query('.//td[@bgcolor="#ddffff"]', $tr);
        if ($cyanTds->length < 2) continue;

        $title  = trim($cyanTds->item(0)->textContent);
        $lyrics = trim($cyanTds->item(1)->textContent);
        if ($title === '') continue;

        $recommended = 0;
        $difficulty  = '';
        $chordUrl    = '';

        foreach ($xpath->query('.//td', $tr) as $td) {
            $bg   = strtolower($td->getAttribute('bgcolor'));
            $text = trim($td->textContent);

            if ($bg === '#ffff00' && $text === 'R') {
                $recommended = 1;
            } elseif ($bg === '#ffff88') {
                $a = $xpath->query('.//a', $td)->item(0);
                if ($a) $chordUrl = $a->getAttribute('href');
            } elseif ($bg === '' && strlen($text) === 1 && ctype_alpha($text)) {
                $difficulty = strtoupper($text);
            }
        }

        $results[$currentArtist][] = [
            'title'       => $title,
            'lyrics'      => $lyrics,
            'difficulty'  => $difficulty ?: null,
            'chord_url'   => $chordUrl   ?: null,
            'recommended' => $recommended,
        ];
    }
    return $results;
}

/* ── DB取り込み ── */
function do_import($pdo, $data, $isDry) {
    $stats = [
        'artists_new'      => 0,
        'artists_existing' => 0,
        'songs_new'        => 0,
        'songs_existing'   => 0,
        'detail'           => [],   // artistName => [new, existing]
    ];

    foreach ($data as $artistName => $songs) {
        $row = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
        $row->execute([$artistName]);
        $artistId = $row->fetchColumn();

        if ($artistId) {
            $stats['artists_existing']++;
        } else {
            if (!$isDry) {
                $pdo->prepare("INSERT INTO artists (name) VALUES (?)")->execute([$artistName]);
                $artistId = (int)$pdo->lastInsertId();
            }
            $stats['artists_new']++;
        }

        $songNew = 0; $songExist = 0;
        foreach ($songs as $s) {
            $chk = $pdo->prepare("SELECT id FROM songs WHERE title = ? AND artist_id = ?");
            $chk->execute([$s['title'], (int)$artistId]);
            if ($chk->fetchColumn()) {
                $songExist++;
                $stats['songs_existing']++;
                continue;
            }
            if (!$isDry && $artistId) {
                $pdo->prepare("
                    INSERT INTO songs (title, artist_id, lyrics_excerpt, chord_difficulty, chord_url, ady_recommended)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([
                    $s['title'],
                    (int)$artistId,
                    $s['lyrics']     ?: null,
                    $s['difficulty'] ?: null,
                    $s['chord_url']  ?: null,
                    $s['recommended'],
                ]);
            }
            $songNew++;
            $stats['songs_new']++;
        }
        $stats['detail'][$artistName] = ['new' => $songNew, 'exist' => $songExist, 'id_new' => !$artistId && !$isDry ? false : ($artistId ? false : true)];
    }
    return $stats;
}

/* ── ファイル一覧を取得（キャッシュなし、毎回fetch） ── */
$songFiles   = get_song_files($BASE);
$importStats = null;
$parseData   = null;
$errorMsg    = '';

if ($srcFile && in_array($srcFile, $songFiles)) {
    $html = ady_fetch($BASE . $srcFile);
    if (!$html) {
        $errorMsg = "{$srcFile} の取得に失敗しました。";
    } else {
        $parseData   = parse_song_page($html);
        $importStats = do_import($pdo, $parseData, $isDry);
    }
} elseif ($srcFile) {
    $errorMsg = "不正なファイル名です: {$srcFile}";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ady楽曲インポート</title>
<link rel="stylesheet" href="../assets/app.css">
<style>
body { background: #f5f5f5; }
.wrap { max-width: 820px; margin: 0 auto; padding: 20px 16px; }
h1 { font-size: 18px; margin: 0 0 16px; }
h2 { font-size: 15px; margin: 16px 0 8px; }
.file-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.file-btn { display: inline-block; padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 700;
            border: 1px solid var(--blue-dark); background: #fff; color: var(--blue); text-decoration: none; }
.file-btn:hover { background: var(--blue); color: #fff; }
.file-btn.dry { border-color: #888; color: #555; background: #f0f0f0; }
.result-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
.result-table th, .result-table td { border: 1px solid #ddd; padding: 5px 8px; }
.result-table th { background: #f0f0f0; }
.tag-new  { color: #0a0; font-weight: 700; }
.tag-skip { color: #999; }
.summary-box { background: #fff; border: 1px solid var(--border); border-radius: 6px; padding: 14px 16px; margin-bottom: 16px; }
.summary-box p { margin: 4px 0; font-size: 14px; }
.err { color: #c00; font-weight: 700; }
.badge-dry { display: inline-block; background: #ff8; border: 1px solid #cc0; border-radius: 3px; padding: 1px 8px; font-size: 12px; margin-left: 8px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>ady.co.jp 楽曲インポート
    <?php if ($isDry): ?><span class="badge-dry">ドライラン</span><?php endif; ?>
  </h1>
  <p style="font-size:13px;color:#666;margin:0 0 16px">
    <a href="<?= htmlspecialchars($BASE . 'artist.htm') ?>" target="_blank" rel="noopener">ady.co.jp アーティスト一覧</a>
    からアーティスト・楽曲を差分追加します。ファイルを1つずつ選んで実行してください。
  </p>

  <?php if ($errorMsg): ?>
    <p class="err"><?= htmlspecialchars($errorMsg) ?></p>
  <?php endif; ?>

  <?php if (!$songFiles): ?>
    <p class="err">artist.htm の取得に失敗しました。</p>
  <?php else: ?>
    <h2>処理するファイルを選択</h2>
    <div class="file-grid">
      <?php foreach ($songFiles as $f): ?>
        <a href="?file=<?= urlencode($f) ?>" class="file-btn<?= ($f === $srcFile && !$isDry) ? ' is-active' : '' ?>">
          <?= htmlspecialchars($f) ?>
        </a>
        <a href="?file=<?= urlencode($f) ?>&dry=1" class="file-btn dry">
          <?= htmlspecialchars($f) ?> (dry)
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($importStats !== null): ?>
    <div class="summary-box">
      <p><strong>ファイル:</strong> <?= htmlspecialchars($srcFile) ?><?= $isDry ? '（ドライラン・DB変更なし）' : '' ?></p>
      <p>アーティスト：新規 <strong class="tag-new"><?= $importStats['artists_new'] ?></strong> 件 ／ 既存スキップ <?= $importStats['artists_existing'] ?> 件</p>
      <p>楽曲：新規 <strong class="tag-new"><?= $importStats['songs_new'] ?></strong> 件 ／ 既存スキップ <?= $importStats['songs_existing'] ?> 件</p>
    </div>

    <h2>詳細</h2>
    <table class="result-table">
      <thead><tr><th>アーティスト</th><th>新規追加</th><th>スキップ</th></tr></thead>
      <tbody>
      <?php foreach ($parseData as $artistName => $songs): ?>
        <?php $d = $importStats['detail'][$artistName] ?? ['new'=>0,'exist'=>0]; ?>
        <tr>
          <td><?= htmlspecialchars($artistName) ?></td>
          <td class="<?= $d['new'] > 0 ? 'tag-new' : 'tag-skip' ?>"><?= $d['new'] ?></td>
          <td class="tag-skip"><?= $d['exist'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
