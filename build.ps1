#
# Build a distribution-ready ZIP for Cube Payment Portal.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File build.ps1
#
# Reads .distignore to determine which files/directories to exclude.
# Outputs: cube-payment-portal.zip in the project root.

$ErrorActionPreference = "Stop"

$PluginSlug = "cube-payment-portal"
$BuildDir = "build"
$DistDir = Join-Path $BuildDir $PluginSlug
$ZipFile = "$PluginSlug.zip"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

Set-Location $ScriptDir

Write-Host "Building $PluginSlug distribution package..." -ForegroundColor Cyan

# Clean previous build.
if (Test-Path $BuildDir) { Remove-Item $BuildDir -Recurse -Force }
if (Test-Path $ZipFile) { Remove-Item $ZipFile -Force }

# Parse .distignore into exclude patterns.
$ExcludeDirs = @()
$ExcludeFiles = @()
$ExcludeGlobs = @()

if (Test-Path ".distignore") {
    foreach ($line in Get-Content ".distignore") {
        $line = $line.Trim()
        if ([string]::IsNullOrEmpty($line)) { continue }
        if ($line.StartsWith("#")) { continue }
        if ($line.StartsWith("!")) { continue }

        if ($line.EndsWith("/")) {
            # Directory pattern.
            $ExcludeDirs += $line.TrimEnd("/")
        } elseif ($line.Contains("*")) {
            # Glob/wildcard pattern.
            $ExcludeGlobs += $line
        } else {
            # Exact file name.
            $ExcludeFiles += $line
        }
    }
}

# Always exclude build artifacts.
$ExcludeDirs += "build"
$ExcludeFiles += $ZipFile
$ExcludeFiles += "build.sh"
$ExcludeFiles += "build.ps1"

# Create build directory.
New-Item -ItemType Directory -Path $DistDir -Force | Out-Null

# Get all files, applying exclusions.
$allFiles = Get-ChildItem -Path . -Recurse -File

$filesToCopy = @()
foreach ($file in $allFiles) {
    $relativePath = $file.FullName.Substring((Get-Location).Path.Length + 1)
    $relativePath = $relativePath -replace '\\', '/'
    $skip = $false

    # Check directory exclusions.
    foreach ($dir in $ExcludeDirs) {
        if ($relativePath -like "$dir/*" -or $relativePath -eq $dir) {
            $skip = $true
            break
        }
    }
    if ($skip) { continue }

    # Check exact file exclusions.
    $fileName = $file.Name
    foreach ($ef in $ExcludeFiles) {
        if ($fileName -eq $ef -or $relativePath -eq $ef) {
            $skip = $true
            break
        }
    }
    if ($skip) { continue }

    # Check glob pattern exclusions.
    foreach ($glob in $ExcludeGlobs) {
        if ($fileName -like $glob -or $relativePath -like $glob) {
            $skip = $true
            break
        }
    }
    if ($skip) { continue }

    $filesToCopy += @{ Source = $file.FullName; Relative = $relativePath }
}

# Copy files to build directory.
foreach ($entry in $filesToCopy) {
    $destPath = Join-Path $DistDir $entry.Relative
    $destDir = Split-Path -Parent $destPath
    if (-not (Test-Path $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item $entry.Source $destPath
}

# Create ZIP.
$zipFullPath = Join-Path (Get-Location).Path $ZipFile
Compress-Archive -Path (Join-Path $BuildDir "*") -DestinationPath $zipFullPath -Force

# Clean up build directory.
Remove-Item $BuildDir -Recurse -Force

# Show result.
$zipInfo = Get-Item $ZipFile
$sizeMB = [math]::Round($zipInfo.Length / 1MB, 2)
$fileCount = $filesToCopy.Count

Write-Host ""
Write-Host "Done! Created $ZipFile ($sizeMB MB, $fileCount files)" -ForegroundColor Green
Write-Host ""
Write-Host "Sample contents:" -ForegroundColor Yellow
$filesToCopy | Select-Object -First 20 | ForEach-Object { Write-Host "  $($_.Relative)" }
if ($fileCount -gt 20) {
    Write-Host "  ... ($fileCount files total)"
}
