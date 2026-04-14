@echo off
setlocal
chcp 65001 >nul

cd /d "%~dp0"

echo ==== Pull latest changes ====
git pull --rebase
if errorlevel 1 goto :fail

echo ==== Stage changes ====
git add .
if errorlevel 1 goto :fail

set /p msg=Commit message:
if "%msg%"=="" set msg=Update

echo ==== Create commit ====
git commit -m "%msg%"
if errorlevel 1 (
  echo Commit was skipped or failed.
)

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
