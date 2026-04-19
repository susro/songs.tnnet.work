<?php
// ============================================
// config.php - DB接続・共通設定
// 場所: songs.tnnet.work/config.php
// ============================================

// エラー表示（本番では false に変更）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// --------------------------------------------
// DB設定（★ここを自分の情報に書き換える）
// --------------------------------------------
define('DB_HOST', 'localhost'); // ★Coreserverのホスト名
define('DB_NAME', 'tnnet_songs');
define('DB_USER', 'tnnet_songs');            // ★DBユーザー名
define('DB_PASS', '2469Songs');        // ★DBパスワード

// Yahoo! ルビ振りAPI Client ID（https://developer.yahoo.co.jp/）
define('YAHOO_CLIENT_ID', 'dmVyPTIwMjUwNyZpZD1hdkhkSGp1b1l3Jmhhc2g9WldVM1pUUTFNelk0Tm1aak5URTFPUQ');         // ★ここにClient IDを貼る

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die("DB接続エラー: " . $e->getMessage());
}

require_once __DIR__ . '/auth.php';
