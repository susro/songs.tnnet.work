@echo off
setlocal
chcp 65001 >nul

cd /d "%~dp0"

echo ==== Stage changes ====
git add .

set /p msg=Commit message (Enter to skip):
if "%msg%"=="" (
  echo No commit message. Skipping commit.
) else (
  echo ==== Create commit ====
  git commit -m "%msg%"
)

echo ==== Pull latest changes ====
git pull --rebase
if errorlevel 1 goto :fail

echo ==== Push ====
git push
if errorlevel 1 goto :fail

echo.
echo ==== Done ====
pause
exit /b 0

:fail
echo.
echo ==== Failed. Check messages above. ====
pause
exit /b 1
