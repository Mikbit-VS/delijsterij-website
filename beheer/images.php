<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestToken(): string
{
    $headerToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($headerToken !== '') {
        return $headerToken;
    }
    return (string) ($_POST['csrfToken'] ?? '');
}

function validateSession(): void
{
    if (empty($_SESSION['cms_authenticated'])) {
        respond(['success' => false, 'message' => 'Niet ingelogd.'], 401);
    }

    $sessionToken = (string) ($_SESSION['cms_csrf'] ?? '');
    $token = requestToken();
    if ($sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
        respond(['success' => false, 'message' => 'Ongeldige sessiecontrole (CSRF).'], 403);
    }
}

function siteRoot(): string
{
    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        respond(['success' => false, 'message' => 'Projectroot niet gevonden.'], 500);
    }
    return $root;
}

function normalizePublicPath(string $path): string
{
    $clean = trim(str_replace('\\', '/', $path));
    $queryPos = strpos($clean, '?');
    if ($queryPos !== false) {
        $clean = substr($clean, 0, $queryPos);
    }
    $hashPos = strpos($clean, '#');
    if ($hashPos !== false) {
        $clean = substr($clean, 0, $hashPos);
    }
    $clean = preg_replace('#^\./#', '', $clean) ?? $clean;
    $clean = ltrim($clean, '/');
    return $clean;
}

function htmlPages(string $root): array
{
    $files = glob($root . DIRECTORY_SEPARATOR . '*.html') ?: [];
    sort($files);
    return $files;
}

function extractImages(string $html): array
{
    $matches = [];
    preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+)\1[^>]*>/i', $html, $matches, PREG_SET_ORDER);

    $items = [];
    foreach ($matches as $match) {
        $src = trim((string) ($match[2] ?? ''));
        if ($src === '' || str_starts_with($src, 'data:') || preg_match('#^https?://#i', $src)) {
            continue;
        }

        $items[] = [
            'src' => $src,
        ];
    }

    return $items;
}

function listImages(string $root): array
{
    $pages = [];
    foreach (htmlPages($root) as $file) {
        $html = file_get_contents($file);
        if ($html === false) {
            continue;
        }

        $images = extractImages($html);
        if ($images === []) {
            continue;
        }

        $rel = basename($file);
        $bySrc = [];
        foreach ($images as $item) {
            $bySrc[$item['src']] = true;
        }

        $entries = [];
        foreach (array_keys($bySrc) as $src) {
            $normalized = normalizePublicPath($src);
            $absolute = realpath($root . DIRECTORY_SEPARATOR . $normalized);
            $exists = $absolute !== false && is_file($absolute);
            $entries[] = [
                'src' => $src,
                'exists' => $exists,
                'size' => $exists ? (int) filesize($absolute) : null,
                'updatedAt' => $exists ? gmdate('c', (int) filemtime($absolute)) : null,
            ];
        }

        usort($entries, static fn(array $a, array $b): int => strcmp($a['src'], $b['src']));

        $pages[] = [
            'slug' => pathinfo($rel, PATHINFO_FILENAME),
            'path' => '/' . $rel,
            'title' => $rel,
            'images' => $entries,
        ];
    }

    return $pages;
}

