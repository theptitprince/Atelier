@echo off
setlocal
REM Console d'administration Atelier (Windows) : console.bat help
cd /d "%~dp0"
set "PHP="
if exist "%~dp0_tools\php84\php.exe" set "PHP=%~dp0_tools\php84\php.exe"
if "%PHP%"=="" if exist "%~dp0_tools\php\php.exe" set "PHP=%~dp0_tools\php\php.exe"
if "%PHP%"=="" set "PHP=php"
"%PHP%" tools\console.php %*
endlocal
