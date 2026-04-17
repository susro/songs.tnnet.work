<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function ok(mixed $data): never {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {

    // ---- 全リスト一覧 ----
    case 'list':
        $rows = $pdo->query("
            SELECT sl.id, sl.name, sl.memo,
                   sl.updated_at,
                   COUNT(ss.song_id) AS song_count
            FROM songlists sl
            LEFT JOIN songlist_songs ss ON sl.id = ss.songlist_id
            GROUP BY sl.id ORDER BY sl.updated_at DESC
        ")->fetchAll();
        ok($rows);

    // ---- リスト内の曲一覧 ----
    case 'songs':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('IDが必要');
        $rows = $pdo->prepare("
            SELECT s.id, s.title, s.release_year, s.youtube_url,
                   a.name AS artist_name, ss.position
            FROM songlist_songs ss
            JOIN songs s ON ss.song_id = s.id
            LEFT JOIN artists a ON s.artist_id = a.id
            WHERE ss.songlist_id = ?
            ORDER BY ss.position ASC, ss.added_at ASC
        ");
        $rows->execute([$id]);
        ok($rows->fetchAll());

    // ---- リスト作成 ----
    case 'create':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') err('リスト名が必要です');
        $pdo->prepare("INSERT INTO songlists (name) VALUES (?)")->execute([$name]);
        ok(['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'song_count' => 0]);

    // ---- リスト削除 ----
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) err('IDが必要');
        $pdo->prepare("DELETE FROM songlists WHERE id = ?")->execute([$id]);
        ok(true);

    // ---- 曲を追加 ----
    case 'add_song':
        $listId = (int)($_POST['songlist_id'] ?? 0);
        $songId = (int)($_POST['song_id']     ?? 0);
        if (!$listId || !$songId) err('IDが不正');
        $pdo->prepare(
            "INSERT IGNORE INTO songlist_songs (songlist_id, song_id) VALUES (?, ?)"
        )->execute([$listId, $songId]);
        $pdo->prepare("UPDATE songlists SET updated_at = NOW() WHERE id = ?")->execute([$listId]);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM songlist_songs WHERE songlist_id = ?");
        $cntStmt->execute([$listId]);
        ok(['count' => (int)$cntStmt->fetchColumn()]);

    // ---- 曲を削除 ----
    case 'remove_song':
        $listId = (int)($_POST['songlist_id'] ?? 0);
        $songId = (int)($_POST['song_id']     ?? 0);
        if (!$listId || !$songId) err('IDが不正');
        $pdo->prepare(
            "DELETE FROM songlist_songs WHERE songlist_id = ? AND song_id = ?"
        )->execute([$listId, $songId]);
        $pdo->prepare("UPDATE songlists SET updated_at = NOW() WHERE id = ?")->execute([$listId]);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM songlist_songs WHERE songlist_id = ?");
        $cntStmt->execute([$listId]);
        ok(['count' => (int)$cntStmt->fetchColumn()]);

    default:
        err('不明なアクション');
}
