@echo off
chcp 932 >nul

REM === プロジェクトルートへ移動 ===
cd /d %~dp0

REM === まず pull してローカルを最新化 ===
echo ==== Pulling latest changes ====
git pull --rebase

REM === 変更をステージング ===
git add .

REM === コミットメッセージ入力 ===
set /p msg="Commit message: "

REM === コミット実行 ===
git commit -m "%msg%"

REM === GitHub へ push ====
git push

echo.
echo ==== Push 完了 ====
pause
