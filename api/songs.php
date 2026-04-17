<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$q    = trim($_GET['q']   ?? '');
$tag  = trim($_GET['tag'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;
$off  = ($page - 1) * $per;

$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = '(s.title LIKE ? OR a.name LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
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
    "SELECT DISTINCT s.id, s.title, s.release_year, s.youtube_url, a.name AS artist_name
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
