# Portable code-quality launcher; requires Docker and Python 3.10+.
$ErrorActionPreference = 'Stop'
$script = Join-Path $PSScriptRoot 'scripts/ulaxys-tools.py'
$runner = $null
$prefix = @()
if ($IsWindows -or $PSVersionTable.PSEdition -eq 'Desktop') {
    if (Get-Command py -ErrorAction SilentlyContinue) {
        $runner = 'py'
        $prefix = @('-3')
    }
}
if (-not $runner) {
    foreach ($name in @('python3', 'python')) {
        if (Get-Command $name -ErrorAction SilentlyContinue) {
            $runner = $name
            break
        }
    }
}
if (-not $runner) {
    [Console]::Error.WriteLine('Python 3.10+ is required.')
    exit 2
}
& $runner @prefix $script @args
exit $LASTEXITCODE
