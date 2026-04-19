<?php
require_once '../config.php';
$me = require_admin();

$clientId = defined('YAHOO_CLIENT_ID') ? YAHOO_CLIENT_ID : '';
$testText = trim($_GET['q'] ?? '上田正樹');

$rawResponse = null;
$decoded     = null;
$error       = '';

if ($clientId !== '') {
    $body = json_encode([
        'id'      => '1',
        'jsonrpc' => '2.0',
        'method'  => 'jlp.furiganaservice.furigana',
        'params'  => ['q' => $testText, 'grade' => 1],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nUser-Agent: Yahoo AppID: " . $clientId,
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $rawResponse = @file_get_contents('https://jlp.yahooapis.jp/FuriganaService/V2/furigana', false, $ctx);
    if ($rawResponse === false) {
        $error = 'file_get_contents が false を返しました（allow_url_fopen 無効 or ネットワークエラー）';
    } else {
        $decoded = json_decode($rawResponse, true);
    }
} else {
    $error = 'YAHOO_CLIENT_ID が空です。config.php を確認してください。';
}

// 読み結合
$reading = '';
if (!empty($decoded['result']['word'])) {
    foreach ($decoded['result']['word'] as $w) {
        $reading .= $w['furigana'] ?? $w['surface'] ?? '';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>Yahoo! API テスト</title>
<style>
body { font-family: sans-serif; padding: 20px; background: #f5f5f5; }
pre  { background: #222; color: #0f0; padding: 14px; border-radius: 4px; overflow-x: auto; font-size: 13px; }
.ok  { color: green; font-weight: bold; }
.err { color: red;   font-weight: bold; }
input[type=text] { padding: 6px 10px; font-size: 14px; width: 300px; border: 1px solid #aaa; border-radius: 3px; }
button { padding: 6px 16px; font-size: 14px; background: #06c; color: #fff; border: none; border-radius: 3px; cursor: pointer; }
</style>
</head>
<body>
<h2>Yahoo! ルビ振りAPI テスト</h2>
<form method="get">
  <input type="text" name="q" value="<?= htmlspecialchars($testText) ?>">
  <button type="submit">テスト</button>
</form>
<hr>

<p><b>Client ID:</b> <?= $clientId !== '' ? substr($clientId,0,10).'…（' . strlen($clientId) . '文字）' : '<span class="err">未設定</span>' ?></p>
<p><b>テスト文字列:</b> <?= htmlspecialchars($testText) ?></p>

<?php if ($error): ?>
  <p class="err">エラー: <?= htmlspecialchars($error) ?></p>
<?php else: ?>
  <p><b>読み結果:</b>
    <?= $reading !== '' ? '<span class="ok">'.htmlspecialchars($reading).'</span>' : '<span class="err">取得できず</span>' ?>
  </p>
  <p><b>生レスポンス:</b></p>
  <pre><?= htmlspecialchars(json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
<?php endif; ?>
</body>
</html>
