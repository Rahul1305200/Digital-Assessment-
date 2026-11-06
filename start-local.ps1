$ErrorActionPreference = 'Stop'
$project = $PSScriptRoot
$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
if (!(Test-Path -LiteralPath $php)) { throw 'PHP was not found. Update $php to your Laragon PHP executable.' }
try {
    $r = Invoke-WebRequest 'http://127.0.0.1:8089/api.php?action=session' -UseBasicParsing -TimeoutSec 2
    if ($r.StatusCode -eq 200) { Write-Host 'Already running: http://localhost:8089/'; exit }
} catch {}
$public = Join-Path $project 'public'
$router = Join-Path $project 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
Start-Process -FilePath $php -ArgumentList @('-d','upload_max_filesize=100M','-d','post_max_size=105M','-S','127.0.0.1:8089',$router) -WorkingDirectory $public -WindowStyle Hidden -RedirectStandardOutput (Join-Path $project 'storage\logs\server.out.log') -RedirectStandardError (Join-Path $project 'storage\logs\server.err.log')
Write-Host 'Open http://localhost:8089/ (MySQL must be running in Laragon).'
