# Funktionstest des Windows-Agenten ohne Windows: laedt die Agent-Funktionen in PowerShell 7 (z. B. Linux/Docker),
# bildet CIM, Dienste, Ereignisanzeige, Netzwerk und Windows Update nach und fuehrt Get-Metrics (+ optional Senden) aus.
# Prueft ausserdem, dass keine eigene Funktion wie ein PowerShell-Alias heisst (z. B. 'r' = Invoke-History).
#   docker run --rm -v "$PWD":/w mcr.microsoft.com/powershell:lts-debian-12 pwsh -NoProfile -File /w/tests/windows-agent-harness.ps1
#   optional: -e SW_PUSH_URL='https://…/push.php?token=…'  (Token eines Test-Agent-Sensors)
$ErrorActionPreference = 'Stop'
$src = Get-Content (Join-Path $PSScriptRoot '../agent/SignalwarteAgent.ps1') -Raw
# 1) Statische Pruefung: werden Befehle aufgerufen, die in PowerShell Aliase sind? (Aliase gehen vor Funktionen)
$tok = $null; $err = $null
$ast = [System.Management.Automation.Language.Parser]::ParseInput($src, [ref]$tok, [ref]$err)
$defined = $ast.FindAll({ $args[0] -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true) | ForEach-Object { $_.Name }
$called = $ast.FindAll({ $args[0] -is [System.Management.Automation.Language.CommandAst] }, $true) | ForEach-Object { $_.GetCommandName() } | Where-Object { $_ } | Sort-Object -Unique
$clash = $called | Where-Object { $defined -contains $_ -and (Get-Alias -Name $_ -ErrorAction SilentlyContinue) }
"Alias-Konflikte bei eigenen Funktionen: " + ($(if ($clash) { $clash -join ', ' } else { 'keine' }))

# 2) Skript ohne Einstieg laden (Funktionen + Variablen)
$env:ProgramData = "/tmp/pd"; New-Item -ItemType Directory -Force /tmp/pd | Out-Null
$body = $src.Substring(0, $src.IndexOf('# ============================================================================ Einstieg'))
. ([scriptblock]::Create($body))
$InstallDir = '/tmp/swa'; New-Item -ItemType Directory -Force $InstallDir | Out-Null

# 3) Windows-Schnittstellen nachbilden
function Get-CimInstance { param([string]$ClassName, [string]$Filter)
    $cls = if ($ClassName) { $ClassName } else { $args[0] }
    switch ($cls) {
        'Win32_OperatingSystem' { [pscustomobject]@{ Caption = 'Microsoft Windows Server 2022 Standard'; Version = '10.0.20348'; BuildNumber = '20348'; OSArchitecture = '64-Bit'
            TotalVisibleMemorySize = 16777216; FreePhysicalMemory = 6291456; LastBootUpTime = (Get-Date).AddDays(-12.5); InstallDate = (Get-Date '2024-03-01') } }
        'Win32_Processor' { [pscustomobject]@{ LoadPercentage = 23; Name = ' Intel(R) Xeon(R) Silver 4210 ' }, [pscustomobject]@{ LoadPercentage = 31; Name = 'Intel(R) Xeon(R) Silver 4210' } }
        'Win32_LogicalDisk' { [pscustomobject]@{ DeviceID = 'C:'; Size = 107374182400; FreeSpace = 9663676416; VolumeName = 'System' }, [pscustomobject]@{ DeviceID = 'D:'; Size = 536870912000; FreeSpace = 322122547200; VolumeName = 'Daten' } }
        'Win32_Service' { [pscustomobject]@{ Name = 'gupdate'; State = 'Stopped' }, [pscustomobject]@{ Name = 'MSSQLSERVER'; State = 'Stopped' } }
        'Win32_PerfFormattedData_Tcpip_NetworkInterface' { [pscustomobject]@{ BytesReceivedPersec = 1250000; BytesSentPersec = 250000 } }
        'Win32_ComputerSystem' { [pscustomobject]@{ Manufacturer = 'Dell Inc.'; Model = 'PowerEdge R450'; Domain = 'firma.local'; NumberOfLogicalProcessors = 20; TotalPhysicalMemory = 17179869184 } }
        'Win32_BIOS' { [pscustomobject]@{ SerialNumber = 'ABC1234'; SMBIOSBIOSVersion = '1.9.2' } }
        default { @() }
    }
}
function Get-Service { param($Name) if ($Name -eq 'W32Time') { [pscustomobject]@{ Status = 'Stopped' } } else { [pscustomobject]@{ Status = 'Running' } } }
function Get-WinEvent { param($FilterHashtable, $ErrorAction) 1..4 }
function Get-HotFix { [pscustomobject]@{ HotFixID = 'KB5044281'; InstalledOn = (Get-Date).AddDays(-9) } }
function Get-MpComputerStatus { [pscustomobject]@{ RealTimeProtectionEnabled = $true; AntivirusSignatureAge = 1 } }
function Get-NetIPConfiguration { param($ErrorAction) [pscustomobject]@{ InterfaceAlias = 'Ethernet0'; IPv4Address = @([pscustomobject]@{ IPAddress = '10.99.0.5'; PrefixLength = 24 })
    IPv4DefaultGateway = [pscustomobject]@{ NextHop = '10.99.0.1' }; DNSServer = @([pscustomobject]@{ AddressFamily = 2; ServerAddresses = @('10.99.0.10', '10.99.0.11') }) } }
function Get-NetAdapter { param([switch]$Physical, $ErrorAction) [pscustomobject]@{ Name = 'Ethernet0'; MacAddress = '00-50-56-AA-BB-CC'; Status = 'Up' } }

. (Join-Path $PSScriptRoot 'windows-agent-wua-mock.ps1')

# 4) Messung + Senden wie im Dienst
$state = @{}
$data = Get-Metrics -WatchServices @('Spooler', 'W32Time') -State $state
"--- Kanaele:"; $data.channels.GetEnumerator() | ForEach-Object { '{0,-26} {1}' -f $_.Key, $_.Value }
"--- Info: " + (($data.info.Keys | ForEach-Object { $_ }) -join ', ')
"--- gestoppte Auto-Dienste: " + ($data.stopped -join ', ')
if ($env:SW_PUSH_URL) {
    $resp = Send-Metrics ([pscustomobject]@{ url = $env:SW_PUSH_URL }) $data
    "--- Antwort Signalwarte: status=$($resp.status) services=$($resp.services -join ',') update=$($resp.update.version)"
} else { '--- (kein Senden: SW_PUSH_URL nicht gesetzt)' }