function safeTargetPath(string $root, string $relative): string
{
    $clean = normalizePublicPath($relative);
    if ($clean === '' || str_contains($clean, '..')) {
        respond(['success' => false, 'message' => 'Ongeldig afbeeldingspad.'], 400);
    }

    $absolute = realpath($root . DIRECTORY_SEPARATOR . $clean);
    if ($absolute === false || !is_file($absolute)) {
        respond(['success' => false, 'message' => 'Afbeelding niet gevonden op schijf.'], 404);
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/');
    $absNorm = str_replace('\\', '/', $absolute);
    if (!str_starts_with($absNorm, $rootPrefix . '/')) {
        respond(['success' => false, 'message' => 'Pad buiten project geblokkeerd.'], 400);
    }

    return $absolute;
}

function safeHtmlPath(string $root, string $pagePath): string
{
    $clean = normalizePublicPath($pagePath);
    if (!preg_match('/^[a-z0-9\-]+\.html$/i', $clean)) {
        respond(['success' => false, 'message' => 'Ongeldige pagina.'], 400);
    }

    $absolute = realpath($root . DIRECTORY_SEPARATOR . $clean);
    if ($absolute === false || !is_file($absolute)) {
        respond(['success' => false, 'message' => 'Pagina niet gevonden.'], 404);
    }

    return $absolute;
}

function nextVersionedPath(string $absolutePath): array
{
    $dir = dirname($absolutePath);
    $filename = basename($absolutePath);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $base = preg_replace('/-v\d{14}$/', '', $base) ?? $base;

    $stamp = gmdate('YmdHis');
    $newName = $base . '-v' . $stamp . '.' . $ext;
    $newPath = $dir . DIRECTORY_SEPARATOR . $newName;

    $attempt = 0;
    while (is_file($newPath) && $attempt < 20) {
        $attempt++;
        $newName = $base . '-v' . $stamp . '-' . $attempt . '.' . $ext;
        $newPath = $dir . DIRECTORY_SEPARATOR . $newName;
    }

    return [$newName, $newPath];
}

function validateUpload(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        respond(['success' => false, 'message' => 'Upload mislukt.'], 400);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        respond(['success' => false, 'message' => 'Uploadbestand niet geldig.'], 400);
    }

    $maxBytes = 12 * 1024 * 1024;
    if (((int) ($file['size'] ?? 0)) > $maxBytes) {
        respond(['success' => false, 'message' => 'Bestand is te groot (max 12MB).'], 400);
    }

    $mime = detectImageMime($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        respond(['success' => false, 'message' => 'Alleen JPG, PNG en WEBP zijn toegestaan.'], 400);
    }
}

function detectImageMime(string $path): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    if (function_exists('getimagesize')) {
        $info = @getimagesize($path);
        $mime = is_array($info) ? ($info['mime'] ?? '') : '';
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    return '';
}

function replaceLogPath(string $root): string
{
    return $root . DIRECTORY_SEPARATOR . 'content' . DIRECTORY_SEPARATOR . 'image-replace-log.json';
}

function readReplaceLog(string $root): array
{
    $path = replaceLogPath($root);
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function writeReplaceLog(string $root, array $items): bool
{
    $path = replaceLogPath($root);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }

    $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }

    return @file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function appendReplaceLog(string $root, array $entry): void
{
    $items = readReplaceLog($root);
    $items[] = $entry;
    if (count($items) > 500) {
        $items = array_slice($items, -500);
    }
    if (!writeReplaceLog($root, $items)) {
        respond(['success' => false, 'message' => 'Wijziging opgeslagen, maar logboek kon niet worden bijgewerkt.'], 500);
    }
}

function pageHistory(string $root, string $pagePath): array
{
    $normalized = '/' . ltrim(normalizePublicPath($pagePath), '/');
    $items = readReplaceLog($root);
    $filtered = array_values(array_filter($items, static function (array $item) use ($normalized): bool {
        return (string) ($item['pagePath'] ?? '') === $normalized;
    }));

    usort($filtered, static function (array $a, array $b): int {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });

    return array_slice($filtered, 0, 20);
}

