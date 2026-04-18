<?php
require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

$q         = trim($_GET['q']   ?? '');
$tagFilter = trim($_GET['tag'] ?? '');

function searchVariants(string $q): array {
    $list = [$q];
    $a = mb_convert_kana($q, 'as', 'UTF-8'); if ($a !== $q)           $list[] = $a;
    $k = mb_convert_kana($q, 'KV', 'UTF-8'); if ($k !== $q)           $list[] = $k;
    $h = mb_convert_kana($k, 'c',  'UTF-8'); if (!in_array($h,$list)) $list[] = $h;
    $c = mb_convert_kana($q, 'C',  'UTF-8'); if (!in_array($c,$list)) $list[] = $c;
    return array_unique($list);
}

$where  = [];
$params = [];

if ($q !== '') {
    $variants = searchVariants($q);
    $orClauses = [];
    foreach ($variants as $v) {
        $orClauses[] = '(a.name LIKE ? OR a.reading LIKE ? OR EXISTS (SELECT 1 FROM artist_aliases aa WHERE aa.artist_id=a.id AND aa.alias LIKE ?))';
        $params[] = "%{$v}%";
        $params[] = "%{$v}%";
        $params[] = "%{$v}%";
    }
    $where[] = '(' . implode(' OR ', $orClauses) . ')';
}
if ($tagFilter !== '') {
    $where[]  = 'EXISTS (SELECT 1 FROM artist_tags atf JOIN tags tf ON atf.tag_id=tf.id WHERE atf.artist_id=a.id AND tf.name=?)';
    $params[] = $tagFilter;
}

$wc = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT a.id, a.name, a.reading,
           COUNT(DISTINCT s.id) AS song_count,
           GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR '|') AS tags
    FROM artists a
    LEFT JOIN songs s         ON s.artist_id = a.id
    LEFT JOIN artist_tags at2 ON a.id = at2.artist_id
    LEFT JOIN tags t          ON at2.tag_id = t.id
    $wc
    GROUP BY a.id, a.name, a.reading
    ORDER BY
      CASE WHEN a.reading IS NULL OR a.reading='' THEN 1 ELSE 0 END,
      a.reading, a.name
");
$stmt->execute($params);
$artists = $stmt->fetchAll();

echo json_encode(['artists' => $artists], JSON_UNESCAPED_UNICODE);
