# Fix NaN issue in radius.html - Download/Upload columns

$file = "e:\acs-radius\web\templates\radius.html"
$content = Get-Content $file -Raw -Encoding UTF8

# Fix formatBytes function
$oldFormatBytes = @'
        function formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
        }
'@

$newFormatBytes = @'
        function formatBytes(bytes) {
            const numBytes = parseInt(bytes) || 0;
            if (numBytes === 0) return '0 B';
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(numBytes) / Math.log(1024));
            return (numBytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
        }
'@

$content = $content.Replace($oldFormatBytes, $newFormatBytes)

# Fix formatDuration function  
$oldFormatDuration = @'
        function formatDuration(seconds) {
            if (!seconds || seconds === 0) return '0s';
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            if (h > 0) return `${h}h ${m}m ${s}s`;
            if (m > 0) return `${m}m ${s}s`;
            return `${s}s`;
        }
'@

$newFormatDuration = @'
        function formatDuration(seconds) {
            const numSeconds = parseInt(seconds) || 0;
            if (numSeconds === 0) return '0s';
            const h = Math.floor(numSeconds / 3600);
            const m = Math.floor((numSeconds % 3600) / 60);
            const s = numSeconds % 60;
            if (h > 0) return `${h}h ${m}m ${s}s`;
            if (m > 0) return `${m}m ${s}s`;
            return `${s}s`;
        }
'@

$content = $content.Replace($oldFormatDuration, $newFormatDuration)

# Fix loadActiveSessions data parsing
$oldLoadActiveSessions = @'
                tbody.innerHTML = sessions.map(s => {
                    const download = formatBytes(s.acctinputoctets || 0);
                    const upload = formatBytes(s.acctoutputoctets || 0);
                    const duration = formatDuration(s.acctsessiontime || 0);
'@

$newLoadActiveSessions = @'
                tbody.innerHTML = sessions.map(s => {
                    const inputOctets = parseInt(s.acctinputoctets) || 0;
                    const outputOctets = parseInt(s.acctoutputoctets) || 0;
                    const sessionTime = parseInt(s.acctsessiontime) || 0;
                    
                    const download = formatBytes(inputOctets);
                    const upload = formatBytes(outputOctets);
                    const duration = formatDuration(sessionTime);
'@

$content = $content.Replace($oldLoadActiveSessions, $newLoadActiveSessions)

# Save the fixed file
Set-Content -Path $file -Value $content -Encoding UTF8

Write-Host "✅ Fixed NaN issue in radius.html" -ForegroundColor Green
Write-Host "File: $file" -ForegroundColor Cyan
Write-Host ""
Write-Host "Changes made:" -ForegroundColor Yellow
Write-Host "1. formatBytes() now uses parseInt() to convert values" 
Write-Host "2. formatDuration() now uses parseInt() to convert values"
Write-Host "3. loadActiveSessions() now parses numeric values before formatting"
Write-Host ""
Write-Host "Please refresh your browser to see the changes!" -ForegroundColor Green
