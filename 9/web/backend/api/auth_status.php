<?php
require_once '../config.php';

session_start();
header('Content-Type: application/json; charset=UTF-8');

$user = $_SESSION['user'] ?? null;
if (!is_array($user)) {
    echo json_encode(['authenticated' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'authenticated' => true,
    'user' => [
        'email' => (string) ($user['email'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'picture' => (string) ($user['picture'] ?? ''),
        'provider' => (string) ($user['provider'] ?? 'google'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
