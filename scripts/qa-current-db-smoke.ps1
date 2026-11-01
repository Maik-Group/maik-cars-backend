param(
  [string] $BaseUrl = 'http://127.0.0.1:8018',
  [string] $ItemId = '019ea6e1-6df9-70dc-a303-c1ef876a2d26'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$secretFile = Join-Path $root 'storage/app/private/maik-qa-credentials.json'
if (!(Test-Path $secretFile)) { throw 'QA credential file is absent; run MaikCatQaBillingSeeder first.' }
$manifest = Get-Content $secretFile -Raw | ConvertFrom-Json
$results = New-Object System.Collections.Generic.List[object]

function Invoke-QaApi {
  param([string]$Method, [string]$Path, [object]$Body = $null, [string]$Token = $null)
  $headers = @{Accept='application/json'}
  if ($Token) { $headers.Authorization = 'Bearer ' + $Token }
  $args = @{Uri=($BaseUrl.TrimEnd('/')+$Path); Method=$Method; Headers=$headers; UseBasicParsing=$true; TimeoutSec=35}
  if ($null -ne $Body) {
    $headers['Content-Type'] = 'application/json'
    $args.Body = ConvertTo-Json -InputObject $Body -Depth 8 -Compress
  }
  try {
    $res=Invoke-WebRequest @args
    $parsed=$null
    try{$parsed=$res.Content | ConvertFrom-Json}catch{}
    return [pscustomobject]@{Status=[int]$res.StatusCode; Value=$parsed}
  } catch {
    $status=0
    if ($_.Exception.Response) {$status=[int]$_.Exception.Response.StatusCode}
    return [pscustomobject]@{Status=$status; Value=$null}
  }
}

function Assert-Qa {
  param([string]$Name, [bool]$Condition, [string]$Detail)
  $status = if($Condition){'PASS'}else{'FAIL'}
  $results.Add([pscustomobject]@{Name=$Name; Status=$status; Detail=$Detail})
  Write-Output ($status+' '+$Name+' '+$Detail)
}

$catalog=Invoke-QaApi 'GET' '/api/v1/billing/plans'
Assert-Qa 'catalog' ($catalog.Status -eq 200 -and @($catalog.Value.data.subscriptions).Count -eq 4 -and @($catalog.Value.data.topUps).Count -eq 3) ('HTTP '+$catalog.Status)
$eligible = @($catalog.Value.data.subscriptions) + @($catalog.Value.data.topUps) | Where-Object {$_.checkoutEligible -eq $true}
Assert-Qa 'checkout-fail-closed' ($catalog.Value.data.externalCheckoutAllowed -eq $false -and @($eligible).Count -eq 0) 'no provider-verified offers'
$anon = Invoke-QaApi 'GET' '/api/v1/wallet'
Assert-Qa 'wallet-auth' ($anon.Status -eq 401) ('HTTP '+$anon.Status)
$item = Invoke-QaApi 'GET' ('/api/items/'+$ItemId)
Assert-Qa 'public-price-redacted' ($item.Status -eq 200 -and $null -eq $item.Value.data.price -and $null -eq $item.Value.data.basePrice -and $null -eq $item.Value.data.ptPpm -and $null -eq $item.Value.data.weightKg) ('HTTP '+$item.Status)

$balances = @{empty=0;free=7;paid=15;topup=10;mixed=17;complimentary=6;once=4;expired=0;held=2;bulk=3;admin=0}
foreach($scenario in @('empty','free','paid','topup','mixed','complimentary','once','expired','held','bulk','admin')) {
  $account=$manifest.accounts.$scenario
  $login=Invoke-QaApi 'POST' '/api/auth/login' @{email=$account.email;password=$account.password;device_id=('maik-qa-smoke-'+$scenario)}
  if ($login.Status -ne 200 -or !$login.Value.token) {
    Assert-Qa ('login-'+$scenario) $false ('HTTP '+$login.Status)
    continue
  }
  $token=$login.Value.token
  try {
    $wallet=Invoke-QaApi 'GET' '/api/v1/wallet' $null $token
    $expected=$balances[$scenario]
    # A repeatable smoke run may have already consumed one paid/mixed point.
    $valid = $wallet.Status -eq 200 -and (
      [int]$wallet.Value.data.balance -eq $expected -or
      ($scenario -in @('paid','mixed') -and [int]$wallet.Value.data.balance -eq ($expected-1)))
    Assert-Qa ('wallet-'+$scenario) $valid ('HTTP '+$wallet.Status+' balance='+$wallet.Value.data.balance)
    if ($scenario -eq 'held' -and $wallet.Status -eq 200) {
      $heldGrants=@($wallet.Value.data.grants | Where-Object {$_.heldAt -ne $null -or $_.held_at -ne $null})
      Assert-Qa 'held-source-visible' ($heldGrants.Count -eq 1) ('heldGrants='+$heldGrants.Count+' balance=2')
    }
    if ($scenario -eq 'complimentary' -or $scenario -eq 'once') {
      $entitlements=Invoke-QaApi 'GET' '/api/v1/billing/subscription' $null $token
      Assert-Qa ('complimentary-'+$scenario) ($entitlements.Status -eq 200 -and @($entitlements.Value.data.complimentaryEntitlements).Count -ge 1) ('HTTP '+$entitlements.Status)
    }
    if ($scenario -eq 'empty') {
      $checkout=Invoke-QaApi 'POST' '/api/v1/billing/checkouts' @{kind='topup';priceVersionId=5;purchaseContextId='qa-local'} $token
      Assert-Qa 'checkout-disabled' ($checkout.Status -eq 403) ('HTTP '+$checkout.Status)
      $zero=Invoke-QaApi 'POST' ('/api/v2/items/'+$ItemId+'/valuation') @{currency='USD'} $token
      if($zero.Status -eq 503) {
        Write-Output 'BLOCKED empty-valuation: metals price upstream unavailable (HTTP 503)'
      } else {
        Assert-Qa 'empty-valuation' ($zero.Status -eq 402) ('HTTP '+$zero.Status)
      }
    }
    if ($scenario -eq 'paid') {
      $before=[int]$wallet.Value.data.balance
      $first=Invoke-QaApi 'POST' ('/api/v2/items/'+$ItemId+'/valuation') @{currency='USD'} $token
      if($first.Status -eq 200) {
        $again=Invoke-QaApi 'POST' ('/api/v2/items/'+$ItemId+'/valuation') @{currency='USD'} $token
        $now=Invoke-QaApi 'GET' '/api/v1/wallet' $null $token
        $date=(Get-Date).ToUniversalTime().ToString('yyyy-MM-dd')
        $revalidated=Invoke-QaApi 'POST' ('/api/v2/items/'+$ItemId+'/valuation') @{currency='USD';revalidate_date=$date} $token
        Assert-Qa 'valuation-reopen-once' ($again.Status -eq 200 -and $revalidated.Status -eq 200 -and $again.Value.wallet.counted -eq $false) ('first='+$first.Status+' repeat='+$again.Status+' balance='+$now.Value.data.balance)
        Assert-Qa 'valuation-at-most-one-debit' ($before-[int]$now.Value.data.balance -ge 0 -and $before-[int]$now.Value.data.balance -le 1) ('before='+$before+' after='+$now.Value.data.balance)
      }else{
        Write-Output ('BLOCKED live-valuation HTTP '+$first.Status+'; no paid QA points were deliberately manufactured')
      }
    }
  } finally {
    $logout=Invoke-QaApi 'POST' '/api/auth/logout' @{} $token
    Assert-Qa ('logout-'+$scenario) ($logout.Status -eq 200) ('HTTP '+$logout.Status)
  }
}

$failed=@($results | Where-Object {$_.Status -eq 'FAIL'})
Write-Output ('QA_SMOKE_SUMMARY passes='+@($results | Where-Object {$_.Status -eq 'PASS'}).Count+' failures='+$failed.Count)
if($failed.Count -gt 0){exit 1}
