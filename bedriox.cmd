@echo off
setlocal
set "BEDRIOX_ROOT=%~dp0"
set "BEDRIOX_RUNTIME_ROOT=%BEDRIOX_ROOT%bin"
set "BEDRIOX_RUNTIME_CACHE=%BEDRIOX_ROOT%cache\runtime"
if not exist "%BEDRIOX_RUNTIME_CACHE%\opcache" mkdir "%BEDRIOX_RUNTIME_CACHE%\opcache" 2>nul
if not exist "%BEDRIOX_RUNTIME_CACHE%\opcache" (
    echo Bedriox could not create its runtime cache. 1>&2
    exit /b 1
)
set "PHPRC="
set "PHP_INI_SCAN_DIR="
set "OPENSSL_CONF=%BEDRIOX_RUNTIME_ROOT%\config\openssl.cnf"
set "OPENSSL_MODULES="
set "SSL_CERT_DIR="
set "SSL_CERT_FILE="
set "CURL_CA_BUNDLE="
"%BEDRIOX_RUNTIME_ROOT%\php.exe" -c "%BEDRIOX_RUNTIME_ROOT%\php.ini" "%BEDRIOX_ROOT%bootstrap\bedriox.php" %*
exit /b %ERRORLEVEL%
