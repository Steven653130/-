<?php
require_once '../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw_body = file_get_contents('php://input');
$data = json_decode($raw_body, true);
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!$signature || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$expected_signature = hash_hmac('sha256', $raw_body, HMAC_SECRET);

if (!hash_equals($expected_signature, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$url = $data['url'] ?? '';
$translations = $data['translations'];
$embedding = json_encode($data['embedding']);
$image_url = trim((string) ($data['image_url'] ?? ''));
$cardTranslations = $data['card_translations'] ?? [];

if ($url === '' || !is_array($translations)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing url or translations']);
    exit;
}

$stmt = $mysqli->prepare("INSERT INTO articles (url, image_url, content_zh, content_en, content_fr, content_es, content_ar, content_ru, llm_title_zh, llm_title_en, llm_title_fr, llm_title_es, llm_title_ar, llm_title_ru, llm_summary_zh, llm_summary_en, llm_summary_fr, llm_summary_es, llm_summary_ar, llm_summary_ru, embedding) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $mysqli->error]);
    exit;
}

$content_zh = $translations['zh'] ?? '';
$content_en = $translations['en'] ?? '';
$content_fr = $translations['fr'] ?? '';
$content_es = $translations['es'] ?? '';
$content_ar = $translations['ar'] ?? '';
$content_ru = $translations['ru'] ?? '';

$llm_title_zh = mb_substr(trim((string) (($cardTranslations['zh']['title'] ?? ''))), 0, 180);
$llm_title_en = mb_substr(trim((string) (($cardTranslations['en']['title'] ?? ''))), 0, 180);
$llm_title_fr = mb_substr(trim((string) (($cardTranslations['fr']['title'] ?? ''))), 0, 180);
$llm_title_es = mb_substr(trim((string) (($cardTranslations['es']['title'] ?? ''))), 0, 180);
$llm_title_ar = mb_substr(trim((string) (($cardTranslations['ar']['title'] ?? ''))), 0, 180);
$llm_title_ru = mb_substr(trim((string) (($cardTranslations['ru']['title'] ?? ''))), 0, 180);

$llm_summary_zh = mb_substr(trim((string) (($cardTranslations['zh']['summary'] ?? ''))), 0, 1200);
$llm_summary_en = mb_substr(trim((string) (($cardTranslations['en']['summary'] ?? ''))), 0, 1200);
$llm_summary_fr = mb_substr(trim((string) (($cardTranslations['fr']['summary'] ?? ''))), 0, 1200);
$llm_summary_es = mb_substr(trim((string) (($cardTranslations['es']['summary'] ?? ''))), 0, 1200);
$llm_summary_ar = mb_substr(trim((string) (($cardTranslations['ar']['summary'] ?? ''))), 0, 1200);
$llm_summary_ru = mb_substr(trim((string) (($cardTranslations['ru']['summary'] ?? ''))), 0, 1200);

$stmt->bind_param(
    "sssssssssssssssssssss",
    $url,
    $image_url,
    $content_zh,
    $content_en,
    $content_fr,
    $content_es,
    $content_ar,
    $content_ru,
    $llm_title_zh,
    $llm_title_en,
    $llm_title_fr,
    $llm_title_es,
    $llm_title_ar,
    $llm_title_ru,
    $llm_summary_zh,
    $llm_summary_en,
    $llm_summary_fr,
    $llm_summary_es,
    $llm_summary_ar,
    $llm_summary_ru,
    $embedding
);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
    exit;
}

echo json_encode(['success' => true]);
?>