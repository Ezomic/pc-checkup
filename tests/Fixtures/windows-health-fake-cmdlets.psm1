function Test-Scenario([string]$Name) { return $env:PCC_FAKE_SCENARIO -eq $Name }

function Get-FakeSid([int]$Rid) { [pscustomobject]@{ Value = "S-1-5-21-1-2-3-$Rid" } }

function Get-PhysicalDisk {
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    if (Test-Scenario 'empty') { return }
    [pscustomobject]@{ FriendlyName = "$([char]7)Samsung SSD 970 | EVO`t"; MediaType = 'SSD'; BusType = 'NVMe'; Size = 256060514304; HealthStatus = 'Healthy'; OperationalStatus = @('OK'); SerialNumber = 'SECRET-SERIAL-1'; DeviceId = '0' }
    [pscustomobject]@{ FriendlyName = 'Backup stick'; MediaType = 'Unspecified'; BusType = 'USB'; Size = 0; HealthStatus = 'Unhealthy'; OperationalStatus = @('OK', 'Degraded'); SerialNumber = 'SECRET-SERIAL-2'; DeviceId = '1' }
    [pscustomobject]@{ FriendlyName = 'Odd disk'; MediaType = 'HDD'; BusType = 'SATA'; Size = 1000204886016; HealthStatus = 'Warning'; OperationalStatus = $null; SerialNumber = 'SECRET-SERIAL-3'; DeviceId = '2' }
}

function Get-StorageReliabilityCounter {
    param([Parameter(ValueFromPipeline = $true)] $Disk)
    process {
        if ($Disk.DeviceId -eq '0') { [pscustomobject]@{ Wear = 4; Temperature = 41; ReadErrorsTotal = 0; WriteErrorsTotal = 0; PowerOnHours = 1234 } }
        if ($Disk.DeviceId -eq '1') { throw 'Access to a CIM resource was not available to the client.' }
        if ($Disk.DeviceId -eq '2') { [pscustomobject]@{ Wear = 250; Temperature = -5; ReadErrorsTotal = 'many'; WriteErrorsTotal = 7; PowerOnHours = $null } }
    }
}

function Get-MpComputerStatus {
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    [pscustomobject]@{ RealTimeProtectionEnabled = $false; AntivirusSignatureAge = 3; QuickScanAge = [uint32]4294967295; FullScanAge = 12; IsTamperProtected = $true }
}

function Get-CimInstance {
    param($Namespace, $ClassName, $OperationTimeoutSec)
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    [pscustomobject]@{ displayName = 'Windows Defender' }
    [pscustomobject]@{ displayName = "Norton 360 | $([char]7)evil" }
    [pscustomobject]@{ displayName = 'Norton 360 | evil' }
}

function Get-BitLockerVolume {
    param($MountPoint)
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    [pscustomobject]@{ ProtectionStatus = 'Off' }
}

function Get-NetFirewallProfile {
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    if (Test-Scenario 'hang') { Start-Sleep -Seconds 60 }
    [pscustomobject]@{ Name = 'Domain'; Enabled = 'True' }
    [pscustomobject]@{ Name = 'Private'; Enabled = 'False' }
    [pscustomobject]@{ Name = 'Public'; Enabled = 'NotConfigured' }
}

function Get-SmbServerConfiguration {
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    [pscustomobject]@{ EnableSMB1Protocol = $true }
}

function Get-LocalUser {
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    [pscustomobject]@{ Name = 'SecretAdminName'; Enabled = $true; SID = (Get-FakeSid 500) }
    [pscustomobject]@{ Name = 'SecretPerson'; Enabled = $true; SID = (Get-FakeSid 1001) }
    [pscustomobject]@{ Name = 'SecretGuest'; Enabled = $false; SID = (Get-FakeSid 1002) }
}

function Get-LocalGroupMember {
    param($SID)
    if (Test-Scenario 'denied') { throw 'Access is denied' }
    [pscustomobject]@{ Name = 'PC\SecretAdminName'; SID = (Get-FakeSid 500) }
    [pscustomobject]@{ Name = 'PC\SecretPerson'; SID = (Get-FakeSid 1001) }
    [pscustomobject]@{ Name = 'PC\SecretGuest'; SID = (Get-FakeSid 1002) }
}
