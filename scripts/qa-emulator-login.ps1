param([ValidateSet('empty','free','paid','topup','mixed','complimentary','once','expired','held','bulk')][string]$Scenario='mixed')
$ErrorActionPreference='Stop'
$private=Join-Path (Split-Path -Parent $PSScriptRoot) 'storage/app/private/maik-qa-credentials.json'
if(!(Test-Path $private)){throw 'QA credentials missing. Run the explicit QA seeder.'}
$credentials=(Get-Content $private -Raw | ConvertFrom-Json).accounts.$Scenario
if(!$credentials -or !$credentials.password){throw 'QA login not configured'}
& adb -s emulator-5554 shell input tap 275 518 | Out-Null
# The ATD emulator occasionally drops fast injected IME events when React
# Native re-renders on every keystroke. Inject one character at a time.
& adb -s emulator-5554 shell input keyevent 123 | Out-Null
for($i=0;$i -lt 64;$i++){ & adb -s emulator-5554 shell input keyevent 67 | Out-Null }
foreach($ch in $credentials.email.ToCharArray()){
  & adb -s emulator-5554 shell input text ([string]$ch) | Out-Null
  Start-Sleep -Milliseconds 140
}
& adb -s emulator-5554 shell input keyevent 4 | Out-Null
& adb -s emulator-5554 shell uiautomator dump /sdcard/maik-qa-input.xml | Out-Null
[xml]$verify=(adb -s emulator-5554 shell cat /sdcard/maik-qa-input.xml) -join ''
$entered=@($verify.SelectNodes('//node[@class="android.widget.EditText"]') | Where-Object {$_.password -ne 'true'} | ForEach-Object {$_.text}) | Select-Object -First 1
if($entered -ne $credentials.email){
  throw ('QA email injection incomplete on emulator, length='+[string]$entered.Length+', expected length='+[string]$credentials.email.Length)
}
& adb -s emulator-5554 shell input tap 250 649 | Out-Null
& adb -s emulator-5554 shell input keyevent 123 | Out-Null
for($i=0;$i -lt 64;$i++){ & adb -s emulator-5554 shell input keyevent 67 | Out-Null }
foreach($ch in $credentials.password.ToCharArray()){
  & adb -s emulator-5554 shell input text ([string]$ch) | Out-Null
  Start-Sleep -Milliseconds 140
}
& adb -s emulator-5554 shell input keyevent 4 | Out-Null
& adb -s emulator-5554 shell input tap 260 785 | Out-Null
Write-Output ('Emulator QA login submitted for scenario '+$Scenario+'. Credentials kept private.')
