# PHP 8.4 (NTS x64) no IIS

Instalação em `C:\PHP8.4` (drivers `php_sqlsrv_84_nts_x64.dll` e `php_pdo_sqlsrv_84_nts_x64.dll` já em `C:\PHP8.4\ext`).

1. Instalar `VC_redist.x64.exe` e `msodbcsql.msi` (ODBC Driver 18).
2. Copiar `docs/php/php.ini` para `C:\PHP8.4\php.ini`.
3. Criar as pastas `C:\PHP8.4\tmp`, `C:\PHP8.4\sessions` e `C:\PHP8.4\logs`, com permissão de escrita para o usuário do pool do IIS.
4. IIS: habilitar o recurso **CGI** e instalar o **URL Rewrite**.
5. Registrar o PHP no FastCGI (PowerShell como administrador):

   ```powershell
   & "$env:windir\system32\inetsrv\appcmd.exe" set config /section:system.webServer/fastCgi /+"[fullPath='C:\PHP8.4\php-cgi.exe']"
   & "$env:windir\system32\inetsrv\appcmd.exe" set config /section:system.webServer/fastCgi /"[fullPath='C:\PHP8.4\php-cgi.exe']".instanceMaxRequests:10000
   & "$env:windir\system32\inetsrv\appcmd.exe" set config /section:system.webServer/fastCgi /+"[fullPath='C:\PHP8.4\php-cgi.exe'].environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']"
   ```

6. Desbloquear a seção `handlers`, que o IIS bloqueia por padrão nos `web.config` dos sites (sem isso: "Esta seção de configuração não pode ser usada nesse caminho"):

   ```powershell
   & "$env:windir\system32\inetsrv\appcmd.exe" unlock config -section:system.webServer/handlers
   ```

7. O site aponta para `web\public` (o `web.config` de lá já traz o manipulador `*.php`, o rewrite e o limite de upload).
8. Conferir: `C:\PHP8.4\php.exe -m` deve listar `pdo_sqlsrv`, `sqlsrv`, `sodium`, `mbstring`, `openssl`.

O cookie de sessão é `Secure` (`session.cookie_secure = 1`): acessar o admin por HTTPS.
