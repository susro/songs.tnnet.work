<?php
require_once __DIR__ . '/../config.php';

function run(PDO $pdo, string $label, string $sql): array {
    try { $pdo->exec($sql); return ['label'=>$label,'status'=>'ok','msg'=>'成功']; }
    catch (PDOException $e) { return ['label'=>$label,'status'=>'error','msg'=>$e->getMessage()]; }
}

$steps = [];

// 1. song_tags に user_id 追加・PK再構成
$steps[] = run($pdo, 'song_tags: FK削除(1)',  "ALTER TABLE song_tags DROP FOREIGN KEY song_tags_ibfk_1");
$steps[] = run($pdo, 'song_tags: FK削除(2)',  "ALTER TABLE song_tags DROP FOREIGN KEY song_tags_ibfk_2");
$steps[] = run($pdo, 'song_tags: PK削除',     "ALTER TABLE song_tags DROP PRIMARY KEY");
$steps[] = run($pdo, 'song_tags: user_id追加', "ALTER TABLE song_tags ADD COLUMN user_id INT NOT NULL DEFAULT 0");
$steps[] = run($pdo, 'song_tags: PK再作成',   "ALTER TABLE song_tags ADD PRIMARY KEY (song_id, tag_id, user_id)");
$steps[] = run($pdo, 'song_tags: FK再作成(1)', "ALTER TABLE song_tags ADD CONSTRAINT song_tags_ibfk_1 FOREIGN KEY (song_id) REFERENCES songs(id) ON DELETE CASCADE");
$steps[] = run($pdo, 'song_tags: FK再作成(2)', "ALTER TABLE song_tags ADD CONSTRAINT song_tags_ibfk_2 FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE");

// 2. パーソナルタグ追加
$steps[] = run($pdo, 'personalタグ追加', "
    INSERT IGNORE INTO tags (name, type, tag_category) VALUES
    ('お気に入り★', 'song', 'personal'),
    ('練習中',      'song', 'personal'),
    ('My定番',      'song', 'personal')
");

$allOk = array_reduce($steps, fn($c,$s) => $c && $s['status']==='ok', true);
?>
<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><title>Migration v3</title>
<style>body{font-family:sans-serif;max-width:640px;margin:40px auto;padding:0 16px}
.ok{color:#1a6644;background:#e6f6ee;border:1px solid #7fcca4}
.error{color:#8b0000;background:#fde8ec;border:1px solid #f0a0b0}
.step{border-radius:4px;padding:8px 12px;margin:8px 0}</style></head><body>
<h1>DBマイグレーション v3</h1>
<?php foreach($steps as $s): ?>
<div class="step <?=$s['status']?>"><strong><?=htmlspecialchars($s['label'])?></strong> — <?=htmlspecialchars($s['msg'])?></div>
<?php endforeach; ?>
<p class="step <?=$allOk?'ok':'error?>"><?=$allOk?'✅ 完了。このファイルは削除してOKです。':'⚠️ エラーあり（Duplicate/Can\'t drop = 既適用で無視OK）'?></p>
</body></html>
