<?php
require_once '../config.php';

session_start();

if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
    http_response_code(500);
    echo 'Google OAuth not configured: missing GOOGLE_CLIENT_ID or GOOGLE_CLIENT_SECRET';
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$defaultRedirect = $scheme . '://' . $host . '/web/backend/api/google_callback.php';
$redirectUri = GOOGLE_REDIRECT_URI !== '' ? GOOGLE_REDIRECT_URI : $defaultRedirect;

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
    'access_type' => 'online',
    'include_granted_scopes' => 'true',
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Location: ' . $authUrl, true, 302);
exit;
