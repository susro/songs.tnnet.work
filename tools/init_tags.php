<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';

$initial_tags = [
    // 年代
    ['name' => '70年代', 'type' => 'artist'],
    ['name' => '80年代', 'type' => 'artist'],
    ['name' => '90年代', 'type' => 'artist'],
    ['name' => '2000年代', 'type' => 'artist'],
    ['name' => '2010年代', 'type' => 'artist'],
    ['name' => '2020年代', 'type' => 'artist'],

    // ジャンル
    ['name' => 'ロック', 'type' => 'both'],
    ['name' => 'J-POP', 'type' => 'both'],
    ['name' => 'アニソン', 'type' => 'both'],
    ['name' => 'ボカロ', 'type' => 'both'],
    ['name' => 'アイドル', 'type' => 'artist'],
    ['name' => '小室ファミリー', 'type' => 'artist'],
    ['name' => 'ビーイング系', 'type' => 'artist'],
    ['name' => 'V系', 'type' => 'artist'],
];

foreach ($initial_tags as $tag) {
    $stmt = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
    $stmt->execute([$tag['name']]);
    if (!$stmt->fetchColumn()) {
        $stmt = $pdo->prepare("INSERT INTO tags (name, type) VALUES (?, ?)");
        $stmt->execute([$tag['name'], $tag['type']]);
        echo "追加: {$tag['name']}<br>";
    }
}

echo "初期タグ投入完了";
