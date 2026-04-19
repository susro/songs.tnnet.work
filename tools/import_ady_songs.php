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
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    return mb_convert_encoding($raw, 'UTF-8', 'CP932');
}

/* ── artist.htm から .htm ファイル名一覧を取得 ── */
function get_song_files($baseUrl) {
    $html = ady_fetch($baseUrl . 'artist.htm');
    if (!$html) return [];
    preg_match_all('/<a\s[^>]*href="([a-z0-9]+\.htm)#/i', $html, $m);
    return array_values(array_unique($m[1]));
}

/* ── 楽曲リストページをRegexでパース ── */
function parse_song_page($html) {
    $results       = [];
    $currentArtist = null;

    // TR ブロックを全て抽出
    preg_match_all('/<TR\b[^>]*>(.*?)<\/TR>/si', $html, $trMatches);

    foreach ($trMatches[1] as $row) {

        /* アーティストヘッダ行: #ffff71 を含む */
        if (stripos($row, '#ffff71') !== false) {
            if (preg_match('/<A\s+name="([^"]+)"/i', $row, $m)) {
                $currentArtist = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                if (!isset($results[$currentArtist])) {
                    $results[$currentArtist] = [];
                }
            }
            continue;
        }

        if ($currentArtist === null) continue;

        /* 楽曲行: #ddffff が2つ以上 */
        if (substr_count(strtolower($row), '#ddffff') < 2) continue;

        preg_match_all('/<TD\b[^>]*bgcolor="#ddffff"[^>]*>(.*?)<\/TD>/si', $row, $cyanM);
        if (count($cyanM[1]) < 2) continue;

        $title  = trim(strip_tags($cyanM[1][0]));
        $lyrics = trim(strip_tags($cyanM[1][1]));
        if ($title === '') continue;

        /* おすすめ(R)フラグ */
        $recommended = 0;
        if (preg_match('/<TD\b[^>]*bgcolor="#ffff00"[^>]*>(.*?)<\/TD>/si', $row, $rm)) {
            if (trim(strip_tags($rm[1])) === 'R') $recommended = 1;
        }

        /* コードページURL */
        $chordUrl = '';
        if (preg_match('/href="(kessai\/[^"]+\.htm)"/i', $row, $cm)) {
            $chordUrl = $cm[1];
        }

        /* 難易度: bgcolorなしTDでA/B/C */
        $difficulty = '';
        preg_match_all('/<TD\b([^>]*)>(.*?)<\/TD>/si', $row, $allTds, PREG_SET_ORDER);
        foreach (array_reverse($allTds) as $td) {
            if (stripos($td[1], 'bgcolor') !== false) continue;
            $txt = trim(strip_tags($td[2]));
            if (strlen($txt) === 1 && in_array($txt, ['A','B','C'])) {
                $difficulty = $txt;
                break;
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

/* ── Yahoo! ルビ振りAPI でひらがな読みを取得 ── */
function yahoo_reading($text) {
    if ($text === '') return null;

    // 全カナ・ひらがなのみ → 変換だけして返す（API不要）
    if (preg_match('/^[ぁ-んァ-ヶーｦ-ﾟ\s　・♪]+$/u', $text)) {
        return mb_convert_kana($text, 'c', 'UTF-8');
    }

    // 日本語文字（漢字・かな）を含まない場合はスキップ（英語名等）
    if (!preg_match('/[ぁ-んァ-ヶー一-龯]/u', $text)) return null;

    $clientId = defined('YAHOO_CLIENT_ID') ? YAHOO_CLIENT_ID : '';
    if ($clientId === '') return null;

    usleep(100000); // 0.1秒待機

    $ctx = stream_context_create(['http' => [
        'method'         => 'POST',
        'header'         => "Content-Type: application/json\r\nUser-Agent: Yahoo AppID: " . $clientId,
        'content'        => json_encode([
            'id'      => '1',
            'jsonrpc' => '2.0',
            'method'  => 'jlp.furiganaservice.furigana',
            'params'  => ['q' => $text, 'grade' => 1],
        ]),
        'timeout'        => 10,
        'ignore_errors'  => true,
    ]]);

    $res = @file_get_contents('https://jlp.yahooapis.jp/FuriganaService/V2/furigana', false, $ctx);
    if (!$res) return null;

    $data = json_decode($res, true);
    if (!empty($data['Error'])) return null; // レートリミットや認証エラー

    $words = $data['result']['word'] ?? [];
    if (!$words) return null;

    $reading = '';
    foreach ($words as $w) {
        $reading .= $w['furigana'] ?? $w['surface'] ?? '';
    }
    // 元テキストと同じなら読みとして意味がない
    return ($reading !== '' && $reading !== $text) ? $reading : null;
}

/* ── DB取り込み ── */
function do_import($pdo, $data, $isDry) {
    // 邦楽タグID取得
    $tRow = $pdo->prepare("SELECT id FROM tags WHERE name='邦楽' LIMIT 1");
    $tRow->execute();
    $邦楽TagId = (int)($tRow->fetchColumn() ?: 0);

    $stats = [
        'artists_new'      => 0,
        'artists_existing' => 0,
        'songs_new'        => 0,
        'songs_existing'   => 0,
        'detail'           => [],
    ];

    foreach ($data as $artistName => $songs) {
        $row = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
        $row->execute([$artistName]);
        $artistId  = $row->fetchColumn();
        $isNewArtist = !$artistId;

        if ($artistId) {
            $stats['artists_existing']++;
        } else {
            $reading = yahoo_reading($artistName);
            if (!$isDry) {
                $pdo->prepare("INSERT INTO artists (name, reading) VALUES (?, ?)")
                    ->execute([$artistName, $reading]);
                $artistId = (int)$pdo->lastInsertId();
                // 邦楽タグ付与
                if ($邦楽TagId) {
                    $pdo->prepare("INSERT IGNORE INTO artist_tags (artist_id, tag_id) VALUES (?, ?)")
                        ->execute([$artistId, $邦楽TagId]);
                }
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
            // 曲タイトル読みはインポート時は取得しない（後でバッチ補完）
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
        $stats['detail'][$artistName] = [
            'new'            => $songNew,
            'exist'          => $songExist,
            'is_new_artist'  => $isNewArtist,
            'reading'        => $isNewArtist ? ($reading ?? null) : null,
        ];
    }
    return $stats;
}

/* ── メイン処理 ── */
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
.wrap { max-width: 860px; margin: 0 auto; padding: 20px 16px; }
h1 { font-size: 18px; margin: 0 0 16px; }
h2 { font-size: 15px; margin: 16px 0 8px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
.file-grid { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
.file-pair { display: flex; gap: 4px; }
.file-btn { display: inline-block; padding: 5px 12px; border-radius: 4px; font-size: 13px; font-weight: 700;
            border: 1px solid var(--blue-dark); background: #fff; color: var(--blue); text-decoration: none; white-space: nowrap; }
.file-btn:hover { background: var(--blue); color: #fff; text-decoration: none; }
.file-btn.dry { border-color: #aaa; color: #666; background: #f4f4f4; font-weight: 400; }
.file-btn.dry:hover { background: #e0e0e0; color: #333; }
.result-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
.result-table th, .result-table td { border: 1px solid #ddd; padding: 4px 8px; }
.result-table th { background: #f0f0f0; text-align: left; }
.tag-new  { color: #080; font-weight: 700; }
.tag-skip { color: #aaa; }
.summary-box { background: #fff; border: 2px solid var(--blue); border-radius: 6px; padding: 14px 16px; margin-bottom: 16px; }
.summary-box p { margin: 4px 0; font-size: 14px; }
.err { color: #c00; font-weight: 700; padding: 10px; background: #fff0f0; border-radius: 4px; }
.badge-dry { display: inline-block; background: #ff8; border: 1px solid #aa0; border-radius: 3px;
             padding: 1px 8px; font-size: 12px; margin-left: 8px; color: #660; }
.badge-new-artist { display: inline-block; background: #dfd; border: 1px solid #080;
                    border-radius: 3px; padding: 0 5px; font-size: 11px; color: #060; margin-left: 4px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>ady.co.jp 楽曲インポート
    <?php if ($isDry): ?><span class="badge-dry">ドライラン</span><?php endif; ?>
  </h1>
  <p style="font-size:13px;color:#666;margin:0 0 4px">
    <a href="<?= htmlspecialchars($BASE . 'artist.htm') ?>" target="_blank" rel="noopener">ady.co.jp アーティスト一覧</a>
    からアーティスト・楽曲を差分追加します。
  </p>
  <p style="font-size:12px;color:#888;margin:0 0 16px">
    新規アーティストには自動で「邦楽」タグが付きます。ファイルを1つずつ選んで実行してください。
  </p>

  <?php if ($errorMsg): ?>
    <p class="err"><?= htmlspecialchars($errorMsg) ?></p>
  <?php endif; ?>

  <?php if (!$songFiles): ?>
    <p class="err">artist.htm の取得に失敗しました。ネットワーク接続を確認してください。</p>
  <?php else: ?>
    <h2>処理するファイルを選択</h2>
    <div class="file-grid">
      <?php foreach ($songFiles as $f): ?>
        <div class="file-pair">
          <a href="?file=<?= urlencode($f) ?>" class="file-btn"><?= htmlspecialchars($f) ?></a>
          <a href="?file=<?= urlencode($f) ?>&dry=1" class="file-btn dry">dry</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($importStats !== null && $parseData !== null): ?>
    <div class="summary-box">
      <p><strong><?= htmlspecialchars($srcFile) ?></strong><?= $isDry ? '（ドライラン・DB変更なし）' : '　実行完了' ?></p>
      <p>アーティスト：新規 <strong class="tag-new"><?= $importStats['artists_new'] ?></strong> 件 ／ 既存スキップ <?= $importStats['artists_existing'] ?> 件</p>
      <p>楽曲：新規 <strong class="tag-new"><?= $importStats['songs_new'] ?></strong> 件 ／ 既存スキップ <?= $importStats['songs_existing'] ?> 件</p>
    </div>

    <h2>詳細（<?= count($parseData) ?>アーティスト）</h2>
    <table class="result-table">
      <thead><tr><th>アーティスト</th><th>読み仮名</th><th>新規追加曲</th><th>スキップ曲</th></tr></thead>
      <tbody>
      <?php foreach ($parseData as $artistName => $songs): ?>
        <?php $d = $importStats['detail'][$artistName] ?? ['new'=>0,'exist'=>0,'is_new_artist'=>false,'reading'=>null]; ?>
        <tr>
          <td>
            <?= htmlspecialchars($artistName) ?>
            <?php if ($d['is_new_artist']): ?>
              <span class="badge-new-artist">新規</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#555">
            <?= $d['reading'] ? htmlspecialchars($d['reading']) : '<span style="color:#ccc">—</span>' ?>
          </td>
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
