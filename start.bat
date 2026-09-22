@echo off
setlocal
REM Lanceur Windows d'Atelier : verifie PHP, initialise la base si besoin, demarre le serveur integre.
cd /d "%~dp0"

set "PHP="
if exist "%~dp0_tools\php84\php.exe" set "PHP=%~dp0_tools\php84\php.exe"
if "%PHP%"=="" if exist "%~dp0_tools\php\php.exe" set "PHP=%~dp0_tools\php\php.exe"
if "%PHP%"=="" (
    where php >nul 2>nul && set "PHP=php"
)
if "%PHP%"=="" (
    echo [Atelier] PHP introuvable. Installez PHP 8.4 ou placez une version portable dans _tools\php84\
    pause
    exit /b 1
)

"%PHP%" -r "exit(version_compare(PHP_VERSION, '8.4.0', '>=') ? 0 : 1);"
if errorlevel 1 (
    echo [Atelier] PHP 8.4 ou superieur est requis.
    "%PHP%" -v
    pause
    exit /b 1
)
"%PHP%" -r "exit(extension_loaded('pdo_sqlite') && extension_loaded('mbstring') ? 0 : 1);"
if errorlevel 1 (
    echo [Atelier] Extensions PHP requises manquantes : pdo_sqlite, mbstring. Verifiez php.ini.
    pause
    exit /b 1
)

if not exist "var\data\atelier.sqlite" (
    echo [Atelier] Premiere utilisation : creation de la base et des donnees de demonstration...
    "%PHP%" tools\console.php db:migrate
    "%PHP%" tools\console.php db:seed
) else (
    "%PHP%" tools\console.php db:migrate >nul
)

set "HOST=127.0.0.1"
set "PORT=8000"
if not "%~1"=="" set "PORT=%~1"

echo [Atelier] Serveur de developpement : http://%HOST%:%PORT%  (Ctrl+C pour arreter)
start "" "http://%HOST%:%PORT%/"
"%PHP%" -S %HOST%:%PORT% -t public tools\dev-router.php
endlocal
