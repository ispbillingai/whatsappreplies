# Screens a USB-connected Android phone for WhatsApp-relay suitability.
# Usage: plug in phone with USB debugging enabled, then:  .\check-phone.ps1
# Catches re-flashed/refurb "farm" devices whose firmware lies about the
# Android version — putting a WhatsApp number on those gets it banned instantly.

$adb = "C:\Users\magom\AppData\Local\Android\Sdk\platform-tools\adb.exe"

$devices = & $adb devices | Select-Object -Skip 1 | Where-Object { $_ -match "device$" }
if (-not $devices) { Write-Host "No phone detected. Enable USB debugging and accept the prompt." -ForegroundColor Red; exit 1 }

function Prop($name) { (& $adb shell getprop $name).Trim() }

$release  = Prop "ro.build.version.release"
$sdk      = [int](Prop "ro.build.version.sdk")
$patch    = Prop "ro.build.version.security_patch"
$fp       = Prop "ro.build.fingerprint"
$model    = Prop "ro.product.model"
$brand    = Prop "ro.product.brand"

# Real Android version from the SDK level (the one that can't be faked)
$sdkToVersion = @{ 21="5.0"; 22="5.1"; 23="6.0"; 24="7.0"; 25="7.1"; 26="8.0"; 27="8.1"; 28="9"; 29="10"; 30="11"; 31="12"; 32="12L"; 33="13"; 34="14"; 35="15"; 36="16" }
$realVersion = $sdkToVersion[$sdk]; if (-not $realVersion) { $realVersion = "API $sdk" }

Write-Host "`nDevice: $brand $model"
Write-Host "Claimed Android version : $release"
Write-Host "Real Android version    : $realVersion (API $sdk)"
Write-Host "Security patch          : $patch"
Write-Host "Fingerprint             : $fp"

$fail = @()
if ($release -notmatch [regex]::Escape($realVersion.Split('.')[0])) {
    $fail += "FIRMWARE LIES: claims Android $release but is really $realVersion. Re-flashed device — likely ex-farm. DO NOT put a WhatsApp number on it."
}
if ($sdk -lt 26) {
    $fail += "Android $realVersion is very old; even if clean, it draws extra scrutiny from WhatsApp."
}
try {
    $patchDate = [datetime]::ParseExact($patch, "yyyy-MM-dd", $null)
    if ($patchDate -lt (Get-Date).AddYears(-4)) { $fail += "Security patch is over 4 years old ($patch) — ancient firmware." }
} catch {}
if ($fp -and $release -and ($fp -notmatch [regex]::Escape($release)) -and ($fp -match ":\d")) {
    $fail += "Fingerprint/version mismatch — properties are inconsistent, looks tampered to integrity checks."
}

Write-Host ""
if ($fail.Count -eq 0) {
    Write-Host "PASS: no tampering signs. Still: use the phone + SIM normally for a few days before enabling the relay." -ForegroundColor Green
} else {
    Write-Host "FAIL — do not register WhatsApp on this phone:" -ForegroundColor Red
    $fail | ForEach-Object { Write-Host " - $_" -ForegroundColor Yellow }
}
