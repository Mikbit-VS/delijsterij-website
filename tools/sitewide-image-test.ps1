$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$workspaceRoot = (Resolve-Path '.').Path
$siteRoot = Join-Path $workspaceRoot 'site'
$imagesRoot = Join-Path $siteRoot 'assets\images'
$testRoot = Join-Path $siteRoot 'assets\images-test'
$htmlFiles = Get-ChildItem -Path $siteRoot -Filter '*.html' -File
$cssFiles = Get-ChildItem -Path (Join-Path $siteRoot 'assets\css') -Filter '*.css' -File
$cacheVersion = '20260407sitewideimagetest'

function Get-MagicFormat {
  param([string]$Path)

  $bytes = Get-Content -LiteralPath $Path -Encoding Byte -TotalCount 16
  if ($bytes.Length -ge 12 -and
      $bytes[0] -eq 0x52 -and $bytes[1] -eq 0x49 -and $bytes[2] -eq 0x46 -and $bytes[3] -eq 0x46 -and
      $bytes[8] -eq 0x57 -and $bytes[9] -eq 0x45 -and $bytes[10] -eq 0x42 -and $bytes[11] -eq 0x50) {
    return 'webp'
  }
  if ($bytes.Length -ge 8 -and
      $bytes[0] -eq 0x89 -and $bytes[1] -eq 0x50 -and $bytes[2] -eq 0x4E -and $bytes[3] -eq 0x47) {
    return 'png'
  }
  if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xD8 -and $bytes[2] -eq 0xFF) {
    return 'jpeg'
  }
  if ($bytes.Length -ge 6) {
    $sig = [System.Text.Encoding]::ASCII.GetString($bytes, 0, [Math]::Min(6, $bytes.Length))
    if ($sig -in @('GIF87a', 'GIF89a')) {
      return 'gif'
    }
  }
  return ([IO.Path]::GetExtension($Path).TrimStart('.').ToLowerInvariant())
}

function Get-ImageInfo {
  param([string]$Path)

  try {
    $img = [System.Drawing.Image]::FromFile($Path)
    try {
      return [pscustomobject]@{
        Width  = $img.Width
        Height = $img.Height
        Format = $img.RawFormat.Guid.ToString()
      }
    }
    finally {
      $img.Dispose()
    }
  }
  catch {
    return [pscustomobject]@{
      Width  = $null
      Height = $null
      Format = ''
    }
  }
}

function Apply-ExifOrientation {
  param([System.Drawing.Image]$Image)

  if (-not ($Image.PropertyIdList -contains 274)) {
    return
  }

  $orientation = [BitConverter]::ToUInt16($Image.GetPropertyItem(274).Value, 0)
  $rotateFlip = switch ($orientation) {
    2 { [System.Drawing.RotateFlipType]::RotateNoneFlipX }
    3 { [System.Drawing.RotateFlipType]::Rotate180FlipNone }
    4 { [System.Drawing.RotateFlipType]::Rotate180FlipX }
    5 { [System.Drawing.RotateFlipType]::Rotate90FlipX }
    6 { [System.Drawing.RotateFlipType]::Rotate90FlipNone }
    7 { [System.Drawing.RotateFlipType]::Rotate270FlipX }
    8 { [System.Drawing.RotateFlipType]::Rotate270FlipNone }
    default { $null }
  }

  if ($null -ne $rotateFlip) {
    $Image.RotateFlip($rotateFlip)
  }
}

