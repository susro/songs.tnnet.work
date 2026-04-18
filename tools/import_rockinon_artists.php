<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_admin();

$dry_run  = isset($_GET['dry'])   && $_GET['dry']   === '1';
$debug    = isset($_GET['debug']) && $_GET['debug'] === '1';

$category_map = [
    '1' => '邦楽',
    '2' => '洋楽',
];
$category = isset($_GET['category']) && isset($category_map[$_GET['category']]) ? $_GET['category'] : '1';
$tag_name = $category_map[$category];

$url = 'https://rockinon.com/artist/list?category=' . $category;
$opts = ['http' => [
    'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
               . "Accept: text/html,application/xhtml+xml\r\n"
               . "Accept-Language: ja,en\r\n",
    'timeout' => 20,
]];
$html = file_get_contents($url, false, stream_context_create($opts));

if ($debug) {
    echo '<pre>';
    echo 'HTMLサイズ: ' . strlen($html) . " bytes\n\n";
    // /artist/ を含む最初のリンク周辺200文字を抽出
    $pos = strpos($html, '/artist/');
    if ($pos !== false) {
        $start = max(0, $pos - 100);
        echo "/artist/ 初出周辺:\n" . htmlspecialchars(substr($html, $start, 400)) . "\n\n";
    } else {
        echo "/artist/ という文字列はHTML内に見つかりませんでした\n";
    }
    // XPathで何件ヒットするか
    $dom2 = new DOMDocument();
    @$dom2->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xp2 = new DOMXPath($dom2);
    $n1 = $xp2->query('//li/a[contains(@href, "/artist/")]');
    $n2 = $xp2->query('//a[contains(@href, "/artist/")]');
    echo "XPath //li/a[contains(@href,\"/artist/\")]: " . $n1->length . " 件\n";
    echo "XPath //a[contains(@href,\"/artist/\")]:    " . $n2->length . " 件\n";
    // /artist/数字 にマッチする最初の3件を表示
    $count = 0;
    foreach ($n2 as $node) {
        $href = $node->getAttribute('href');
        if (!preg_match('#/artist/\d+$#', $href)) continue;
        $tmp = new DOMDocument();
        $tmp->appendChild($tmp->importNode($node, true));
        echo "\n--- マッチ " . (++$count) . " (href={$href}) ---\n";
        echo htmlspecialchars($tmp->saveHTML()) . "\n";
        echo "親タグ: " . $node->parentNode->nodeName . "\n";
        if ($count >= 3) break;
    }
    echo '</pre>';
    exit;
}

if ($html === false) {
    die('<p style="color:red">ページ取得失敗（サーバーから外部接続できない可能性）</p>');
}

$dom = new DOMDocument();
@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
$xpath = new DOMXPath($dom);
$nodes = $xpath->query('//li/a[contains(@href, "/artist/") and @href != "/artist/list"]');

// タグを取得or作成
$tag_id = null;
if (!$dry_run) {
    $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
    $stmt->execute([$tag_name]);
    $tag_id = $stmt->fetchColumn();
    if (!$tag_id) {
        $pdo->prepare("INSERT INTO tags (name, type, tag_category) VALUES (?, 'artist', 'official')")->execute([$tag_name]);
        $tag_id = $pdo->lastInsertId();
    }
}

$added = $updated = $skipped = $tagged = 0;
$rows = [];

foreach ($nodes as $node) {
    $href = $node->getAttribute('href');
    if (!preg_match('#/artist/\d+$#', $href)) continue;

    $nameNodes    = $xpath->query('.//div[contains(@class,"c-artist-index__name")]', $node);
    $readingNodes = $xpath->query('.//div[contains(@class,"c-artist-index__kana")]', $node);

    if ($nameNodes->length === 0) continue;
    $name    = trim($nameNodes->item(0)->textContent);
    $reading = $readingNodes->length > 0 ? trim($readingNodes->item(0)->textContent) : null;
    if (!$name) continue;

    $stmt = $pdo->prepare("SELECT id, reading FROM artists WHERE name = ?");
    $stmt->execute([$name]);
    $row = $stmt->fetch();

    if ($row) {
        $artist_id = $row['id'];
        if (!$row['reading'] && $reading) {
            if (!$dry_run) {
                $pdo->prepare("UPDATE artists SET reading = ? WHERE id = ?")->execute([$reading, $artist_id]);
            }
            $rows[] = ['tag' => '更新', 'name' => $name, 'reading' => $reading];
            $updated++;
        } else {
            $rows[] = ['tag' => 'スキップ', 'name' => $name, 'reading' => $row['reading'] ?: ''];
            $skipped++;
        }
    } else {
        if (!$dry_run) {
            $pdo->prepare("INSERT INTO artists (name, reading) VALUES (?, ?)")->execute([$name, $reading]);
            $artist_id = $pdo->lastInsertId();
        } else {
            $artist_id = null;
        }
        $rows[] = ['tag' => '追加', 'name' => $name, 'reading' => $reading ?? ''];
        $added++;
    }

    // タグ付与
    if (!$dry_run && $artist_id && $tag_id) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO artist_tags (artist_id, tag_id) VALUES (?, ?)");
        $stmt->execute([$artist_id, $tag_id]);
        if ($stmt->rowCount() > 0) $tagged++;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>rockinon アーティスト取込 (<?= htmlspecialchars($tag_name) ?>)</title>
<style>
body { font-family: sans-serif; font-size: 14px; padding: 20px; }
h1 { font-size: 18px; }
.summary { margin: 12px 0; font-weight: bold; }
.dry { background: #fff3cd; padding: 6px 12px; border-left: 4px solid #ffc107; margin-bottom: 12px; }
table { border-collapse: collapse; width: 100%; max-width: 700px; }
th, td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
th { background: #f4f4f4; }
.tag-追加    { color: #1a7f4f; font-weight: bold; }
.tag-更新    { color: #0056b3; font-weight: bold; }
.tag-スキップ { color: #888; }
</style>
</head>
<body>
<h1>rockinon アーティスト取込 — <?= htmlspecialchars($tag_name) ?></h1>
<p>カテゴリ切替: <a href="?category=1<?= $dry_run?'&dry=1':'' ?>">邦楽</a> / <a href="?category=2<?= $dry_run?'&dry=1':'' ?>">洋楽</a></p>

<?php if ($dry_run): ?>
<div class="dry">ドライランモード（DBは変更されていません）。実際に取込むには <a href="?category=<?= $category ?>">こちら</a></div>
<?php else: ?>
<p>取込元: <code><?= htmlspecialchars($url) ?></code></p>
<?php endif; ?>

<div class="summary">
  追加: <?= $added ?> / 更新: <?= $updated ?> / スキップ: <?= $skipped ?>
  （合計: <?= count($rows) ?> 件）
  ／ 付与タグ: <strong><?= htmlspecialchars($tag_name) ?></strong>
  <?php if (!$dry_run): ?>（新規付与: <?= $tagged ?> 件）<?php else: ?>（ドライランのため未付与）<?php endif; ?>
</div>

<table>
<tr><th>結果</th><th>アーティスト名</th><th>よみがな</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="tag-<?= $r['tag'] ?>"><?= $r['tag'] ?></td>
  <td><?= htmlspecialchars($r['name']) ?></td>
  <td><?= htmlspecialchars($r['reading']) ?></td>
</tr>
<?php endforeach; ?>
</table>
</body>
</html>
