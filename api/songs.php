<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$q        = trim($_GET['q']        ?? '');
$tag      = trim($_GET['tag']      ?? '');
$artistId = (int)($_GET['artist_id'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 50;
$off      = ($page - 1) * $per;

function searchVariants(string $q): array {
    $list = [$q];
    $a = mb_convert_kana($q, 'as',  'UTF-8'); if ($a !== $q)           $list[] = $a;
    $k = mb_convert_kana($q, 'KV',  'UTF-8'); if ($k !== $q)           $list[] = $k;
    $h = mb_convert_kana($k, 'c',   'UTF-8'); if (!in_array($h,$list)) $list[] = $h;
    $c = mb_convert_kana($q, 'C',   'UTF-8'); if (!in_array($c,$list)) $list[] = $c;
    return array_unique($list);
}

$where  = [];
$params = [];

if ($q !== '') {
    $variants = searchVariants($q);
    $orClauses = [];
    foreach ($variants as $v) {
        $orClauses[] = '(s.title LIKE ? OR a.name LIKE ? OR a.reading LIKE ?)';
        $params[] = "%$v%";
        $params[] = "%$v%";
        $params[] = "%$v%";
    }
    $where[] = '(' . implode(' OR ', $orClauses) . ')';
}

if ($artistId > 0) {
    $where[]  = 's.artist_id = ?';
    $params[] = $artistId;
}

$from = "FROM songs s LEFT JOIN artists a ON s.artist_id = a.id";

if ($tag !== '') {
    $from    .= " LEFT JOIN artist_tags at2 ON a.id = at2.artist_id"
              . " LEFT JOIN tags t ON at2.tag_id = t.id";
    $where[]  = 't.name = ?';
    $params[] = $tag;
}

$wc = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$cntStmt = $pdo->prepare("SELECT COUNT(DISTINCT s.id) $from $wc");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();

$dataStmt = $pdo->prepare(
    "SELECT DISTINCT s.id, s.title, s.release_year, s.youtube_url, s.dam_number, s.joysound_number,
            a.id AS artist_id, a.name AS artist_name
     $from $wc ORDER BY s.release_year DESC, s.id DESC LIMIT $per OFFSET $off"
);
$dataStmt->execute($params);
$songs = $dataStmt->fetchAll();

echo json_encode([
    'songs' => $songs,
    'total' => $total,
    'page'  => $page,
    'pages' => (int)ceil($total / $per),
], JSON_UNESCAPED_UNICODE);
