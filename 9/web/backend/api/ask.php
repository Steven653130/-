<?php
require_once '../config.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$data = json_decode(file_get_contents('php://input'), true);
$question = $data['question'];

// Simple RAG: Get recent articles
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$result = $mysqli->query("SELECT content_zh, embedding FROM articles ORDER BY created_at DESC LIMIT 5");

$contexts = [];
while ($row = $result->fetch_assoc()) {
    $contexts[] = $row['content_zh'];
}
$context = implode(' ', $contexts);

// Call GLM-4 for answer
// Since PHP, use curl to call Python service or directly, but for simplicity, mock
$answer = "基于知识库的回答：$context"; // Mock

echo "data: $answer\n\n";
flush();
?>