$root = siteRoot();
$action = (string) ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    validateSession();
    respond(['success' => true, 'pages' => listImages($root)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'history') {
    validateSession();
    $pagePath = (string) ($_GET['pagePath'] ?? '');
    if ($pagePath === '') {
        respond(['success' => false, 'message' => 'Pagina ontbreekt.'], 400);
    }
    respond(['success' => true, 'items' => pageHistory($root, $pagePath)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'undo') {
    validateSession();
    $entryId = (string) ($_POST['entryId'] ?? '');
    if ($entryId === '') {
        respond(['success' => false, 'message' => 'Wijziging-id ontbreekt.'], 400);
    }

    $items = readReplaceLog($root);
    $targetIndex = null;
    foreach ($items as $index => $item) {
        if ((string) ($item['id'] ?? '') === $entryId) {
            $targetIndex = $index;
            break;
        }
    }

    if ($targetIndex === null) {
        respond(['success' => false, 'message' => 'Wijziging niet gevonden.'], 404);
    }

    $target = $items[$targetIndex];
    if (!empty($target['undoneAt'])) {
        respond(['success' => false, 'message' => 'Deze wijziging was al ongedaan gemaakt.'], 400);
    }

    $pagePath = (string) ($target['pagePath'] ?? '');
    $from = (string) ($target['newSrc'] ?? '');
    $to = (string) ($target['oldSrc'] ?? '');
    if ($pagePath === '' || $from === '' || $to === '') {
        respond(['success' => false, 'message' => 'Logitem is onvolledig en kan niet worden hersteld.'], 400);
    }

    $pageFile = safeHtmlPath($root, $pagePath);
    $html = @file_get_contents($pageFile);
    if ($html === false) {
        respond(['success' => false, 'message' => 'Pagina kon niet worden gelezen.'], 500);
    }

    $updatedHtml = str_replace($from, $to, $html, $replaceCount);
    if ($replaceCount < 1) {
        respond(['success' => false, 'message' => 'Geen verwijzing gevonden om ongedaan te maken.'], 400);
    }

    if (@file_put_contents($pageFile, $updatedHtml) === false) {
        respond(['success' => false, 'message' => 'Pagina kon niet worden bijgewerkt.'], 500);
    }

    $items[$targetIndex]['undoneAt'] = gmdate('c');
    if (!writeReplaceLog($root, $items)) {
        respond(['success' => false, 'message' => 'Wijziging teruggezet, maar logboek kon niet worden bijgewerkt.'], 500);
    }

    respond([
        'success' => true,
        'message' => 'Laatste wijziging is ongedaan gemaakt.',
        'pagePath' => $pagePath,
        'restoredSrc' => $to,
        'replacedReferences' => $replaceCount,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'replace') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

validateSession();

$pagePath = (string) ($_POST['pagePath'] ?? '');
$imageSrc = (string) ($_POST['imageSrc'] ?? '');
$file = $_FILES['imageFile'] ?? null;

if ($pagePath === '' || $imageSrc === '' || !is_array($file)) {
    respond(['success' => false, 'message' => 'Ontbrekende verplichte velden.'], 400);
}

validateUpload($file);
$pageFile = safeHtmlPath($root, $pagePath);
$targetImage = safeTargetPath($root, $imageSrc);

$oldExt = strtolower(pathinfo($targetImage, PATHINFO_EXTENSION));
$mime = detectImageMime((string) $file['tmp_name']);
$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
$newExt = $mimeToExt[$mime] ?? '';
if ($newExt === '' || !in_array($oldExt, ['jpg', 'jpeg', 'png', 'webp'], true)) {
    respond(['success' => false, 'message' => 'Niet-ondersteund afbeeldingsformaat.'], 400);
}

[$newName, $newAbsolute] = nextVersionedPath($targetImage);
$targetDir = dirname($targetImage);

if (!move_uploaded_file((string) $file['tmp_name'], $newAbsolute)) {
    respond(['success' => false, 'message' => 'Nieuwe afbeelding kon niet worden opgeslagen.'], 500);
}

if ($newExt !== $oldExt) {
    $newAbsoluteWithExt = preg_replace('/\.[a-z0-9]+$/i', '.' . $newExt, $newAbsolute) ?? $newAbsolute;
    $newName = basename($newAbsoluteWithExt);
    if (!rename($newAbsolute, $newAbsoluteWithExt)) {
        @unlink($newAbsolute);
        respond(['success' => false, 'message' => 'Bestandsconversie van extensie mislukt.'], 500);
    }
    $newAbsolute = $newAbsoluteWithExt;
}

$oldPublic = str_replace('\\', '/', $imageSrc);
$newPublic = str_replace('\\', '/', dirname($oldPublic));
$newPublic = rtrim($newPublic, '/');
$newPublic = ($newPublic === '' || $newPublic === '.') ? $newName : $newPublic . '/' . $newName;

$html = file_get_contents($pageFile);
if ($html === false) {
    @unlink($newAbsolute);
    respond(['success' => false, 'message' => 'Pagina kon niet worden gelezen.'], 500);
}

$updatedHtml = str_replace($oldPublic, $newPublic, $html, $replaceCount);
if ($replaceCount < 1) {
    @unlink($newAbsolute);
    respond(['success' => false, 'message' => 'Geen verwijzingen gevonden om te vervangen.'], 400);
}

if (@file_put_contents($pageFile, $updatedHtml) === false) {
    @unlink($newAbsolute);
    respond(['success' => false, 'message' => 'Pagina kon niet worden bijgewerkt.'], 500);
}

$entryId = bin2hex(random_bytes(16));
appendReplaceLog($root, [
    'id' => $entryId,
    'createdAt' => gmdate('c'),
    'undoneAt' => null,
    'pagePath' => '/' . basename($pageFile),
    'oldSrc' => $oldPublic,
    'newSrc' => $newPublic,
]);

respond([
    'success' => true,
    'message' => 'Afbeelding vervangen.',
    'newSrc' => $newPublic,
    'oldSrc' => $oldPublic,
    'entryId' => $entryId,
    'pagePath' => '/' . basename($pageFile),
    'replacedReferences' => $replaceCount,
]);
