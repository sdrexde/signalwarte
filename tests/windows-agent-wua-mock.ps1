# Windows Update Agent (COM) nachbilden
function New-Object { param([string]$TypeName, [string]$ComObject, [object[]]$ArgumentList)
    if ($ComObject -eq 'Microsoft.Update.Session') {
        $mk = { param($cat, $sev) [pscustomobject]@{ Categories = @([pscustomobject]@{ Name = $cat }); MsrcSeverity = $sev } }
        $updates = @((& $mk 'Security Updates' 'Critical'), (& $mk 'Security Updates' 'Important'), (& $mk 'Update Rollups' $null), (& $mk 'Critical Updates' $null))
        $searcher = [pscustomobject]@{ Online = $true }
        $searcher | Add-Member -MemberType ScriptMethod -Name Search -Value { param($q) [pscustomobject]@{ Updates = $updates } }.GetNewClosure()
        $session = [pscustomobject]@{}
        $session | Add-Member -MemberType ScriptMethod -Name CreateUpdateSearcher -Value { $searcher }.GetNewClosure()
        return $session
    }
    if ($ArgumentList) { Microsoft.PowerShell.Utility\New-Object -TypeName $TypeName -ArgumentList $ArgumentList } else { Microsoft.PowerShell.Utility\New-Object -TypeName $TypeName }
}
