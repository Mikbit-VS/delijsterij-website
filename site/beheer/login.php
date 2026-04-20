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

const CMS_PASSWORD = 'lijsterij2026';

function wantsJson(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return stripos($accept, 'application/json') !== false || strtolower($xrw) === 'xmlhttprequest';
}

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    $authenticated = !empty($_SESSION['cms_authenticated']);
    jsonResponse([
        'authenticated' => $authenticated,
        'csrfToken' => $authenticated ? (string) ($_SESSION['cms_csrf'] ?? '') : '',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();

    if (wantsJson()) {
        jsonResponse(['success' => true]);
    }
    header('Location: index.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$password = (string) ($_POST['password'] ?? '');

if (hash_equals(CMS_PASSWORD, $password)) {
    $_SESSION['cms_authenticated'] = true;
    if (empty($_SESSION['cms_csrf'])) {
        $_SESSION['cms_csrf'] = bin2hex(random_bytes(32));
    }
    if (wantsJson()) {
        jsonResponse(['success' => true, 'csrfToken' => $_SESSION['cms_csrf']]);
    }
    header('Location: index.html');
    exit;
}

unset($_SESSION['cms_authenticated']);
unset($_SESSION['cms_csrf']);

if (wantsJson()) {
    jsonResponse(['success' => false, 'message' => 'Onjuist wachtwoord.'], 401);
}

header('Location: index.html?error=1');
exit;
