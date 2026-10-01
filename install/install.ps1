[CmdletBinding()]
param(
    [Parameter()]
    [ValidateNotNullOrEmpty()]
    [string] $Directory = "bedriox-server",

    [Parameter()]
    [switch] $NoStart
)

$ErrorActionPreference = "Stop"
$installBaseUrl = if ($env:BEDRIOX_INSTALL_BASE_URL) { $env:BEDRIOX_INSTALL_BASE_URL.TrimEnd("/") } else { "https://bedriox.com/install" }

if ([System.IO.Path]::GetFullPath($Directory) -eq [System.IO.Path]::GetPathRoot([System.IO.Path]::GetFullPath($Directory))) {
    throw "Bedriox installer: refusing to install into a filesystem root."
}
if (Test-Path -LiteralPath $Directory) {
    throw "Bedriox installer: $Directory already exists. Choose a new directory."
}
if (-not [Environment]::Is64BitOperatingSystem) {
    throw "Bedriox installer: a 64-bit operating system is required."
}

$architecture = if ($env:PROCESSOR_ARCHITEW6432) {
    $env:PROCESSOR_ARCHITEW6432
} else {
    $env:PROCESSOR_ARCHITECTURE
}
if ([string]::IsNullOrWhiteSpace($architecture)) {
    throw "Bedriox installer: unable to determine the Windows CPU architecture."
}
if ($architecture -notin @("AMD64", "x86_64")) {
    throw "Bedriox installer: Windows $architecture is not currently supported."
}

$target = "windows-x86-64"
$temporaryDirectory = Join-Path ([System.IO.Path]::GetTempPath()) ("bedriox-install-" + [Guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null

try {
    Write-Host "Bedriox installer: checking the latest release for $target..."
    $manifest = Invoke-RestMethod -Uri "$installBaseUrl/manifests/$target.json"
    if ($manifest.schema -ne 1 -or $manifest.target -ne $target) {
        throw "Bedriox installer: the release manifest is invalid."
    }

    foreach ($url in @($manifest.phar_url, $manifest.launcher_url, $manifest.runtime_url)) {
        if ([string]::IsNullOrWhiteSpace($url) -or -not $url.StartsWith("https://bedriox.com/downloads/", [StringComparison]::Ordinal)) {
            throw "Bedriox installer: the release manifest contains an untrusted download URL."
        }
    }
    foreach ($digest in @($manifest.phar_sha256, $manifest.launcher_sha256, $manifest.runtime_sha256)) {
        if ($digest -notmatch "^[0-9a-f]{64}$") {
            throw "Bedriox installer: the release manifest contains an invalid checksum."
        }
    }

    $payload = Join-Path $temporaryDirectory "payload"
    $runtimeArchive = Join-Path $temporaryDirectory "runtime.zip"
    New-Item -ItemType Directory -Path $payload | Out-Null
    Invoke-WebRequest -Uri $manifest.phar_url -OutFile (Join-Path $payload "Bedriox.phar")
    Invoke-WebRequest -Uri $manifest.launcher_url -OutFile (Join-Path $payload "bedriox.cmd")
    Invoke-WebRequest -Uri $manifest.runtime_url -OutFile $runtimeArchive

    $pharHash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $payload "Bedriox.phar")).Hash.ToLowerInvariant()
    $launcherHash = (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $payload "bedriox.cmd")).Hash.ToLowerInvariant()
    $runtimeHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $runtimeArchive).Hash.ToLowerInvariant()
    if ($pharHash -ne $manifest.phar_sha256) {
        throw "Bedriox installer: Bedriox.phar checksum verification failed."
    }
    if ($launcherHash -ne $manifest.launcher_sha256) {
        throw "Bedriox installer: launcher checksum verification failed."
    }
    if ($runtimeHash -ne $manifest.runtime_sha256) {
        throw "Bedriox installer: Runtime checksum verification failed."
    }

    Expand-Archive -LiteralPath $runtimeArchive -DestinationPath $payload
    & (Join-Path $payload "bedriox.cmd") --version | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Bedriox installer: the downloaded server failed its startup check."
    }

    Move-Item -LiteralPath $payload -Destination $Directory
    Write-Host "Bedriox installer: installed Bedriox $($manifest.bedriox_version) in $Directory."
} finally {
    if (Test-Path -LiteralPath $temporaryDirectory) {
        Remove-Item -LiteralPath $temporaryDirectory -Recurse -Force
    }
}

if ($NoStart) {
    Write-Host "Start it with: cd '$Directory'; .\bedriox.cmd serve"
    return
}

Set-Location -LiteralPath $Directory
Write-Host "Bedriox installer: starting the server..."
& .\bedriox.cmd serve
exit $LASTEXITCODE
