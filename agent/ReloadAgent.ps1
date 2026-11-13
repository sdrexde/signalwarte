<#
.SYNOPSIS
    Reload-Monitor Windows-Agent: meldet Systemzustand (CPU, RAM, Laufwerke, Dienste, Updates,
    Neustart, Ereignisanzeige, Defender, Netzwerk) an Reload Monitor.

.DESCRIPTION
    Reines PowerShell-Skript (Windows PowerShell 5.1 oder neuer), keine Zusatzsoftware noetig.
    Laeuft als geplante Aufgabe unter dem Konto SYSTEM und startet automatisch mit Windows.

    Ohne Parameter          Installiert den Agenten (fordert Administratorrechte an)
    -Install                Installiert nach C:\ProgramData\ReloadAgent und richtet die Aufgabe ein
    -Uninstall              Entfernt Aufgabe und Programmverzeichnis
    -Status                 Zeigt Zustand der Aufgabe und die letzten Logzeilen
    -Test                   Sammelt einmal alle Werte, zeigt sie an und sendet sie
    -Run                    Agent-Schleife (wird von der geplanten Aufgabe gestartet)

    Konfiguration: C:\ProgramData\ReloadAgent\config.json
    Die zu ueberwachenden Dienste und das Intervall kommen vom Server (Sensor bearbeiten).

.NOTES
    Wird von Reload Monitor beim Download mit Server-Adresse und Token versehen.
    Datei bewusst nur mit ASCII-Zeichen, damit Windows PowerShell 5.1 sie ohne BOM korrekt liest.
#>
[CmdletBinding()]
param(
    [switch]$Install,
    [switch]$Uninstall,
    [switch]$Run,
    [switch]$Test,
    [switch]$Status
)

$ErrorActionPreference = 'Stop'
$AgentVersion = '1.0.0'
$TaskName = 'ReloadAgent'
$InstallDir = Join-Path $env:ProgramData 'ReloadAgent'
$InstalledScript = Join-Path $InstallDir 'ReloadAgent.ps1'
$ConfigPath = Join-Path $InstallDir 'config.json'
$StatePath = Join-Path $InstallDir 'state.json'
$LogPath = Join-Path $InstallDir 'agent.log'
$Invariant = [Globalization.CultureInfo]::InvariantCulture
$script:Interactive = $false

# Beim Download von Reload Monitor eingesetzt (Push-URL inkl. Token)
$EmbeddedConfig = @'
__RELOAD_CONFIG__
'@

# Automatische Dienste, die regelmaessig ohne Fehler gestoppt sind (Trigger-Start / verzoegert)
$IgnoreServices = @('gupdate', 'gupdatem', 'edgeupdate', 'edgeupdatem', 'sppsvc', 'RemoteRegistry', 'MapsBroker',
    'WbioSrvc', 'tiledatamodelsvc', 'CDPSvc', 'BITS', 'wuauserv', 'TrustedInstaller', 'clr_optimization_v4.0.30319_32',
    'clr_optimization_v4.0.30319_64', 'ShellHWDetection', 'sedsvc', 'UsoSvc', 'WaaSMedicSvc', 'GoogleUpdaterService*',
    'GoogleUpdaterInternalService*', 'MicrosoftEdgeElevationService', 'DoSvc', 'dmwappushservice', 'IntelAudioService',
    'Intel(R) TPM Provisioning Service', 'WinDefend', 'TabletInputService', 'OneSyncSvc*', 'CDPUserSvc*', 'WpnUserService*')

# ============================================================================ Hilfsfunktionen
function Write-Log {
    param([string]$Message, [string]$Level = 'INFO')
    $line = '{0} {1} {2}' -f (Get-Date).ToString('yyyy-MM-dd HH:mm:ss', $Invariant), $Level, $Message
    if ($script:Interactive) {
        $color = switch ($Level) { 'ERROR' { 'Red' } 'WARN' { 'Yellow' } default { 'Gray' } }
        Write-Host $line -ForegroundColor $color
    }
    try {
        if (-not (Test-Path $InstallDir)) { return }
        if ((Test-Path $LogPath) -and (Get-Item $LogPath).Length -gt 2MB) { Move-Item $LogPath "$LogPath.1" -Force }
        Add-Content -Path $LogPath -Value $line -Encoding UTF8
    } catch { }
}

function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-EmbeddedConfig {
    $t = $EmbeddedConfig.Trim()
    if (-not $t -or $t -like '__RELOAD*') { return $null }
    return $t | ConvertFrom-Json
}

