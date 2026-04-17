<?php
require_once '../config.php';

$steps = [];

function run(PDO $pdo, string $label, string $sql): array {
    try {
        $pdo->exec($sql);
        return ['label' => $label, 'status' => 'ok', 'msg' => '成功'];
    } catch (PDOException $e) {
        return ['label' => $label, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// 1. tags に tag_category 列追加（official=公式ジャンル系 / personal=マイタグ）
//    既存の type 列（artist/song/both）はそのまま維持
$steps[] = run($pdo, 'tags.tag_category 列追加',
    "ALTER TABLE tags ADD COLUMN tag_category ENUM('official','personal') NOT NULL DEFAULT 'official'"
);

// 2. songlists テーブル作成
$steps[] = run($pdo, 'songlists テーブル作成', "
    CREATE TABLE IF NOT EXISTS songlists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        memo TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// 3. songlist_songs テーブル作成
$steps[] = run($pdo, 'songlist_songs テーブル作成', "
    CREATE TABLE IF NOT EXISTS songlist_songs (
        songlist_id INT NOT NULL,
        song_id INT NOT NULL,
        position INT NOT NULL DEFAULT 0,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (songlist_id, song_id),
        FOREIGN KEY (songlist_id) REFERENCES songlists(id) ON DELETE CASCADE,
        FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// songs に全文検索用インデックス追加（1万曲対応）
$steps[] = run($pdo, 'songs FULLTEXT インデックス追加',
    "ALTER TABLE songs ADD FULLTEXT INDEX ft_title (title)"
);

$allOk = array_reduce($steps, fn($carry, $s) => $carry && $s['status'] === 'ok', true);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Migration v2</title>
<style>
body { font-family: sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; }
h1 { font-size: 1.2rem; }
.ok    { color: #1a6644; background: #e6f6ee; border: 1px solid #7fcca4; }
.error { color: #8b0000; background: #fde8ec; border: 1px solid #f0a0b0; }
.step  { border-radius: 4px; padding: 8px 12px; margin: 8px 0; }
.done  { font-weight: bold; font-size: 1.1rem; margin-top: 20px; }
</style>
</head>
<body>
<h1>DBマイグレーション v2</h1>
<?php foreach ($steps as $s): ?>
    <div class="step <?= $s['status'] ?>">
        <strong><?= htmlspecialchars($s['label']) ?></strong>
        — <?= htmlspecialchars($s['msg']) ?>
    </div>
<?php endforeach; ?>
<p class="done <?= $allOk ? 'ok step' : 'error step' ?>">
    <?= $allOk ? '✅ 全ステップ完了。このファイルは削除してOKです。' : '⚠️ エラーあり。上記を確認してください。' ?>
</p>
<?php if (!$allOk): ?>
<p>※ "Duplicate column" エラーは既に列が存在するので無視してOKです。</p>
<?php endif; ?>
</body>
</html>
