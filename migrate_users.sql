-- ============================================
-- ユーザー機能 Migration
-- 実行: Coreserver の phpMyAdmin or mysql CLI
-- ============================================

-- 1. usersテーブル
CREATE TABLE IF NOT EXISTS users (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(50)  NOT NULL,
  invite_code  VARCHAR(32)  NOT NULL UNIQUE,
  is_admin     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. 招待コードを事前登録（あとでbulider.phpから管理予定）
--    ★ invite_code の値は自分で好きな文字列に変える
INSERT IGNORE INTO users (name, invite_code, is_admin)
VALUES ('管理人', 'ADMIN_CODE_CHANGE_ME', 1);

-- 3. songlists に user_id を追加
ALTER TABLE songlists
  ADD COLUMN user_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
  ADD INDEX idx_user_id (user_id);

-- 既存リストは管理人（id=1）に帰属させる
UPDATE songlists SET user_id = 1 WHERE user_id IS NULL;

-- 4. song_tags に user_id を追加（personal タグはユーザー別）
ALTER TABLE song_tags
  ADD COLUMN user_id INT UNSIGNED NULL DEFAULT NULL,
  ADD INDEX idx_st_user_id (user_id);

-- 既存の personal タグは管理人に帰属
UPDATE song_tags st
  JOIN tags t ON st.tag_id = t.id
  SET st.user_id = 1
  WHERE t.tag_category = 'personal' AND st.user_id IS NULL;
