@echo off
REM Runs artisan with this project's extra PHP config (php.d\pgsql.ini), which loads the
REM PostgreSQL driver. Global C:\xampp\php\php.ini is left alone, so other XAMPP projects
REM are unaffected. Usage: artisan screener:run
setlocal
set "PHP_INI_SCAN_DIR=%~dp0php.d"
php "%~dp0artisan" %*
endlocal
