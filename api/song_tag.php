<?php
require_once '../config.php';
$me = require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$song_id = (int)($_POST['song_id'] ?? 0);
$tag_id  = (int)($_POST['tag_id']  ?? 0);
if (!$song_id || !$tag_id) { http_response_code(400); echo json_encode(['error'=>'bad params']); exit; }

// personalタグのみ操作可能
$stmt = $pdo->prepare("SELECT id FROM tags WHERE id=? AND tag_category='personal'");
$stmt->execute([$tag_id]);
if (!$stmt->fetchColumn()) { http_response_code(403); echo json_encode(['error'=>'not personal tag']); exit; }

// toggle
$stmt = $pdo->prepare("SELECT 1 FROM song_tags WHERE song_id=? AND tag_id=? AND user_id=?");
$stmt->execute([$song_id, $tag_id, $me['id']]);
$exists = (bool)$stmt->fetchColumn();

if ($exists) {
    $pdo->prepare("DELETE FROM song_tags WHERE song_id=? AND tag_id=? AND user_id=?")->execute([$song_id, $tag_id, $me['id']]);
    echo json_encode(['tagged' => false]);
} else {
    $pdo->prepare("INSERT IGNORE INTO song_tags (song_id, tag_id, user_id) VALUES (?,?,?)")->execute([$song_id, $tag_id, $me['id']]);
    echo json_encode(['tagged' => true]);
}
