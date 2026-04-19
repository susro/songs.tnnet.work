<?php
require_once '../config.php';
$me = require_admin();
set_time_limit(0); // バッチ処理のためタイムアウト無制限

$clientId = defined('YAHOO_CLIENT_ID') ? YAHOO_CLIENT_ID : '';
$limit     = (int)($_GET['limit'] ?? 50);  // 1回あたり処理件数
$isDry     = isset($_GET['dry']);

function yahoo_reading_batch($text, $clientId) {
    if ($text === '') return null;
    if (preg_match('/^[ぁ-んァ-ヶーｦ-ﾟ\s　・♪]+$/u', $text)) {
        return mb_convert_kana($text, 'c', 'UTF-8');
    }
    if (!preg_match('/[ぁ-んァ-ヶー一-龯]/u', $text)) return null;
    if ($clientId === '') return null;

    usleep(150000); // 0.15秒待機

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
    if (!$words) return null;
    $reading = '';
    foreach ($words as $w) { $reading .= $w['furigana'] ?? $w['surface'] ?? ''; }
    return ($reading !== '' && $reading !== $text) ? $reading : null;
}

// 未補完曲数
$totalStmt = $pdo->query("SELECT COUNT(*) FROM songs WHERE title_reading IS NULL");
$totalNull = (int)$totalStmt->fetchColumn();

$results = [];
$processed = 0;
$filled = 0;
$skipped = 0;

if (isset($_GET['run']) || $isDry) {
    $rows = $pdo->query("SELECT id, title FROM songs WHERE title_reading IS NULL LIMIT " . (int)$limit);
    $songs = $rows->fetchAll();

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
.btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { border: 1px solid #ddd; padding: 4px 8px; text-align: left; }
th { background: #f0f0f0; }
.ok  { color: #080; font-weight: 700; }
.ng  { color: #aaa; }
.badge-dry { background:#ff8; border:1px solid #aa0; border-radius:3px; padding:1px 8px; font-size:12px; color:#660; margin-left:8px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>曲タイトル読み仮名 一括補完
    <?php if ($isDry): ?><span class="badge-dry">ドライラン</span><?php endif; ?>
  </h1>

  <div class="stat">
    <p>読み仮名未補完の曲：<strong><?= $totalNull ?></strong> 件</p>
    <?php if ($clientId === ''): ?>
      <p style="color:red;font-weight:700">YAHOO_CLIENT_ID が未設定です</p>
    <?php endif; ?>
  </div>

  <div class="btn-row">
    <a href="?run=1&limit=50"  class="link-button" style="background:var(--blue);color:#fff;border-color:var(--blue-dark)">50件 実行</a>
    <a href="?run=1&limit=100" class="link-button" style="background:var(--blue);color:#fff;border-color:var(--blue-dark)">100件 実行</a>
    <a href="?dry=1&limit=20"  class="link-button">20件 ドライラン</a>
  </div>
  <p style="font-size:12px;color:#888;margin:-10px 0 16px">※ API呼び出しがあるため1件あたり約0.5〜1秒かかります。100件で1〜2分程度。</p>

  <?php if ($processed > 0): ?>
    <p style="font-size:14px;margin-bottom:8px">
      処理: <?= $processed ?>件 ／ 補完成功: <strong class="ok"><?= $filled ?></strong>件 ／ スキップ（英語・取得不可）: <?= $skipped ?>件
    </p>
    <table>
      <thead><tr><th>曲タイトル</th><th>読み仮名</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['title']) ?></td>
          <td class="<?= $r['ok'] ? 'ok' : 'ng' ?>">
            <?= $r['ok'] ? htmlspecialchars($r['reading']) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
