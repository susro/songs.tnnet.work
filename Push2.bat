@echo off
chcp 65001 >nul

echo ==== Pulling latest changes ====
git pull --rebase
if %errorlevel% neq 0 (
    echo.
    echo *** Pull failed. Uncommitted changes or conflicts exist. ***
    echo *** Resolve them before pushing. ***
    pause
    exit /b
)

echo ==== Staging changes ====
git add .

echo ==== Commit message ====
set /p msg="Commit message: "
if "%msg%"=="" set msg=update

git commit -m "%msg%"

echo ==== Pushing to remote ====
git push
if %errorlevel% neq 0 (
    echo.
    echo *** Push failed. Remote has newer commits. ***
    echo *** Run 'git pull --rebase' manually and resolve. ***
    pause
    exit /b
)

echo ==== Done ====
pause
