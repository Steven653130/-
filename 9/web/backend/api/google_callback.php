<?php
require_once '../config.php';

session_start();

$frontIndex = '/web/frontend/index.html';

$fail = static function (string $message) use ($frontIndex): void {
    header('Location: ' . $frontIndex . '?login=failed&reason=' . rawurlencode($message), true, 302);
    exit;
};

if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
    $fail('google_not_configured');
}

$state = (string) ($_GET['state'] ?? '');
$code = (string) ($_GET['code'] ?? '');
$expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    $fail('invalid_state');
}

if ($code === '') {
    $fail('missing_code');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$defaultRedirect = $scheme . '://' . $host . '/web/backend/api/google_callback.php';
$redirectUri = GOOGLE_REDIRECT_URI !== '' ? GOOGLE_REDIRECT_URI : $defaultRedirect;

$postFields = http_build_query([
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if (!is_string($response) || $response === '' || $httpCode >= 400) {
    $fail('token_exchange_failed_' . ($curlError !== '' ? $curlError : (string) $httpCode));
}

$tokenData = json_decode($response, true);
if (!is_array($tokenData)) {
    $fail('invalid_token_response');
}

$idToken = (string) ($tokenData['id_token'] ?? '');
if ($idToken === '') {
    $fail('missing_id_token');
}

$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
$verifyResp = @file_get_contents($verifyUrl);
if (!is_string($verifyResp) || $verifyResp === '') {
    $fail('token_verify_failed');
}
$claims = json_decode($verifyResp, true);
if (!is_array($claims)) {
    $fail('invalid_claims');
}

$aud = (string) ($claims['aud'] ?? '');
$email = (string) ($claims['email'] ?? '');
$name = (string) ($claims['name'] ?? '');
$picture = (string) ($claims['picture'] ?? '');
$sub = (string) ($claims['sub'] ?? '');

if ($aud !== GOOGLE_CLIENT_ID || $sub === '') {
    $fail('aud_or_sub_invalid');
}

$_SESSION['user'] = [
    'sub' => $sub,
    'email' => $email,
    'name' => $name,
    'picture' => $picture,
    'provider' => 'google',
    'login_at' => date('c'),
];

// Persist user to database (upsert by google_id)
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$mysqli->connect_error) {
    $stmt = $mysqli->prepare(
        'INSERT INTO users (google_id, email, name) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE email = VALUES(email), name = VALUES(name)'
    );
    if ($stmt) {
        $stmt->bind_param('sss', $sub, $email, $name);
        $stmt->execute();
        $dbUserId = $mysqli->insert_id ?: null;
        // For ON DUPLICATE KEY UPDATE, insert_id is 0 when no insert happened; fetch actual id
        if (!$dbUserId) {
            $sel = $mysqli->prepare('SELECT id FROM users WHERE google_id = ?');
            if ($sel) {
                $sel->bind_param('s', $sub);
                $sel->execute();
                $sel->bind_result($dbUserId);
                $sel->fetch();
                $sel->close();
            }
        }
        $stmt->close();
        $_SESSION['user']['db_id'] = (int) $dbUserId;
    }
    $mysqli->close();
}

header('Location: ' . $frontIndex . '?login=success', true, 302);
exit;