function Test-HasTransparency {
  param([string]$Path)

  $bmp = [System.Drawing.Bitmap]::FromFile($Path)
  try {
    if (-not [System.Drawing.Image]::IsAlphaPixelFormat($bmp.PixelFormat)) {
      return $false
    }

    $rect = New-Object System.Drawing.Rectangle(0, 0, $bmp.Width, $bmp.Height)
    $clone = $bmp.Clone($rect, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    try {
      $data = $clone.LockBits($rect, [System.Drawing.Imaging.ImageLockMode]::ReadOnly, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
      try {
        $bytes = New-Object byte[] ($data.Stride * $data.Height)
        [Runtime.InteropServices.Marshal]::Copy($data.Scan0, $bytes, 0, $bytes.Length)
        for ($i = 3; $i -lt $bytes.Length; $i += 4) {
          if ($bytes[$i] -lt 255) {
            return $true
          }
        }
        return $false
      }
      finally {
        $clone.UnlockBits($data)
      }
    }
    finally {
      $clone.Dispose()
    }
  }
  finally {
    $bmp.Dispose()
  }
}

function Save-JpegResized {
  param(
    [string]$SourcePath,
    [string]$DestinationPath,
    [int]$MaxWidth,
    [long]$Quality
  )

  $img = [System.Drawing.Image]::FromFile($SourcePath)
  try {
    Apply-ExifOrientation -Image $img
    $newWidth = if ($img.Width -gt $MaxWidth) { $MaxWidth } else { $img.Width }
    $newHeight = [int][Math]::Round($img.Height * ($newWidth / [double]$img.Width))
    $bmp = New-Object System.Drawing.Bitmap($newWidth, $newHeight)
    try {
      $gfx = [System.Drawing.Graphics]::FromImage($bmp)
      try {
        $gfx.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $gfx.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $gfx.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $gfx.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
        $gfx.Clear([System.Drawing.Color]::White)
        $gfx.DrawImage($img, 0, 0, $newWidth, $newHeight)
      }
      finally {
        $gfx.Dispose()
      }

      $codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/jpeg' }
      $encoder = [System.Drawing.Imaging.Encoder]::Quality
      $params = New-Object System.Drawing.Imaging.EncoderParameters(1)
      $params.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter($encoder, $Quality)
      $bmp.Save($DestinationPath, $codec, $params)

      return [pscustomobject]@{
        Width  = $newWidth
        Height = $newHeight
      }
    }
    finally {
      $bmp.Dispose()
    }
  }
  finally {
    $img.Dispose()
  }
}

function Save-PngResized {
  param(
    [string]$SourcePath,
    [string]$DestinationPath,
    [int]$MaxWidth
  )

  $img = [System.Drawing.Image]::FromFile($SourcePath)
  try {
    Apply-ExifOrientation -Image $img
    $newWidth = if ($img.Width -gt $MaxWidth) { $MaxWidth } else { $img.Width }
    $newHeight = [int][Math]::Round($img.Height * ($newWidth / [double]$img.Width))
    $bmp = New-Object System.Drawing.Bitmap($newWidth, $newHeight, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    try {
      $gfx = [System.Drawing.Graphics]::FromImage($bmp)
      try {
        $gfx.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $gfx.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $gfx.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $gfx.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
        $gfx.Clear([System.Drawing.Color]::Transparent)
        $gfx.DrawImage($img, 0, 0, $newWidth, $newHeight)
      }
      finally {
        $gfx.Dispose()
      }

      $bmp.Save($DestinationPath, [System.Drawing.Imaging.ImageFormat]::Png)

      return [pscustomobject]@{
        Width  = $newWidth
        Height = $newHeight
      }
    }
    finally {
      $bmp.Dispose()
    }
  }
  finally {
    $img.Dispose()
  }
}

function Ensure-DirectoryForFile {
  param([string]$FilePath)
  $dir = Split-Path -Parent $FilePath
  if (-not (Test-Path -LiteralPath $dir)) {
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
  }
}

$refs = New-Object System.Collections.Generic.List[object]
foreach ($file in $htmlFiles) {
  $content = Get-Content -LiteralPath $file.FullName -Raw
  foreach ($m in [regex]::Matches($content, '(?i)(?:src|href)\s*=\s*["''](?<path>assets/images(?!-test)(?!/test-optimized)[^"''?#]+\.(?:png|jpe?g|gif|webp|svg))["'']')) {
    $refs.Add([pscustomobject]@{ Source = $file.FullName; Kind = 'html'; Path = $m.Groups['path'].Value })
  }
  foreach ($m in [regex]::Matches($content, '(?i)url\((?:["'']?)(?<path>assets/images(?!-test)(?!/test-optimized)[^)"''?#]+\.(?:png|jpe?g|gif|webp|svg))(?:["'']?)\)')) {
    $refs.Add([pscustomobject]@{ Source = $file.FullName; Kind = 'html-inline'; Path = $m.Groups['path'].Value })
  }
}
foreach ($file in $cssFiles) {
  $content = Get-Content -LiteralPath $file.FullName -Raw
  foreach ($m in [regex]::Matches($content, '(?i)url\((?:["'']?)(?<path>\.\./images(?!-test)(?!/test-optimized)[^)"''?#]+\.(?:png|jpe?g|gif|webp|svg))(?:["'']?)\)')) {
    $refs.Add([pscustomobject]@{ Source = $file.FullName; Kind = 'css'; Path = $m.Groups['path'].Value })
  }
}

$refs = $refs | Sort-Object Source, Path -Unique
$uniqueImages = @{}
foreach ($ref in $refs) {
  $sitePath = if ($ref.Kind -eq 'css') { $ref.Path -replace '^\.\./images', 'assets/images' } else { $ref.Path }
  if (-not $uniqueImages.ContainsKey($sitePath)) {
    $diskPath = Join-Path $siteRoot $sitePath
    $uniqueImages[$sitePath] = [ordered]@{
      SitePath = $sitePath
      DiskPath = $diskPath
      UsedIn = New-Object System.Collections.Generic.List[string]
    }
  }
  $null = $uniqueImages[$sitePath].UsedIn.Add($ref.Source.Replace($workspaceRoot + '\', ''))
}

if (-not (Test-Path -LiteralPath $testRoot)) {
  New-Item -ItemType Directory -Path $testRoot -Force | Out-Null
}

$report = New-Object System.Collections.Generic.List[object]
$entries = $uniqueImages.GetEnumerator() | Sort-Object Key
foreach ($entry in $entries) {
  $sitePath = $entry.Key
  $data = $entry.Value
  $oldInfo = Get-ImageInfo -Path $data.DiskPath
  $oldItem = Get-Item -LiteralPath $data.DiskPath
  $magic = Get-MagicFormat -Path $data.DiskPath
  $relativeInsideImages = $sitePath.Substring('assets/images/'.Length)
  $targetRelativeBase = Join-Path 'assets/images-test' $relativeInsideImages
  $targetDiskBase = Join-Path $siteRoot $targetRelativeBase
  $targetRelative = $targetRelativeBase
  $targetDisk = $targetDiskBase
  $note = ''
  $optimized = $true

  if ($magic -eq 'jpeg') {
    $targetRelative = [IO.Path]::ChangeExtension($targetRelativeBase, '.jpg')
    $targetDisk = [IO.Path]::ChangeExtension($targetDiskBase, '.jpg')
    Ensure-DirectoryForFile -FilePath $targetDisk
    $newInfo = Save-JpegResized -SourcePath $data.DiskPath -DestinationPath $targetDisk -MaxWidth 1600 -Quality 78
  }
  elseif ($magic -eq 'png') {
    $hasTransparency = $false
    try {
      $hasTransparency = Test-HasTransparency -Path $data.DiskPath
    }
    catch {
      $hasTransparency = $true
      $note = 'png-transparency-check-failed'
    }

    if ($hasTransparency) {
      $targetRelative = [IO.Path]::ChangeExtension($targetRelativeBase, '.png')
      $targetDisk = [IO.Path]::ChangeExtension($targetDiskBase, '.png')
      Ensure-DirectoryForFile -FilePath $targetDisk
      $newInfo = Save-PngResized -SourcePath $data.DiskPath -DestinationPath $targetDisk -MaxWidth 1600
      if (-not $note) {
        $note = 'kept-png-for-transparency'
      }
    }
    else {
      $targetRelative = [IO.Path]::ChangeExtension($targetRelativeBase, '.jpg')
      $targetDisk = [IO.Path]::ChangeExtension($targetDiskBase, '.jpg')
      Ensure-DirectoryForFile -FilePath $targetDisk
      $newInfo = Save-JpegResized -SourcePath $data.DiskPath -DestinationPath $targetDisk -MaxWidth 1600 -Quality 78
      $note = 'png-converted-to-jpg'
    }
  }
  elseif ($magic -eq 'gif') {
    $targetRelative = [IO.Path]::ChangeExtension($targetRelativeBase, '.gif')
    $targetDisk = [IO.Path]::ChangeExtension($targetDiskBase, '.gif')
    Ensure-DirectoryForFile -FilePath $targetDisk
    Copy-Item -LiteralPath $data.DiskPath -Destination $targetDisk -Force
    $newInfo = Get-ImageInfo -Path $targetDisk
    $optimized = $false
    $note = 'copied-unsupported-gif'
  }
  else {
    $targetRelative = [IO.Path]::ChangeExtension($targetRelativeBase, [IO.Path]::GetExtension($data.DiskPath))
    $targetDisk = [IO.Path]::ChangeExtension($targetDiskBase, [IO.Path]::GetExtension($data.DiskPath))
    Ensure-DirectoryForFile -FilePath $targetDisk
    Copy-Item -LiteralPath $data.DiskPath -Destination $targetDisk -Force
    $newInfo = Get-ImageInfo -Path $targetDisk
    $optimized = $false
    $note = "copied-unsupported-$magic"
  }

  $newItem = Get-Item -LiteralPath $targetDisk
  $report.Add([pscustomobject]@{
    OriginalSitePath = $sitePath
    TestSitePath = $targetRelative.Replace('\', '/')
    UsedIn = @($data.UsedIn | Sort-Object -Unique)
    OldBytes = $oldItem.Length
    NewBytes = $newItem.Length
    OldWidth = $oldInfo.Width
    OldHeight = $oldInfo.Height
    NewWidth = $newInfo.Width
    NewHeight = $newInfo.Height
    Optimized = $optimized
    Note = $note
  })
}

foreach ($file in $htmlFiles) {
  $content = Get-Content -LiteralPath $file.FullName -Raw
  foreach ($row in $report) {
    $content = $content.Replace($row.OriginalSitePath, $row.TestSitePath)
  }
  $content = [regex]::Replace($content, 'assets/css/main\.css\?v=[^"''>]+', "assets/css/main.css?v=$cacheVersion")
  Set-Content -LiteralPath $file.FullName -Value $content -NoNewline
}

foreach ($file in $cssFiles) {
  $content = Get-Content -LiteralPath $file.FullName -Raw
  foreach ($row in $report) {
    $originalCss = $row.OriginalSitePath -replace '^assets/images', '../images'
    $testCss = $row.TestSitePath -replace '^assets/images-test', '../images-test'
    $content = $content.Replace($originalCss, $testCss)
  }
  Set-Content -LiteralPath $file.FullName -Value $content -NoNewline
}

$reportDir = Join-Path $testRoot '_report'
if (-not (Test-Path -LiteralPath $reportDir)) {
  New-Item -ItemType Directory -Path $reportDir -Force | Out-Null
}
$reportPath = Join-Path $reportDir 'sitewide-image-test-report.json'
$csvPath = Join-Path $reportDir 'sitewide-image-test-report.csv'
$report | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $reportPath
$report | Select-Object OriginalSitePath, TestSitePath, OldBytes, NewBytes, OldWidth, OldHeight, NewWidth, NewHeight, Optimized, Note | Export-Csv -LiteralPath $csvPath -NoTypeInformation -Encoding UTF8

$summary = [pscustomobject]@{
  ImageCount = $report.Count
  TestImageCount = ($report | Measure-Object).Count
  TotalOldBytes = ($report | Measure-Object -Property OldBytes -Sum).Sum
  TotalNewBytes = ($report | Measure-Object -Property NewBytes -Sum).Sum
  Unsupported = @($report | Where-Object { -not $_.Optimized } | Select-Object OriginalSitePath, TestSitePath, Note)
  ManualCheck = @($report | Where-Object { $_.Note -match 'unsupported|failed' } | Select-Object OriginalSitePath, TestSitePath, Note)
  TopSavings = @(
    $report |
      Select-Object OriginalSitePath, TestSitePath, OldBytes, NewBytes, @{Name='SavedBytes';Expression={ $_.OldBytes - $_.NewBytes }}, Note |
      Sort-Object SavedBytes -Descending |
      Select-Object -First 10
  )
}
$summary | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $reportDir 'sitewide-image-test-summary.json')
$summary
