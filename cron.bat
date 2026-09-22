@echo off
setlocal
REM Taches de fond Atelier (Windows) : a planifier toutes les 5 a 15 minutes avec le Planificateur de taches, par exemple :
REM   schtasks /Create /SC MINUTE /MO 10 /TN "Atelier cron" /TR "\"%~dp0cron.bat\"" /F
cd /d "%~dp0"
set "PHP="
if exist "%~dp0_tools\php84\php.exe" set "PHP=%~dp0_tools\php84\php.exe"
if "%PHP%"=="" if exist "%~dp0_tools\php\php.exe" set "PHP=%~dp0_tools\php\php.exe"
if "%PHP%"=="" set "PHP=php"
"%PHP%" tools\console.php cron:run %*
endlocal
