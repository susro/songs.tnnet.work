<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$me = require_login();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function ok($data) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($action) {

    // ---- 全リスト一覧（自分のリストのみ） ----
    case 'list':
        $stmt = $pdo->prepare("
            SELECT sl.id, sl.name, sl.memo, sl.updated_at,
                   sl.list_type, sl.filter_config,
                   COUNT(ss.song_id) AS song_count
            FROM songlists sl
            LEFT JOIN songlist_songs ss ON sl.id = ss.songlist_id AND sl.list_type = 'static'
            WHERE sl.user_id = ?
            GROUP BY sl.id ORDER BY sl.list_type DESC, sl.updated_at DESC
        ");
        $stmt->execute([$me['id']]);
        $lists = $stmt->fetchAll();
        // 動的リストの曲数を計算
        foreach ($lists as &$sl) {
            if ($sl['list_type'] === 'dynamic') {
                $cfg = json_decode($sl['filter_config'] ?? '{}', true);
                if (!empty($cfg['personal_tag'])) {
                    $c = $pdo->prepare("SELECT COUNT(*) FROM song_tags st JOIN tags t ON st.tag_id=t.id WHERE t.name=? AND st.user_id=?");
                    $c->execute([$cfg['personal_tag'], $me['id']]);
                    $sl['song_count'] = (int)$c->fetchColumn();
                }
            }
        }
        unset($sl);
        ok($lists);

    // ---- リスト内の曲一覧 ----
    case 'songs':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) err('IDが必要');
        $list = $pdo->prepare("SELECT list_type, filter_config FROM songlists WHERE id=? AND user_id=?");
        $list->execute([$id, $me['id']]);
        $listRow = $list->fetch();
        if (!$listRow) err('リストが見つかりません', 404);

        if ($listRow['list_type'] === 'dynamic') {
            $cfg = json_decode($listRow['filter_config'] ?? '{}', true);
            $personalTag = $cfg['personal_tag'] ?? '';
            if (!$personalTag) ok([]);
            $rows = $pdo->prepare("
                SELECT s.id, s.title, s.release_year, s.youtube_url, s.dam_number,
                       a.name AS artist_name
                FROM song_tags st
                JOIN tags t  ON st.tag_id = t.id
                JOIN songs s ON st.song_id = s.id
                LEFT JOIN artists a ON s.artist_id = a.id
                WHERE t.name = ? AND st.user_id = ?
                ORDER BY s.id DESC
            ");
            $rows->execute([$personalTag, $me['id']]);
        } else {
            $rows = $pdo->prepare("
                SELECT s.id, s.title, s.release_year, s.youtube_url, s.dam_number,
                       a.name AS artist_name, ss.position
                FROM songlist_songs ss
                JOIN songs s ON ss.song_id = s.id
                LEFT JOIN artists a ON s.artist_id = a.id
                WHERE ss.songlist_id = ?
                ORDER BY ss.position ASC, ss.added_at ASC
            ");
            $rows->execute([$id]);
        }
        ok($rows->fetchAll());

    // ---- リスト作成 ----
    case 'create':
        $name = trim($_POST['name'] ?? '');
        if ($name === '') err('リスト名が必要です');
        $pdo->prepare("INSERT INTO songlists (user_id, name) VALUES (?, ?)")->execute([$me['id'], $name]);
        ok(['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'song_count' => 0]);

    // ---- リスト削除（自分のリストのみ） ----
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) err('IDが必要');
        $pdo->prepare("DELETE FROM songlists WHERE id = ? AND user_id = ?")->execute([$id, $me['id']]);
        ok(true);

    // ---- 曲を追加（静的リストのみ） ----
    case 'add_song':
        $listId = (int)($_POST['songlist_id'] ?? 0);
        $songId = (int)($_POST['song_id']     ?? 0);
        if (!$listId || !$songId) err('IDが不正');
        $typeCheck = $pdo->prepare("SELECT list_type FROM songlists WHERE id=? AND user_id=?");
        $typeCheck->execute([$listId, $me['id']]);
        $lt = $typeCheck->fetchColumn();
        if ($lt === 'dynamic') err('動的リストには手動追加できません');
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
