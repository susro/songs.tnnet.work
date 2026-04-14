<?php
require_once __DIR__ . '/../config.php';

function getOrCreateTag($pdo, $name, $type='artist') {
    $stmt = $pdo->prepare("SELECT id FROM tags WHERE name=?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return $id;

    $stmt = $pdo->prepare("INSERT INTO tags (name, type) VALUES (?, ?)");
    $stmt->execute([$name, $type]);
    return $pdo->lastInsertId();
}

$stmt = $pdo->query("SELECT id, debut_year FROM artists");
$artists = $stmt->fetchAll();

foreach ($artists as $a) {
    if (!$a['debut_year']) continue;

    $decade = floor($a['debut_year'] / 10) * 10;
    $tag_name = $decade . "年代";

    $tag_id = getOrCreateTag($pdo, $tag_name, 'artist');

    $stmt2 = $pdo->prepare("INSERT IGNORE INTO artist_tags (artist_id, tag_id) VALUES (?, ?)");
    $stmt2->execute([$a['id'], $tag_id]);
    if ($stmt2->rowCount() > 0) {
        echo "年代タグ付与: Artist {$a['id']} → {$tag_name}<br>";
    }
}

echo "年代タグ付与完了";
