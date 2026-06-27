$filename = "Project_BOT$(Get-Date -Format 'yyyyMMdd_HHmm').txt"

Get-ChildItem -Path app,routes,config,database,resources -Recurse `
  -Include *.php,*.blade.php `
  | Where-Object {
      $_.FullName -notmatch '\\vendor\\' -and
      $_.FullName -notmatch '\\storage\\' -and
      $_.FullName -notmatch '\\.git\\' -and
      $_.FullName -notmatch '\\node_modules\\' -and
      $_.FullName -notmatch '\\public\\' -and
      $_.FullName -notmatch '\\tests\\'
  } `
  | ForEach-Object {
      "================ WARNAI DENGAN FILE: $($_.Name) =================`n" +
      (Get-Content $_.FullName -Raw -Encoding UTF8)
  } | Set-Content -Path $filename -Encoding UTF8

$extras = @("composer.json", "composer.lock", "package.json", ".env.example")
foreach ($file in $extras) {
    if (Test-Path $file) {
        $content = "`n================ WARNAI DENGAN FILE: $file =================`n" +
                   (Get-Content $file -Raw -Encoding UTF8)
        Add-Content -Path $filename -Value $content -Encoding UTF8
    }
}

Write-Host "Tersimpan sebagai: $filename"
