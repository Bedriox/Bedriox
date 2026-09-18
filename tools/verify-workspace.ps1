[CmdletBinding()]
param(
    [switch] $SkipClean
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$failures = [System.Collections.Generic.List[string]]::new()
$codeRepositories = @('Bedriox', 'RakNet', 'Protocol', 'Data', 'ExamplePlugin', 'PluginTools')
$runtimeRepositories = @('Runtime')
$documentationRepositories = @('Docs', 'RFCs')
$repositoryNames = $codeRepositories + $runtimeRepositories + $documentationRepositories
$requiredDocuments = @(
    'AGENTS.md',
    'README.md',
    'LICENSE',
    'NOTICE',
    'CONTRIBUTING.md',
    'GOVERNANCE.md',
    'SECURITY.md',
    'CHANGELOG.md',
    'CODE_OF_CONDUCT.md'
)

function Add-VerificationFailure {
    param([Parameter(Mandatory)][string] $Message)

    $script:failures.Add($Message)
    Write-Host "FAIL: $Message" -ForegroundColor Red
}

function Invoke-VerificationCommand {
    param(
        [Parameter(Mandatory)][string] $Repository,
        [Parameter(Mandatory)][string] $Executable,
        [Parameter(Mandatory)][string[]] $Arguments
    )

    Write-Host "RUN: $Repository :: $Executable $($Arguments -join ' ')"
    Push-Location -LiteralPath $Repository
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        # Windows PowerShell surfaces native stderr as ErrorRecord objects. Keep
        # those diagnostics visible without treating ordinary tool output as a
        # terminating PowerShell exception; the native exit code is authoritative.
        $ErrorActionPreference = 'Continue'
        $commandOutput = @(& $Executable @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
        foreach ($outputLine in $commandOutput) {
            $message = if ($outputLine -is [System.Management.Automation.ErrorRecord]) {
                $outputLine.Exception.Message
            } else {
                [string] $outputLine
            }
            if (-not [string]::IsNullOrWhiteSpace($message)) {
                Write-Host $message
            }
        }
        if ($exitCode -ne 0) {
            Add-VerificationFailure "$Repository :: $Executable exited with code $exitCode."
            return $false
        }
    } catch {
        Add-VerificationFailure "$Repository :: could not run $Executable ($($_.Exception.Message))."
        return $false
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
        Pop-Location
    }

    return $true
}

$serverRoot = [System.IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
$workspaceParent = [System.IO.Path]::GetFullPath((Split-Path -Parent $serverRoot))
$resolvedRepositories = @{}

Write-Host "Workspace parent: $workspaceParent"

foreach ($repositoryName in $repositoryNames) {
    $candidate = Join-Path $workspaceParent $repositoryName
    if (-not (Test-Path -LiteralPath $candidate -PathType Container)) {
        Add-VerificationFailure "$repositoryName is missing at the expected path: $candidate"
        continue
    }

    $resolved = [System.IO.Path]::GetFullPath((Resolve-Path -LiteralPath $candidate).Path)
    $resolvedParent = [System.IO.Path]::GetFullPath((Split-Path -Parent $resolved))
    $resolvedName = Split-Path -Leaf $resolved
    if (-not $resolvedParent.Equals($workspaceParent, [System.StringComparison]::OrdinalIgnoreCase) -or
        -not $resolvedName.Equals($repositoryName, [System.StringComparison]::OrdinalIgnoreCase)) {
        Add-VerificationFailure "$repositoryName resolved outside the explicit workspace boundary: $resolved"
        continue
    }

    $resolvedRepositories[$repositoryName] = $resolved
    Write-Host "PATH: $repositoryName -> $resolved"
}

foreach ($repositoryName in $repositoryNames) {
    if (-not $resolvedRepositories.ContainsKey($repositoryName)) {
        continue
    }

    $repository = $resolvedRepositories[$repositoryName]
    foreach ($document in $requiredDocuments) {
        if (-not (Test-Path -LiteralPath (Join-Path $repository $document) -PathType Leaf)) {
            Add-VerificationFailure "$repositoryName is missing required file $document."
        }
    }

    $readmePath = Join-Path $repository 'README.md'
    $noticePath = Join-Path $repository 'NOTICE'
    if ((Test-Path -LiteralPath $readmePath) -and
        -not (Select-String -LiteralPath $readmePath -SimpleMatch 'Bedriox' -Quiet)) {
        Add-VerificationFailure "$repositoryName README.md is missing Bedriox branding."
    }
    if ((Test-Path -LiteralPath $noticePath) -and
        -not (Select-String -LiteralPath $noticePath -SimpleMatch 'Veno Ninja LLC' -Quiet)) {
        Add-VerificationFailure "$repositoryName NOTICE is missing Veno Ninja LLC branding."
    }
    if ((Test-Path -LiteralPath $readmePath) -and
        -not (Select-String -LiteralPath $readmePath -SimpleMatch 'https://bedriox.com' -Quiet)) {
        Add-VerificationFailure "$repositoryName README.md is missing the Bedriox website link."
    }

    $licensePath = Join-Path $repository 'LICENSE'
    if (Test-Path -LiteralPath $licensePath) {
        $hasGplName = Select-String -LiteralPath $licensePath -SimpleMatch 'GNU GENERAL PUBLIC LICENSE' -Quiet
        $hasGplVersion = Select-String -LiteralPath $licensePath -SimpleMatch 'Version 3' -Quiet
        if (-not ($hasGplName -and $hasGplVersion)) {
            Add-VerificationFailure "$repositoryName LICENSE does not identify GNU GPL version 3."
        }
    }

    $legacyPattern = '(?i)(\bBSL\b|Business Source License|revenue threshold|\$25(?:,?000|k)\b)'
    $textFiles = Get-ChildItem -LiteralPath $repository -Recurse -File | Where-Object {
        $_.FullName -notmatch '[\\/](\.git|vendor|build|\.phpunit\.cache|\.phpstan\.cache)[\\/]' -and
        ($_.Extension -in @('.md', '.txt', '.php', '.json', '.yml', '.yaml', '.neon', '.xml', '.lock') -or
            $_.Name -in @('LICENSE', 'NOTICE'))
    }
    foreach ($textFile in $textFiles) {
        if (Select-String -LiteralPath $textFile.FullName -Pattern $legacyPattern -Quiet) {
            Add-VerificationFailure "$repositoryName contains legacy restricted-license wording in $($textFile.FullName)."
        }
    }

    if (-not $SkipClean) {
        if (-not (Test-Path -LiteralPath (Join-Path $repository '.git') -PathType Container)) {
            Add-VerificationFailure "$repositoryName is not a Git working tree."
        } else {
            $gitStatus = & git -C $repository status --porcelain=v1 --untracked-files=normal 2>&1
            if ($LASTEXITCODE -ne 0) {
                Add-VerificationFailure "$repositoryName Git status could not be read."
            } elseif ($null -ne $gitStatus -and @($gitStatus).Count -gt 0) {
                Add-VerificationFailure "$repositoryName working tree is not clean:`n$($gitStatus -join [Environment]::NewLine)"
            }
        }
    }
}

foreach ($repositoryName in $codeRepositories) {
    if (-not $resolvedRepositories.ContainsKey($repositoryName)) {
        continue
    }

    $repository = $resolvedRepositories[$repositoryName]
    $composerPath = Join-Path $repository 'composer.json'
    $composerLockPath = Join-Path $repository 'composer.lock'
    if (-not (Test-Path -LiteralPath $composerPath -PathType Leaf) -or
        -not (Test-Path -LiteralPath $composerLockPath -PathType Leaf)) {
        Add-VerificationFailure "$repositoryName must contain composer.json and composer.lock."
        continue
    }

    try {
        $composerData = Get-Content -LiteralPath $composerPath -Raw | ConvertFrom-Json
        if ($composerData.license -ne 'GPL-3.0-only') {
            Add-VerificationFailure "$repositoryName composer.json license must be GPL-3.0-only."
        }
        if ($null -eq $composerData.scripts.check) {
            Add-VerificationFailure "$repositoryName composer.json is missing the check script."
        }
    } catch {
        Add-VerificationFailure "$repositoryName composer.json could not be parsed ($($_.Exception.Message))."
        continue
    }

    Invoke-VerificationCommand $repository 'composer' @('validate', '--strict', '--no-interaction') | Out-Null
    Invoke-VerificationCommand $repository 'composer' @('check') | Out-Null
    Invoke-VerificationCommand $repository 'composer' @('audit', '--locked', '--no-interaction') | Out-Null
}

foreach ($repositoryName in $documentationRepositories) {
    if (-not $resolvedRepositories.ContainsKey($repositoryName)) {
        continue
    }

    $repository = $resolvedRepositories[$repositoryName]
    $validator = Join-Path $repository 'tools\validate-docs.php'
    if (-not (Test-Path -LiteralPath $validator -PathType Leaf)) {
        Add-VerificationFailure "$repositoryName is missing tools/validate-docs.php."
        continue
    }

    Invoke-VerificationCommand $repository 'php' @('tools/validate-docs.php') | Out-Null
}

foreach ($repositoryName in $runtimeRepositories) {
    if (-not $resolvedRepositories.ContainsKey($repositoryName)) {
        continue
    }

    $repository = $resolvedRepositories[$repositoryName]
    $validator = Join-Path $repository 'tools\validate.php'
    if (-not (Test-Path -LiteralPath $validator -PathType Leaf)) {
        Add-VerificationFailure "$repositoryName is missing tools/validate.php."
        continue
    }

    Invoke-VerificationCommand $repository 'php' @('tools/validate.php') | Out-Null
}

if ($failures.Count -gt 0) {
    Write-Host "Workspace verification failed with $($failures.Count) problem(s)." -ForegroundColor Red
    foreach ($failure in $failures) {
        Write-Host " - $failure" -ForegroundColor Red
    }
    exit 1
}

$cleanMessage = if ($SkipClean) { ' Git cleanliness was intentionally skipped.' } else { '' }
Write-Host "Workspace verification passed for all nine repositories.$cleanMessage" -ForegroundColor Green
