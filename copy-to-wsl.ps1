# Copy files from Windows to WSL Ubuntu
# Usage: .\copy-to-wsl.ps1

$source = "E:\acs"
$wslDest = "/opt/acs"
$distro = "Ubuntu"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Copy Files to WSL - ACS Project" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Source      : $source" -ForegroundColor Yellow
Write-Host "Destination : $distro::$wslDest" -ForegroundColor Yellow
Write-Host ""

# Create destination directory if not exists
Write-Host "[1/3] Creating destination directory..." -ForegroundColor Green
wsl -d $distro -u root -- mkdir -p $wslDest

# Copy files using wsl command with rsync for better performance
Write-Host "[2/3] Copying files..." -ForegroundColor Green

# Convert Windows path to WSL path
$wslSource = "/mnt/" + $source.Replace("\", "/").Replace(":", "").ToLower()

# Use rsync for efficient copying (preserves permissions, skips unchanged files)
wsl -d $distro -u root -- bash -c "rsync -av --delete '$wslSource/' '$wslDest/' --exclude '.git' --exclude 'node_modules' --exclude '*.ps1' --exclude 'copy-to-wsl.ps1'"

if ($LASTEXITCODE -eq 0) {
    Write-Host ""
    Write-Host "[3/3] Setting permissions..." -ForegroundColor Green
    wsl -d $distro -u root -- chmod -R 755 $wslDest
    wsl -d $distro -u root -- chown -R www-data:www-data $wslDest
    
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Green
    Write-Host "  SUCCESS! Files copied to WSL" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Files are now available at: $wslDest" -ForegroundColor Cyan
} else {
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Red
    Write-Host "  ERROR! Copy failed" -ForegroundColor Red
    Write-Host "========================================" -ForegroundColor Red
    Write-Host ""
    Write-Host "Trying alternative method with cp..." -ForegroundColor Yellow
    wsl -d $distro -u root -- bash -c "cp -rf '$wslSource/'* '$wslDest/'"
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Alternative copy succeeded!" -ForegroundColor Green
        wsl -d $distro -u root -- chmod -R 755 $wslDest
        wsl -d $distro -u root -- chown -R www-data:www-data $wslDest
    }
}

Write-Host ""
Write-Host "Press any key to exit..."
$null = $Host.UI.RawUI.ReadKey("NoEcho,IncludeKeyDown")
