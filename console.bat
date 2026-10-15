@echo off
REM Atelier, par ETDEL -- https://github.com/theptitprince/Atelier
REM Copyright (C) 2026 ETDEL -- licence GPL-3.0-or-later
REM Conditions additionnelles (article 7) : voir le fichier NOTICE.
setlocal
REM Console d'administration Atelier (Windows) : console.bat help
cd /d "%~dp0"
set "PHP="
if exist "%~dp0_tools\php84\php.exe" set "PHP=%~dp0_tools\php84\php.exe"
if "%PHP%"=="" if exist "%~dp0_tools\php\php.exe" set "PHP=%~dp0_tools\php\php.exe"
if "%PHP%"=="" set "PHP=php"
"%PHP%" tools\console.php %*
endlocal
