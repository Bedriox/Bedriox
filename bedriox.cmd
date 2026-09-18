@echo off
setlocal
set "BEDRIOX_ROOT=%~dp0"
set "BEDRIOX_RUNTIME_ROOT=%BEDRIOX_ROOT%bin"
set "PHPRC="
set "PHP_INI_SCAN_DIR="
set "OPENSSL_CONF="
set "OPENSSL_MODULES="
set "SSL_CERT_DIR="
set "SSL_CERT_FILE="
set "CURL_CA_BUNDLE="
"%BEDRIOX_RUNTIME_ROOT%\php.exe" -c "%BEDRIOX_RUNTIME_ROOT%\php.ini" "%BEDRIOX_ROOT%bootstrap\bedriox.php" %*
exit /b %ERRORLEVEL%
