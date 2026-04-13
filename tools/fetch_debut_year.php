<?php
require_once __DIR__ . '/../config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1回の実行で処理する件数
$LIMIT = 20;

// MusicBrainz API でアーティスト検索
function searchMusicBrainz($name) {
    $url = "https://musicbrainz.org/ws/2/artist/?query=" . urlencode($name) . "&fmt=json";

    // MusicBrainz は User-Agent が必要
    $opts = [
        "http" => [
            "header" => "User-Agent: songs-fetcher/1.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);

    $json = @file_get_contents($url, false, $context);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (!isset($data['artists'][0])) return null;

    return $data['artists'][0];
}

// begin-date を抽出
function extractDebutYear($artistData) {
    if (!isset($artistData['life-span']['begin'])) return null;

    $begin = $artistData['life-span']['begin'];

    if (preg_match('/^(19|20)\d{2}/', $begin, $m)) {
        return intval($m[0]);
    }

    return null;
}

// 未処理のアーティストを20件だけ取得
$stmt = $pdo->prepare("SELECT id, name FROM artists WHERE debut_year IS NULL LIMIT ?");
$stmt->bindValue(1, $LIMIT, PDO::PARAM_INT);
$stmt->execute();
$artists = $stmt->fetchAll();

if (!$artists) {
    echo "すべて処理済み<br>";
    exit;
}

foreach ($artists as $a) {
    $data = searchMusicBrainz($a['name']);

    if (!$data) {
        echo "{$a['name']} → MusicBrainzヒットせず<br>";
        continue;
    }

    $year = extractDebutYear($data);

    if ($year) {
        $stmt2 = $pdo->prepare("UPDATE artists SET debut_year = ? WHERE id = ?");
        $stmt2->execute([$year, $a['id']]);
        echo "{$a['name']} → {$year}<br>";
    } else {
        echo "{$a['name']} → 年取得できず<br>";
    }

    // MusicBrainz に優しく 1秒待つ
    sleep(1);
}

echo "<br>20件処理完了。もう一度実行してください。";
