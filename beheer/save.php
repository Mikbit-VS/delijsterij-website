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
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function headerValue(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$serverKey] ?? '');
}

function rotateBackups(string $targetFile): void
{
    if (!is_file($targetFile)) {
        return;
    }

    $bak1 = $targetFile . '.bak1';
    $bak2 = $targetFile . '.bak2';
    $bak3 = $targetFile . '.bak3';

    if (is_file($bak2)) {
        @copy($bak2, $bak3);
    }
    if (is_file($bak1)) {
        @copy($bak1, $bak2);
    }
    @copy($targetFile, $bak1);
}

function textLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value);
    }
    return strlen($value);
}

if (empty($_SESSION['cms_authenticated'])) {
    respond(['success' => false, 'message' => 'Niet ingelogd.'], 401);
}

$absoluteTarget = '/home/delijsterij.nl/httpdocs/content/content.json';
$localDir = realpath(__DIR__ . '/../content');
$localFallback = $localDir ? ($localDir . DIRECTORY_SEPARATOR . 'content.json') : '';

$target = $absoluteTarget;
if (!is_dir(dirname($target)) && $localFallback !== '') {
    $target = $localFallback;
}

$action = (string) ($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'backups') {
    $items = [];
    foreach (['bak1', 'bak2', 'bak3'] as $suffix) {
        $file = $target . '.' . $suffix;
        $exists = is_file($file);
        $items[] = [
            'name' => $suffix,
            'exists' => $exists,
            'modifiedAt' => $exists ? gmdate('c', (int) filemtime($file)) : null,
        ];
    }
    respond(['success' => true, 'backups' => $items]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    respond(['success' => false, 'message' => 'Ongeldige JSON-payload.'], 400);
}

$sessionToken = (string) ($_SESSION['cms_csrf'] ?? '');
$requestToken = headerValue('X-CSRF-Token');
if ($requestToken === '') {
    $requestToken = (string) ($payload['csrfToken'] ?? '');
}
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    respond(['success' => false, 'message' => 'Ongeldige sessiecontrole (CSRF).'], 403);
}

if ($action === 'restore') {
    $backup = (string) ($payload['backup'] ?? '');
    if (!in_array($backup, ['bak1', 'bak2', 'bak3'], true)) {
        respond(['success' => false, 'message' => 'Ongeldige backupkeuze.'], 400);
    }

    $source = $target . '.' . $backup;
    if (!is_file($source)) {
        respond(['success' => false, 'message' => 'Geselecteerde backup bestaat niet.'], 404);
    }

    $snapshot = file_get_contents($source);
    if ($snapshot === false) {
        respond(['success' => false, 'message' => 'Backup kon niet worden gelezen.'], 500);
    }

    rotateBackups($target);
    if (@file_put_contents($target, $snapshot) === false) {
        respond(['success' => false, 'message' => 'Herstellen van backup is mislukt.'], 500);
    }

    respond(['success' => true, 'message' => 'Backup hersteld ✓']);
}

$current = ['updatedAt' => gmdate('c'), 'pages' => []];
if (is_file($target)) {
    $existing = json_decode((string) file_get_contents($target), true);
    if (is_array($existing)) {
        $current = $existing;
        if (!isset($current['pages']) || !is_array($current['pages'])) {
            $current['pages'] = [];
        }
    }
}

$page = (string) ($payload['page'] ?? '');
$fields = $payload['fields'] ?? null;
if ($page === '' || !preg_match('/^[a-z0-9-]+$/', $page) || !is_array($fields)) {
    respond(['success' => false, 'message' => 'Verplicht en geldig: page + fields.'], 400);
}

if (
    !isset($current['pages'][$page]) ||
    !is_array($current['pages'][$page]) ||
    !isset($current['pages'][$page]['fields']) ||
    !is_array($current['pages'][$page]['fields'])
) {
    respond(['success' => false, 'message' => 'Onbekende pagina.'], 400);
}

$allowedKeys = array_keys($current['pages'][$page]['fields']);
$allowedMap = array_fill_keys($allowedKeys, true);

foreach ($fields as $key => $value) {
    $key = (string) $key;
    if ($key === '' || !isset($allowedMap[$key])) {
        respond(['success' => false, 'message' => 'Onbekende veldsleutel: ' . $key], 400);
    }
    if (!is_scalar($value) && $value !== null) {
        respond(['success' => false, 'message' => 'Ongeldige veldwaarde voor: ' . $key], 400);
    }

    $value = (string) $value;
    if (textLength($value) > 20000) {
        respond(['success' => false, 'message' => 'Veld te lang: ' . $key], 400);
    }

    $current['pages'][$page]['fields'][$key] = $value;
}

$current['updatedAt'] = gmdate('c');
$json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    respond(['success' => false, 'message' => 'Kon JSON niet serialiseren.'], 500);
}

rotateBackups($target);

if (@file_put_contents($target, $json . PHP_EOL) === false) {
    respond(['success' => false, 'message' => 'Opslaan van content.json is mislukt.'], 500);
}

respond(['success' => true, 'message' => 'Opgeslagen ✓']);
