$ErrorActionPreference = 'Stop'

$siteRoot = Join-Path (Resolve-Path '.').Path 'site'
$htmlFiles = Get-ChildItem -Path $siteRoot -Filter '*.html' -File
$cssFiles = Get-ChildItem -Path (Join-Path $siteRoot 'assets\css') -Filter '*.css' -File

foreach ($file in $htmlFiles) {
  $content = Get-Content -LiteralPath $file.FullName -Raw
  $content = $content.Replace('assets/images-test/', 'assets/images/')
  Set-Content -LiteralPath $file.FullName -Value $content -NoNewline
}

foreach ($file in $cssFiles) {
  $content = Get-Content -LiteralPath $file.FullName -Raw
  $content = $content.Replace('../images-test/', '../images/')
  Set-Content -LiteralPath $file.FullName -Value $content -NoNewline
}
