# Simple fix for NaN issue in radius.html
$file = "e:\acs-radius\web\templates\radius.html"

# Read file
$lines = Get-Content $file

# Process line by line
$output = @()
$i = 0
while ($i -lt $lines.Count) {
    $line = $lines[$i]
    
    # Fix formatBytes function
    if ($line -match '^\s+if \(\!bytes \|\| bytes === 0\) return') {
        $output += $line -replace 'if \(\!bytes \|\| bytes === 0\)', 'const numBytes = parseInt(bytes) || 0;            if (numBytes === 0)'
        $i++
        continue
    }
    
    # Fix Math.log(bytes)
    if ($line -match 'Math\.log\(bytes\)') {
        $output += $line -replace 'bytes', 'numBytes'
        $i++
        continue
    }
    
    # Fix formatDuration function
    if ($line -match '^\s+if \(\!seconds \|\| seconds === 0\) return') {
        $output += $line -replace 'if \(\!seconds \|\| seconds === 0\)', 'const numSeconds = parseInt(seconds) || 0;            if (numSeconds === 0)'
        $i++
        continue
    }
    
    # Fix Math.floor(seconds
    if ($line -match 'Math\.floor\(seconds / 3600\)') {
        $output += $line -replace 'seconds', 'numSeconds'
        $i++
        continue
    }
    
    if ($line -match '\(seconds % 3600\)') {
        $output += $line -replace 'seconds', 'numSeconds'
        $i++
        continue
    }
    
    # Fix loadActiveSessions data parsing
    if ($line -match 'const download = formatBytes\(s\.acctinputoctets') {
        $indent = $line -replace '^(\s+).*', '$1'
        $output += "${indent}const inputOctets = parseInt(s.acctinputoctets) || 0;"
        $output += "${indent}const outputOctets = parseInt(s.acctoutputoctets) || 0;"
        $output += "${indent}const sessionTime = parseInt(s.acctsessiontime) || 0;"
        $output += "${indent}"
        $output += "${indent}const download = formatBytes(inputOctets);"
        
        # Skip next 2 lines (original upload and duration)
        $i += 3
        $output += "${indent}const upload = formatBytes(outputOctets);"
        $output += "${indent}const duration = formatDuration(sessionTime);"
        continue
    }
    
    $output += $line
    $i++
}

# Write back
$output | Set-Content $file -Encoding UTF8

Write-Host "✅ File edited successfully!" -ForegroundColor Green
Write-Host "Please hard refresh browser (Ctrl+Shift+R)" -ForegroundColor Yellow
