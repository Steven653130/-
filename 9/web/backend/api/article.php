<?php
require_once '../config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Always return JSON even when PHP notices/warnings happen.
ini_set('display_errors', '0');

set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function ($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'error' => 'Article API failed',
        'detail' => $e instanceof Throwable ? $e->getMessage() : 'unknown error',
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$article_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$lang = isset($_GET['lang']) ? (string)$_GET['lang'] : 'zh';

// Validate language
$valid_langs = ['zh', 'en', 'fr', 'es', 'ar', 'ru'];
if (!in_array($lang, $valid_langs)) {
    $lang = 'zh';
}

if ($article_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid article ID'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cleanDetailContent = static function (string $text): string {
    $s = trim($text);
    if ($s === '') {
        return '';
    }

    $lines = preg_split('/\R/u', $s) ?: [];
    $cleaned = [];
    $noise = [
        'URL Source', 'Markdown Content', 'Title:', 'javascript:void',
        '分享', '一键分享', 'QQ空间', '新浪微博', '百度经验', '登录', '投诉', '帮助',
        '相关经验', '返回 顶部', 'http://', 'https://'
    ];

    foreach ($lines as $lineRaw) {
        $line = trim((string) $lineRaw);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^\*\s*\[[^\]]+\]\([^\)]+\)$/u', $line)) {
            continue;
        }
        if (preg_match('/^!?\[[^\]]*\]\([^\)]+\)$/u', $line)) {
            continue;
        }
        if (preg_match('/https?:\/\//iu', $line)) {
            continue;
        }
        if (preg_match('/^#{1,6}\s*/u', $line)) {
            $line = preg_replace('/^#{1,6}\s*/u', '', $line);
        }

        $skip = false;
        foreach ($noise as $kw) {
            if (mb_stripos($line, $kw) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        $line = preg_replace('/^\d+\.\s*/u', '', $line);
        $line = preg_replace('/^\*+\s*/u', '', $line);
        $line = preg_replace('/\s+/u', ' ', (string) $line);
        $line = trim((string) $line);
        if ($line === '' || mb_strlen($line) < 8) {
            continue;
        }
        $cleaned[] = $line;
    }

    $cleaned = array_values(array_unique($cleaned));
    $out = implode("\n", $cleaned);
    return mb_substr(trim($out), 0, 6000);
};

$chineseRatio = static function (string $text): float {
    $s = trim($text);
    if ($s === '') {
        return 0.0;
    }
    $zh = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $s);
    $len = max(1, mb_strlen($s));
    return ((float) $zh) / ((float) $len);
};

$hasExpectedScript = static function (string $langCode, string $text): bool {
    if ($langCode === 'ru') {
        return (bool) preg_match('/[\x{0400}-\x{04FF}]/u', $text);
    }
    if ($langCode === 'ar') {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }
    if (in_array($langCode, ['en', 'fr', 'es'], true)) {
        return (bool) preg_match('/[A-Za-z]/u', $text);
    }
    return true;
};

$stripChineseBleed = static function (string $langCode, string $text): string {
    $s = trim($text);
    if ($s === '' || $langCode === 'zh') {
        return $s;
    }
    // Remove accidental Chinese fragments in non-zh localized output.
    $s = preg_replace('/[\x{4e00}-\x{9fff}]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', (string) $s);
    return trim((string) $s);
};

$isBadLocalized = static function (string $langCode, string $text) use ($chineseRatio, $hasExpectedScript): bool {
    $s = trim($text);
    if ($s === '') {
        return true;
    }
    if (!$hasExpectedScript($langCode, $s)) {
        return true;
    }
    $ratio = $chineseRatio($s);
    if (in_array($langCode, ['ar', 'ru'], true) && $ratio > 0.05) {
        return true;
    }
    if (in_array($langCode, ['en', 'fr', 'es'], true) && $ratio > 0.12) {
        return true;
    }
    return false;
};

// Fetch article from database
$stmt = $mysqli->prepare("
    SELECT 
        id, url, image_url,
        content_zh, content_en, content_fr, content_es, content_ar, content_ru,
        llm_title_zh, llm_title_en, llm_title_fr, llm_title_es, llm_title_ar, llm_title_ru,
        llm_summary_zh, llm_summary_en, llm_summary_fr, llm_summary_es, llm_summary_ar, llm_summary_ru,
        created_at
    FROM articles 
    WHERE id = ?
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query error: ' . $mysqli->error], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param('i', $article_id);
$stmt->execute();
$result = $stmt->get_result();
$article = $result->fetch_assoc();
$stmt->close();

if (!$article) {
    http_response_code(404);
    echo json_encode(['error' => 'Article not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Build response with all language versions
$response = [
    'id' => $article['id'],
    'url' => $article['url'],
    'image_url' => $article['image_url'] ?: '',
    'current_lang' => $lang,
    'created_at' => $article['created_at'],
    'languages' => [],
];

// Collect all language versions
foreach ($valid_langs as $l) {
    $title_col = "llm_title_{$l}";
    $summary_col = "llm_summary_{$l}";
    $content_col = "content_{$l}";
    
    $title = $article[$title_col] ?: '';
    $summary = $article[$summary_col] ?: '';
    $content = $cleanDetailContent((string) ($article[$content_col] ?: ''));

    $title = $stripChineseBleed($l, (string) $title);
    $summary = $stripChineseBleed($l, (string) $summary);
    $content = $stripChineseBleed($l, (string) $content);
    
    $response['languages'][$l] = [
        'title' => $title,
        'summary' => $summary,
        'content' => $content,
    ];
}

// Fallback for display: if selected language content looks invalid,
// use English then Chinese to avoid broken detail pages.
foreach ($valid_langs as $l) {
    if ($l === 'zh' || !isset($response['languages'][$l])) {
        continue;
    }

    $current = $response['languages'][$l];
    $en = $response['languages']['en'] ?? ['title' => '', 'summary' => '', 'content' => ''];
    $zh = $response['languages']['zh'] ?? ['title' => '', 'summary' => '', 'content' => ''];

    if ($isBadLocalized($l, (string) ($current['content'] ?? ''))) {
        $fallbackContent = (string) ($en['content'] ?? '');
        if ($fallbackContent === '') {
            $fallbackContent = (string) ($zh['content'] ?? '');
        }
        $response['languages'][$l]['content'] = $fallbackContent;
    }

    if (trim((string) ($current['title'] ?? '')) === '') {
        $fallbackTitle = trim((string) ($en['title'] ?? ''));
        if ($fallbackTitle === '') {
            $fallbackTitle = trim((string) ($zh['title'] ?? ''));
        }
        $response['languages'][$l]['title'] = $fallbackTitle;
    }

    if (trim((string) ($current['summary'] ?? '')) === '') {
        $fallbackSummary = trim((string) ($en['summary'] ?? ''));
        if ($fallbackSummary === '') {
            $fallbackSummary = trim((string) ($zh['summary'] ?? ''));
        }
        $response['languages'][$l]['summary'] = $fallbackSummary;
    }
}

// Set current language details at top level for convenience
if (isset($response['languages'][$lang])) {
    $response['current'] = $response['languages'][$lang];
}

$mysqli->close();

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
