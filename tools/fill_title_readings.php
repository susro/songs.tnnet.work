<?php
require_once '../config.php';
$me = require_admin();
set_time_limit(0);

$clientId = defined('YAHOO_CLIENT_ID') ? YAHOO_CLIENT_ID : '';
$limit    = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$isDry    = isset($_GET['dry']);
$isJson   = isset($_GET['json']); // JS自動ループ用

function yahoo_reading_batch($text, $clientId) {
    if ($text === '') return null;
    if (preg_match('/^[ぁ-んァ-ヶーｦ-ﾟ\s　・♪]+$/u', $text)) {
        return mb_convert_kana($text, 'c', 'UTF-8');
    }
    if (!preg_match('/[ぁ-んァ-ヶー一-龯]/u', $text)) return null;
    if ($clientId === '') return null;

    usleep(150000);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nUser-Agent: Yahoo AppID: " . $clientId,
        'content'       => json_encode([
            'id' => '1', 'jsonrpc' => '2.0',
            'method' => 'jlp.furiganaservice.furigana',
            'params' => ['q' => $text, 'grade' => 1],
        ]),
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $res = @file_get_contents('https://jlp.yahooapis.jp/FuriganaService/V2/furigana', false, $ctx);
    if (!$res) return null;
    $data = json_decode($res, true);
    if (!empty($data['Error'])) return null;
    $words = $data['result']['word'] ?? [];
    $reading = '';
    foreach ($words as $w) { $reading .= $w['furigana'] ?? $w['surface'] ?? ''; }
    return ($reading !== '' && $reading !== $text) ? $reading : null;
}