function Get-AgentConfig {
    if (Test-Path $ConfigPath) { return Get-Content $ConfigPath -Raw | ConvertFrom-Json }
    $e = Get-EmbeddedConfig
    if ($e) { return $e }
    throw 'Keine Konfiguration gefunden. Skript bitte ueber Reload Monitor (Sensor-Details) herunterladen.'
}

function Read-State {
    if (Test-Path $StatePath) {
        try {
            $o = Get-Content $StatePath -Raw | ConvertFrom-Json
            $h = @{}
            foreach ($p in $o.PSObject.Properties) { $h[$p.Name] = $p.Value }
            return $h
        } catch { }
    }
    return @{}
}

function Save-State($h) {
    try { $h | ConvertTo-Json -Depth 5 | Set-Content -Path $StatePath -Encoding UTF8 } catch { }
}

function Initialize-Tls {
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    } catch { }
}

function R($v, [int]$d = 1) { return [math]::Round([double]$v, $d) }

# ============================================================================ Messwerte sammeln
function Get-Metrics {
    param([string[]]$WatchServices, $State)
    $ch = [ordered]@{}
    $info = [ordered]@{}
    $stopped = @()

    $os = Get-CimInstance Win32_OperatingSystem
    $info.os = "$($os.Caption) $($os.Version)".Trim()
    $info.host = $env:COMPUTERNAME
    $info.agent = $AgentVersion

    try { $ch['CPU %'] = R ((Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average) } catch { }
    $total = [double]$os.TotalVisibleMemorySize
    if ($total -gt 0) {
        $ch['RAM %'] = R ((($total - [double]$os.FreePhysicalMemory) / $total) * 100)
        $ch['RAM frei GB'] = R ([double]$os.FreePhysicalMemory / 1MB) 2
        $ch['RAM gesamt GB'] = R ($total / 1MB) 1
    }
    $ch['Laufzeit (Tage)'] = R (((Get-Date) - $os.LastBootUpTime).TotalDays) 2
    try { $ch['Prozesse'] = (Get-Process).Count } catch { }

    foreach ($d in (Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3')) {
        if ($d.Size -gt 0) {
            $ch["Frei $($d.DeviceID) %"] = R (($d.FreeSpace / $d.Size) * 100)
            $ch["Frei $($d.DeviceID) GB"] = R ($d.FreeSpace / 1GB)
        }
    }

    # Automatische Dienste, die nicht laufen
    try {
        $svc = Get-CimInstance Win32_Service -Filter "StartMode='Auto' AND State<>'Running'"
        foreach ($s in $svc) {
            $skip = $false
            foreach ($p in $IgnoreServices) { if ($s.Name -like $p) { $skip = $true; break } }
            if (-not $skip) { $stopped += $s.Name }
        }
        $ch['Auto-Dienste gestoppt'] = $stopped.Count
    } catch { }

    # Ueberwachte Dienste (Liste kommt vom Server)
    $services = [ordered]@{}
    foreach ($n in $WatchServices) {
        if (-not $n) { continue }
        try { $services[$n] = [string](Get-Service -Name $n -ErrorAction Stop).Status } catch { $services[$n] = 'NichtGefunden' }
    }

    # Neustart ausstehend
    $reboot = 0
    if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending') { $reboot = 1 }
    if (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired') { $reboot = 1 }
    $ch['Neustart ausstehend'] = $reboot

    # Fehler in der Ereignisanzeige (letzte Stunde)
    try {
        $since = (Get-Date).AddHours(-1)
        $n = @(Get-WinEvent -FilterHashtable @{ LogName = 'System', 'Application'; Level = 1, 2; StartTime = $since } -ErrorAction SilentlyContinue).Count
        $ch['Ereignis-Fehler 1h'] = $n
    } catch { $ch['Ereignis-Fehler 1h'] = 0 }

    # Letztes Update (Get-HotFix ist langsam -> hoechstens stuendlich)
    $now = Get-Date
    if (-not $State['hotfix_at'] -or ($now - [datetime]$State['hotfix_at']).TotalMinutes -gt 60) {
        try {
            $last = Get-HotFix | Where-Object { $_.InstalledOn } | Sort-Object InstalledOn -Descending | Select-Object -First 1
            if ($last) { $State['hotfix_days'] = R (($now - $last.InstalledOn).TotalDays) 0; $State['hotfix_id'] = $last.HotFixID }
        } catch { }
        $State['hotfix_at'] = $now.ToString('o')
    }
    if ($null -ne $State['hotfix_days']) { $ch['Tage seit letztem Update'] = $State['hotfix_days']; $info.lastupdate = $State['hotfix_id'] }

    # Defender
    try {
        if (Get-Command Get-MpComputerStatus -ErrorAction SilentlyContinue) {
            $mp = Get-MpComputerStatus
            $ch['Defender aktiv'] = [int][bool]$mp.RealTimeProtectionEnabled
            $ch['Defender Signatur (Tage)'] = [int]$mp.AntivirusSignatureAge
        }
    } catch { }

    # Netzwerk (aktuelle Rate ueber alle Adapter)
    try {
        $net = Get-CimInstance Win32_PerfFormattedData_Tcpip_NetworkInterface
        $ch['Netz Eingang Mbit/s'] = R ((($net | Measure-Object BytesReceivedPersec -Sum).Sum) * 8 / 1e6) 3
        $ch['Netz Ausgang Mbit/s'] = R ((($net | Measure-Object BytesSentPersec -Sum).Sum) * 8 / 1e6) 3
    } catch { }

    try {
        $ip = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1).IPAddress
        if ($ip) { $info.ip = $ip }
    } catch { }

    return @{ channels = $ch; services = $services; stopped = $stopped; info = $info }
}

function Send-Metrics($config, $data) {
    $body = $data | ConvertTo-Json -Depth 5 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($body)
    $resp = Invoke-RestMethod -Uri $config.url -Method Post -Body $bytes -ContentType 'application/json; charset=utf-8' -TimeoutSec 30 -UseBasicParsing
    return $resp
}

# ============================================================================ Agent-Schleife
function Start-AgentLoop {
    $config = Get-AgentConfig
    Initialize-Tls
    Write-Log "Agent $AgentVersion gestartet, Server $($config.url -replace 'token=.*', 'token=***')"
    $state = Read-State
    while ($true) {
        $watch = @()
        if ($state['services']) { $watch = @($state['services']) }
        $interval = 120
        if ($state['interval']) { $interval = [int]$state['interval'] }
        try {
            $data = Get-Metrics -WatchServices $watch -State $state
            $resp = Send-Metrics $config $data
            if ($resp.services) { $state['services'] = @($resp.services) } else { $state['services'] = @() }
            if ($resp.interval) { $state['interval'] = [int]$resp.interval }
            $state['last_ok'] = (Get-Date).ToString('o')
            $state['last_status'] = [string]$resp.status
            Save-State $state
            # Neue Dienstliste sofort einmal nachliefern
            $newWatch = @($state['services'])
            if (($newWatch -join ',') -ne ($watch -join ',') -and $newWatch.Count) { Start-Sleep -Seconds 3; continue }
        } catch {
            Write-Log "Senden fehlgeschlagen: $($_.Exception.Message)" 'WARN'
        }
        Start-Sleep -Seconds ([math]::Max(30, $interval))
    }
}

# ============================================================================ Installation
function Stop-AgentProcesses {
    try { Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue } catch { }
    try {
        Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
            Where-Object { $_.CommandLine -like '*ReloadAgent.ps1*-Run*' } |
            ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    } catch { }
}

function Install-Agent {
    $cfg = Get-EmbeddedConfig
    if (-not $cfg -and -not (Test-Path $ConfigPath)) {
        throw 'Dieses Skript enthaelt keine Konfiguration. Bitte ueber Reload Monitor herunterladen (Sensor "Windows-/Linux-Agent" -> Details).'
    }
    Write-Host "ReloadAgent $AgentVersion wird installiert ..." -ForegroundColor Cyan
    Stop-AgentProcesses
    if (-not (Test-Path $InstallDir)) { New-Item -ItemType Directory -Path $InstallDir | Out-Null }
    # Nur SYSTEM und Administratoren duerfen das Verzeichnis lesen (enthaelt das Token)
    $null = & icacls.exe $InstallDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' 2>&1

    $self = $PSCommandPath
    if ($self -and ((Resolve-Path $self).Path -ne $InstalledScript)) { Copy-Item -Path $self -Destination $InstalledScript -Force }
    try { Unblock-File -Path $InstalledScript } catch { }
    if ($cfg) { $cfg | ConvertTo-Json -Depth 5 | Set-Content -Path $ConfigPath -Encoding UTF8 }
    if (Test-Path $StatePath) { Remove-Item $StatePath -Force }
    $config = Get-AgentConfig
    Write-Log "Installation Version $AgentVersion"

    Initialize-Tls
    try {
        $state = @{}
        $resp = Send-Metrics $config (Get-Metrics -WatchServices @() -State $state)
        if ($resp.services) { $state['services'] = @($resp.services) }
        if ($resp.interval) { $state['interval'] = [int]$resp.interval }
        Save-State $state
        Write-Host "Verbindung zu Reload Monitor erfolgreich (Status: $($resp.status))." -ForegroundColor Green
    } catch {
        Write-Host "WARNUNG: Reload Monitor nicht erreichbar ($($_.Exception.Message)). Der Agent versucht es weiter." -ForegroundColor Yellow
    }

    $arg = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $InstalledScript + '" -Run'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arg -WorkingDirectory $InstallDir
    $boot = New-ScheduledTaskTrigger -AtStartup
    # Waechter: alle 5 Minuten starten, falls der Agent nicht laeuft (doppelte Instanzen werden ignoriert)
    $watch = New-ScheduledTaskTrigger -Daily -At '00:00'
    $watch.Repetition = (New-ScheduledTaskTrigger -Once -At '00:00' -RepetitionInterval (New-TimeSpan -Minutes 5) `
        -RepetitionDuration (New-TimeSpan -Days 1)).Repetition
    $principal = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
        -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
        -MultipleInstances IgnoreNew
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($boot, $watch) -Principal $principal `
        -Settings $settings -Description 'Reload Monitor Agent: meldet Systemzustand an Reload Monitor' -Force | Out-Null
    Start-ScheduledTask -TaskName $TaskName
    Write-Host "Installiert. Aufgabe '$TaskName' laeuft als SYSTEM." -ForegroundColor Green
    Write-Host "Verzeichnis: $InstallDir   (config.json, agent.log)"
}

function Uninstall-Agent {
    Write-Host 'ReloadAgent wird entfernt ...' -ForegroundColor Cyan
    Stop-AgentProcesses
    try { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue } catch { }
    Start-Sleep -Seconds 1
    if (Test-Path $InstallDir) { Remove-Item $InstallDir -Recurse -Force -ErrorAction SilentlyContinue }
    Write-Host 'Entfernt.' -ForegroundColor Green
}

function Show-Status {
    $t = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $t) { Write-Host 'Nicht installiert.' -ForegroundColor Yellow; return }
    $i = Get-ScheduledTaskInfo -TaskName $TaskName
    Write-Host "Aufgabe: $($t.State)   letzter Start: $($i.LastRunTime)   Ergebnis: $($i.LastTaskResult)"
    $st = Read-State
    if ($st['last_ok']) { Write-Host "Letzte erfolgreiche Meldung: $($st['last_ok'])  Status: $($st['last_status'])" }
    if ($st['services']) { Write-Host "Ueberwachte Dienste: $(@($st['services']) -join ', ')" }
    if (Test-Path $LogPath) { Write-Host '--- agent.log ---'; Get-Content $LogPath -Tail 15 }
}

function Invoke-Test {
    $config = Get-AgentConfig
    Initialize-Tls
    $state = Read-State
    $watch = @(); if ($state['services']) { $watch = @($state['services']) }
    $data = Get-Metrics -WatchServices $watch -State $state
    $data.channels.GetEnumerator() | ForEach-Object { '{0,-28} {1}' -f $_.Key, $_.Value } | Write-Host
    if ($data.stopped.Count) { Write-Host "Gestoppte Auto-Dienste: $($data.stopped -join ', ')" -ForegroundColor Yellow }
    $resp = Send-Metrics $config $data
    Write-Host "Gesendet. Server-Status: $($resp.status)" -ForegroundColor Green
}

# ============================================================================ Einstieg
$script:Interactive = -not $Run
try {
    if ($Run) { Start-AgentLoop; return }
    if ($Status) { Show-Status; return }
    if ($Test) { Invoke-Test; return }
    if ($Uninstall -or $Install -or (-not $PSBoundParameters.Count)) {
        if (-not (Test-Admin)) {
            $mode = if ($Uninstall) { '-Uninstall' } else { '-Install' }
            Write-Host 'Administratorrechte werden angefordert ...' -ForegroundColor Cyan
            Start-Process powershell.exe -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -NoExit -File `"$PSCommandPath`" $mode"
            return
        }
        if ($Uninstall) { Uninstall-Agent } else { Install-Agent }
    }
} catch {
    Write-Host "FEHLER: $($_.Exception.Message)" -ForegroundColor Red
    Write-Log $_.Exception.Message 'ERROR'
    if (-not $Run) { exit 1 }
}
