<?php
require_once __DIR__ . '/../config.php';

function addTag($pdo, $artist_id, $tag_name) {
    $stmt = $pdo->prepare("SELECT id FROM tags WHERE name=?");
    $stmt->execute([$tag_name]);
    $tag_id = $stmt->fetchColumn();
    if (!$tag_id) return;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM artist_tags WHERE artist_id=? AND tag_id=?");
    $stmt->execute([$artist_id, $tag_id]);
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO artist_tags (artist_id, tag_id) VALUES (?, ?)");
        $stmt->execute([$artist_id, $tag_id]);
        echo "ジャンルタグ付与: Artist {$artist_id} → {$tag_name}<br>";
    }
}

$stmt = $pdo->query("
    SELECT a.id, a.name, GROUP_CONCAT(aa.alias SEPARATOR ' ') AS aliases
    FROM artists a
    LEFT JOIN artist_aliases aa ON a.id = aa.artist_id
    GROUP BY a.id
");
$artists = $stmt->fetchAll();

foreach ($artists as $a) {
    $text = strtolower($a['name'] . ' ' . $a['aliases']);

    // ロック
    if (preg_match('/rock|band|ロック|バンド/', $text)) {
        addTag($pdo, $a['id'], 'ロック');
    }

    // アニソン
    if (preg_match('/anisong|アニソン|fripside|claris|lisa|kalafina/', $text)) {
        addTag($pdo, $a['id'], 'アニソン');
    }

    // ボカロ
    if (preg_match('/vocaloid|ボカロ|p$|ハチ|deco\*27|wowaka|kz/', $text)) {
        addTag($pdo, $a['id'], 'ボカロ');
    }

    // アイドル
    if (preg_match('/48|46|ジャニーズ|idol/', $text)) {
        addTag($pdo, $a['id'], 'アイドル');
    }

    // 小室ファミリー
    if (preg_match('/trf|globe|小室|tm network/', $text)) {
        addTag($pdo, $a['id'], '小室ファミリー');
    }

    // ビーイング系
    if (preg_match('/zard|wands|t-bolan|b\'z|field of view/', $text)) {
        addTag($pdo, $a['id'], 'ビーイング系');
    }

    // V系
    if (preg_match('/dir en grey|the gazette|lynch|v系|ヴィジュアル/', $text)) {
        addTag($pdo, $a['id'], 'V系');
    }
}

echo "ジャンルタグ付与完了";