$totalNull = (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE title_reading IS NULL")->fetchColumn();

$processed = 0; $filled = 0; $skipped = 0; $results = [];

if (isset($_GET['run']) || $isDry) {
    $songs = $pdo->query("SELECT id, title FROM songs WHERE title_reading IS NULL LIMIT " . $limit)->fetchAll();
    foreach ($songs as $s) {
        $reading = yahoo_reading_batch($s['title'], $clientId);
        $processed++;
        if ($reading !== null) {
            if (!$isDry) {
                $pdo->prepare("UPDATE songs SET title_reading=? WHERE id=?")->execute([$reading, $s['id']]);
            }
            $filled++;
            $results[] = ['title' => $s['title'], 'reading' => $reading, 'ok' => true];
        } else {
            $skipped++;
            $results[] = ['title' => $s['title'], 'reading' => null, 'ok' => false];
        }
    }
    $remaining = (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE title_reading IS NULL")->fetchColumn();

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'processed'  => $processed,
            'filled'     => $filled,
            'skipped'    => $skipped,
            'remaining'  => $remaining,
            'done'       => $remaining === 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>曲タイトル読み補完</title>
<link rel="stylesheet" href="../assets/app.css">
<style>
body { background: #f5f5f5; }
.wrap { max-width: 800px; margin: 0 auto; padding: 20px 16px; }
h1 { font-size: 18px; margin: 0 0 16px; }
.stat { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; }
.stat p { margin: 4px 0; }
.btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
th, td { border: 1px solid #ddd; padding: 4px 8px; text-align: left; }
th { background: #f0f0f0; }
.ok  { color: #080; font-weight: 700; }
.ng  { color: #aaa; }
.badge-dry { background:#ff8; border:1px solid #aa0; border-radius:3px; padding:1px 8px; font-size:12px; color:#660; margin-left:8px; }
progress { width: 100%; height: 20px; margin: 8px 0; }
#auto-log { font-size: 13px; color: #444; margin-top: 8px; max-height: 200px; overflow-y: auto;
            background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 8px; }
#auto-log p { margin: 2px 0; }
.btn-stop { background: #c00; color: #fff; border: none; border-radius: 4px;
            padding: 6px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
</style>
</head>
<body>
<div class="wrap">
  <h1>曲タイトル読み仮名 一括補完
    <?php if ($isDry): ?><span class="badge-dry">ドライラン</span><?php endif; ?>
  </h1>

  <div class="stat">
    <p>読み仮名未補完の曲：<strong id="remaining-count"><?= $totalNull ?></strong> 件</p>
    <?php if ($clientId === ''): ?>
      <p style="color:red;font-weight:700">YAHOO_CLIENT_ID が未設定です</p>
    <?php endif; ?>
  </div>

  <div class="btn-row">
    <button class="link-button" style="background:var(--blue);color:#fff;border-color:var(--blue-dark)"
            onclick="startAuto(50)">全件自動実行（50件ずつ）</button>
    <a href="?run=1&limit=100" class="link-button" style="background:var(--blue);color:#fff;border-color:var(--blue-dark)">100件だけ実行</a>
    <a href="?dry=1&limit=20"  class="link-button">20件 ドライラン</a>
  </div>
  <p style="font-size:12px;color:#888;margin:0 0 16px">
    ※ 全件自動実行はページを開いたまま放置してください。中断は「停止」ボタンで。<br>
    　 英語タイトル・記号のみは読み取得をスキップします。
  </p>

  <div id="auto-wrap" hidden>
    <progress id="auto-progress" value="0" max="<?= $totalNull ?>"></progress>
    <p id="auto-status" style="font-size:13px"></p>
    <button class="btn-stop" id="stop-btn" onclick="stopAuto()">停止</button>
    <div id="auto-log"></div>
  </div>

  <?php if ($processed > 0 && !$isJson): ?>
    <p style="font-size:14px;margin:12px 0 8px">
      処理: <?= $processed ?>件 ／ 補完: <strong class="ok"><?= $filled ?></strong>件 ／ スキップ: <?= $skipped ?>件
    </p>
    <table>
      <thead><tr><th>曲タイトル</th><th>読み仮名</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['title']) ?></td>
          <td class="<?= $r['ok'] ? 'ok' : 'ng' ?>"><?= $r['ok'] ? htmlspecialchars($r['reading']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
var totalRemaining = <?= $totalNull ?>;
var totalDone = 0;
var stopping = false;

function startAuto(batchSize) {
  document.getElementById('auto-wrap').hidden = false;
  document.querySelector('.btn-row').style.opacity = '0.4';
  document.querySelector('.btn-row').style.pointerEvents = 'none';
  stopping = false;
  runBatch(batchSize);
}

function stopAuto() {
  stopping = true;
  document.getElementById('stop-btn').textContent = '停止中…';
}

async function runBatch(batchSize) {
  if (stopping) {
    document.getElementById('auto-status').textContent = '停止しました。';
    return;
  }
  document.getElementById('auto-status').textContent = '処理中…';
  try {
    const res = await fetch('?json=1&run=1&limit=' + batchSize);
    const data = await res.json();

    totalDone += data.processed;
    totalRemaining = data.remaining;

    var progress = document.getElementById('auto-progress');
    progress.max   = totalDone + data.remaining;
    progress.value = totalDone;

    document.getElementById('remaining-count').textContent = data.remaining;

    var log = document.getElementById('auto-log');
    var p = document.createElement('p');
    p.textContent = '処理 ' + data.processed + '件 → 読み補完 ' + data.filled + '件 / スキップ ' + data.skipped + '件 / 残り ' + data.remaining + '件';
    log.prepend(p);

    if (data.done || data.remaining === 0) {
      document.getElementById('auto-status').textContent = '✓ 全件完了！ 合計 ' + totalDone + '件処理しました。';
      document.getElementById('stop-btn').hidden = true;
      return;
    }
    document.getElementById('auto-status').textContent = '残り ' + data.remaining + '件…';
    setTimeout(function() { runBatch(batchSize); }, 500);
  } catch(e) {
    document.getElementById('auto-status').textContent = 'エラー: ' + e.message + ' — リロードして再開できます。';
  }
}
</script>
</body>
</html>
