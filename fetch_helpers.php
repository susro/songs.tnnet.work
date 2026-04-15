<?php
function normalizeArtistName(string $name): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
}

function normalizeSongTitle(string $title): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title)));
}

function ensureImportHistoryTables(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS import_fetch_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    artist_count INT NOT NULL,
    song_count INT NOT NULL,
    status ENUM('completed','undone') NOT NULL DEFAULT 'completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS import_fetch_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    song_id INT NOT NULL,
    artist_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    release_year INT NULL,
    FOREIGN KEY (batch_id) REFERENCES import_fetch_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
    );
}

function gatherExistingSongTitles(PDO $pdo, int $artistId): array {
    $stmt = $pdo->prepare("SELECT title FROM songs WHERE artist_id = ?");
    $stmt->execute([$artistId]);
    $titles = array_map('normalizeSongTitle', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_flip($titles);
}

function fetchCandidateSongsForArtist(PDO $pdo, int $artistId): array {
    $stmt = $pdo->prepare("SELECT name FROM artists WHERE id = ?");
    $stmt->execute([$artistId]);
    $artistName = (string)$stmt->fetchColumn();
    if ($artistName === '') {
        return [
            'artist_id' => $artistId,
            'artist_name' => '(不明)',
            'candidates' => [],
            'existing' => 0,
            'api_term_count' => 0,
            'error' => 'アーティストが見つかりません',
        ];
    }

    $stmt = $pdo->prepare("SELECT alias FROM artist_aliases WHERE artist_id = ?");
    $stmt->execute([$artistId]);
    $aliases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $keywords = array_unique(array_filter(array_merge([$artistName], $aliases)));
    $normalizedArtistNames = array_values(array_filter(array_unique(array_map('normalizeArtistName', $keywords))));
    $existingTitles = gatherExistingSongTitles($pdo, $artistId);
    $candidates = [];
    $candidateMap = [];
    $apiTermCount = 0;

    foreach ($keywords as $word) {
        $word = trim((string)$word);
        if ($word === '' || mb_strlen($word) < 2) {
            continue;
        }

        $url = "https://itunes.apple.com/search?term=" . urlencode($word) . "&country=jp&entity=song&attribute=artistTerm&limit=200";
        $jsonText = @file_get_contents($url);
        if ($jsonText === false) {
            continue;
        }
        $json = json_decode($jsonText, true);
        if (!$json || !isset($json['results']) || !is_array($json['results'])) {
            continue;
        }

        $apiTermCount++;
        foreach ($json['results'] as $song) {
            $resultArtist = normalizeArtistName((string)($song['artistName'] ?? ''));
            if ($resultArtist === '' || !in_array($resultArtist, $normalizedArtistNames, true)) {
                continue;
            }

            $title = trim((string)($song['trackName'] ?? ''));
            if ($title === '') {
                continue;
            }
            $normalizedTitle = normalizeSongTitle($title);
            if (isset($existingTitles[$normalizedTitle])) {
                continue;
            }

            $year = substr((string)($song['releaseDate'] ?? ''), 0, 4);
            $yearValue = ctype_digit($year) ? (int)$year : null;
            $trackId = isset($song['trackId']) ? (int)$song['trackId'] : 0;
            $candidateKey = $trackId > 0 ? "track:{$trackId}" : "title:{$normalizedTitle}|{$yearValue}";
            if (isset($candidateMap[$candidateKey])) {
                continue;
            }

            $candidateMap[$candidateKey] = true;
            $candidates[] = [
                'track_id' => $trackId,
                'title' => $title,
                'release_year' => $yearValue,
                'artist_name' => $song['artistName'] ?? '',
                'search_term' => $word,
                'key' => "{$artistId}:" . ($trackId > 0 ? "track:{$trackId}" : "title:{$normalizedTitle}|{$yearValue}"),
            ];
        }
    }

    return [
        'artist_id' => $artistId,
        'artist_name' => $artistName,
        'candidates' => $candidates,
        'existing' => count($existingTitles),
        'api_term_count' => $apiTermCount,
        'error' => '',
    ];
}

function createImportBatch(PDO $pdo, int $artistCount, int $songCount): int {
    $stmt = $pdo->prepare("INSERT INTO import_fetch_batches (artist_count, song_count) VALUES (?, ?)");
    $stmt->execute([$artistCount, $songCount]);
    return (int)$pdo->lastInsertId();
}

function insertImportItem(PDO $pdo, int $batchId, int $songId, int $artistId, string $title, ?int $releaseYear): void {
    $stmt = $pdo->prepare("INSERT INTO import_fetch_items (batch_id, song_id, artist_id, title, release_year) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$batchId, $songId, $artistId, $title, $releaseYear]);
}

function commitCandidateImport(PDO $pdo, array $importCandidates): array {
    $totalSongs = 0;
    foreach ($importCandidates as $candidateGroup) {
        foreach ($candidateGroup['candidates'] as $candidate) {
            $totalSongs++;
        }
    }

    if ($totalSongs === 0) {
        return ['batch_id' => 0, 'inserted' => 0, 'artist_count' => count($importCandidates)];
    }

    ensureImportHistoryTables($pdo);
    $batchId = createImportBatch($pdo, count($importCandidates), $totalSongs);
    $insertSongStmt = $pdo->prepare("INSERT INTO songs (title, artist_id, release_year) VALUES (?, ?, ?)");

    $inserted = 0;
    foreach ($importCandidates as $candidateGroup) {
        $artistId = $candidateGroup['artist_id'];
        foreach ($candidateGroup['candidates'] as $candidate) {
            $insertSongStmt->execute([$candidate['title'], $artistId, $candidate['release_year']]);
            $songId = (int)$pdo->lastInsertId();
            insertImportItem($pdo, $batchId, $songId, $artistId, $candidate['title'], $candidate['release_year']);
            $inserted++;
        }
        $updateArtist = $pdo->prepare("UPDATE artists SET fetch_attempts = fetch_attempts + 1, last_fetch_at = NOW(), fetch_failed = 0 WHERE id = ?");
        $updateArtist->execute([$artistId]);
    }

    if ($batchId > 0) {
        $updateBatch = $pdo->prepare("UPDATE import_fetch_batches SET song_count = ? WHERE id = ?");
        $updateBatch->execute([$inserted, $batchId]);
    }

    return ['batch_id' => $batchId, 'inserted' => $inserted, 'artist_count' => count($importCandidates)];
}

function undoImportBatch(PDO $pdo, int $batchId): array {
    ensureImportHistoryTables($pdo);
    $stmt = $pdo->prepare("SELECT status FROM import_fetch_batches WHERE id = ?");
    $stmt->execute([$batchId]);
    $status = $stmt->fetchColumn();
    if (!$status) {
        return ['success' => false, 'message' => '指定された履歴が見つかりません。'];
    }
    if ($status !== 'completed') {
        return ['success' => false, 'message' => 'この取り込みはすでに取り消されています。'];
    }

    $delete = $pdo->prepare("DELETE s FROM songs s INNER JOIN import_fetch_items i ON s.id = i.song_id WHERE i.batch_id = ?");
    $delete->execute([$batchId]);
    $deleted = $delete->rowCount();

    $update = $pdo->prepare("UPDATE import_fetch_batches SET status = 'undone' WHERE id = ?");
    $update->execute([$batchId]);

    return ['success' => true, 'deleted' => $deleted];
}
