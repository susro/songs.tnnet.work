# Handover

このファイルは、次チャット開始時に最初に読むための引き継ぎメモ。

## プロジェクト

- サイト: `songs.tnnet.work`
- Git管理ルート: `public_html`
- DB: `tnnet_songs`（MariaDB）

## 現在の方針

- 優先: 検索/一覧/取り込み導線の使いやすさ
- 画面分離:
  - 使用モード: `index.php`
  - 構築モード: `builder.php`
- コミットメッセージは日本語で残す

## 主要ファイル

- `public_html/index.php` トップ画面
- `public_html/builder.php` 曲を増やす画面（旧 `admin.php`）
- `public_html/artists.php` アーティスト一覧
- `public_html/add.php` 曲追加
- `public_html/assets/app.css` 共通スタイル

## 最近の重要変更（要点）

- `admin.php` を `builder.php` に改名（`admin.php` は互換リダイレクト）
- Builderで以下実装済み:
  - カード選択ON/OFF
  - タグ複数選択フィルタ
  - 一括取り込み（まとめて楽曲Get）
  - 実行中オーバーレイ + 完了ダイアログ
  - 最終採集日表示、未Getバッジ
  - 未登録アーティスト追加モーダル
- テーマ:
  - ネオン/サンセット/ミント + クリームA/B/C
  - `localStorage` で全ページ維持
- トップ:
  - 右上（hero内）に `楽曲一括Get！` ボタン
  - 管理モードセクションは廃止

## 既知メモ

- Builder初期表示で採集中オーバーレイが残る不具合は対策済み（再発時はJS実行順と`hidden`状態を確認）
- 表記ゆれ（例: 五木ひろし / いつきひろし）は完全自動解決まだ。現状は候補確認の初期対応まで

## 次チャットで最初にやること（候補）

1. クリーム系テーマのコントラスト最終調整
2. BuilderフィルタUIの最終整形（密度/整列/スマホ）
3. 未登録アーティスト追加モーダルの候補選択フロー強化
4. 表記ゆれ・重複対策の運用方針決定（aliases活用含む）

## 直近コミット

- `42bc5c2` Builder画面の崩れ修正とテーマ選択を再構成
- `f418c3d` Builder画面の固まり対策と導線レイアウトを調整
- `f3a587d` adminページをbuilderへ改名し互換リダイレクトを追加
