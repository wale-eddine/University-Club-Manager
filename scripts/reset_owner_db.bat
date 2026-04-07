@echo off
setlocal

set "SCRIPT_DIR=%~dp0"
set "PHP_EXE=C:\xampp\php\php.exe"

if not exist "%PHP_EXE%" (
    echo Could not find PHP at "%PHP_EXE%".
    echo Edit scripts\reset_owner_db.bat and update PHP_EXE.
    exit /b 1
)

"%PHP_EXE%" "%SCRIPT_DIR%reset_owner_db.php" %*
exit /b %ERRORLEVEL%
