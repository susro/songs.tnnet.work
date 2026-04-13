<?php
require_once 'config.php';
require_once 'artists_list.php';

foreach ($artists as $a) {

    // artists に存在するか確認
    $stmt = $pdo->prepare("SELECT id FROM artists WHERE name = ?");
    $stmt->execute([$a['name']]);
    $artist_id = $stmt->fetchColumn();

    if (!$artist_id) {
        // artists に追加
        $stmt = $pdo->prepare("INSERT INTO artists (name) VALUES (?)");
        $stmt->execute([$a['name']]);
        $artist_id = $pdo->lastInsertId();
        echo "追加: {$a['name']}<br>";
    }

    // alias を追加
    if (!empty($a['aliases'])) {
        foreach ($a['aliases'] as $alias) {
            if (!$alias) continue;

            // 重複チェック
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM artist_aliases WHERE alias = ?");
            $stmt->execute([$alias]);
            if ($stmt->fetchColumn() > 0) continue;

            $stmt = $pdo->prepare("INSERT INTO artist_aliases (artist_id, alias) VALUES (?, ?)");
            $stmt->execute([$artist_id, $alias]);

            echo " └ alias追加: {$alias}<br>";
        }
    }
}

echo "<br>完了！";
