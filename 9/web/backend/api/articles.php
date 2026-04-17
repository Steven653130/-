<?php
require_once '../config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lang = strtolower(trim($_GET['lang'] ?? 'zh'));
$limit = (int) ($_GET['limit'] ?? 12);
$q = trim((string) ($_GET['q'] ?? ''));

if ($q !== '') {
    $q = mb_substr($q, 0, 80);
}

$allowedLangs = ['zh', 'en', 'fr', 'es', 'ar', 'ru'];
if (!in_array($lang, $allowedLangs, true)) {
    $lang = 'zh';
}

if ($limit < 1) {
    $limit = 1;
}
if ($limit > 30) {
    $limit = 30;
}

// Query a larger pool then apply quality filters in PHP,
// to avoid returning empty feeds after strict filtering.
$candidateLimit = $q !== ''
    ? min(800, max($limit * 45, 180))
    : min(300, max($limit * 15, 60));

$cacheRoot = dirname(__DIR__) . '/cache';
$feedCacheDir = $cacheRoot . '/feed';
$feedCacheTtl = (int) ($_ENV['ARTICLES_FEED_CACHE_TTL'] ?? 180);
if ($feedCacheTtl < 30) {
    $feedCacheTtl = 30;
}
if ($feedCacheTtl > 1800) {
    $feedCacheTtl = 1800;
}

$ensureDir = static function (string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
};

$readJsonCache = static function (string $path, int $ttl = 0): ?array {
    if (!is_file($path)) {
        return null;
    }
    if ($ttl > 0) {
        $mtime = @filemtime($path);
        if (!is_int($mtime) || (time() - $mtime) > $ttl) {
            return null;
        }
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $obj = json_decode($raw, true);
    return is_array($obj) ? $obj : null;
};

$writeJsonCache = static function (string $path, array $data) use ($ensureDir): void {
    $ensureDir(dirname($path));
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

$ensureDir($feedCacheDir);

$feedCacheKey = sha1("{$lang}|{$limit}|{$q}");
$feedCachePath = $feedCacheDir . '/' . $feedCacheKey . '.json';
$cachedFeed = $readJsonCache($feedCachePath, $feedCacheTtl);
if (is_array($cachedFeed) && isset($cachedFeed['items']) && is_array($cachedFeed['items'])) {
    echo json_encode([
        'lang' => $lang,
        'query' => $q,
        'count' => count($cachedFeed['items']),
        'items' => $cachedFeed['items'],
        'cached' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$langColumn = 'content_' . $lang;

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "SELECT id, url, image_url, {$langColumn} AS localized_content, content_zh,
    llm_title_{$lang} AS llm_title,
    llm_summary_{$lang} AS llm_summary,
    created_at
        FROM articles
        ORDER BY created_at DESC
        LIMIT ?";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error' => 'Prepare failed: ' . $mysqli->error], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt->bind_param('i', $candidateLimit);
if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(['error' => 'Execute failed: ' . $stmt->error], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = $stmt->get_result();
$items = [];

$petKeywords = [
    '宠物', '犬', '狗', '猫', '饲养', '喂养', '营养', '健康', '行为', '训练', '护理',
    '疫苗', '免疫', '驱虫', '绝育', '皮肤', '肠胃', '口腔', '寄生虫', '应激',
    'pet', 'dog', 'cat', 'feeding', 'health', 'training', 'vaccine', 'parasite',
];

$noiseMarkers = [
    '课程大纲', '在线教程', '章节简介', '教学计划', '登录后可预览', '分享到QQ好友', 'void(0)',
    '责任编辑', '稿件来源', '扫一扫', '打印 关闭', '相关文档', '关联链接', '卫生健康委员会',
    '首页', '课程', '教材', '开课平台', '开课高校',
];

$cleanText = static function (string $text): string {
    $text = trim($text);
    // Remove noisy wrappers from scraper/Jina output.
    $text = preg_replace('/\[(?:zh|en|fr|es|ar|ru)\]\s*/iu', '', $text);
    $text = preg_replace('/\b(?:Title|URL Source|Markdown Content|Published Time):\s*/iu', ' ', $text);
    $text = preg_replace('/!\[[^\]]*\]\([^\)]*\)/u', ' ', $text);
    $text = preg_replace('/\[([^\]]*)\]\([^\)]*\)/u', '$1', $text);
    $text = preg_replace('/https?:\/\/\S+/u', ' ', $text);
    $text = preg_replace('/\|[^\n]*\|/u', ' ', $text);
    $text = preg_replace('/\s*\*\s*/u', ' ', $text);
    $text = preg_replace('/^#{1,6}\s*/mu', '', $text);
    // Remove common site navigation blocks that are irrelevant to article body.
    $text = preg_replace('/(?:首页|课程|教材|虚仿实验|教师教研|研究生教育|创课平台|课外成长|专题|慕课西部行|资讯|事务通知|动态新闻|咨询须知|心理百科|咨询流派|咨询案例|心理辅导站|心理协会)(?:\s+(?:首页|课程|教材|虚仿实验|教师教研|研究生教育|创课平台|课外成长|专题|慕课西部行|资讯|事务通知|动态新闻|咨询须知|心理百科|咨询流派|咨询案例|心理辅导站|心理协会|农学|动物生产类|开课平台|开课高校)){2,}/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
};

$extractTranslatedOnly = static function (string $text): string {
    $s = trim($text);
    if ($s === '') {
        return '';
    }

    // Keep only the translated part when model output contains
    // patterns like: 原文 ... Translation: 译文
    $patterns = [
        '/(?:^|\s)(?:Translation|Translated Text|译文|翻译)\s*[:：]\s*(.+)$/uis',
        '/"\s*(?:Translation|译文|翻译)\s*[:：]\s*"\s*(.+)$/uis',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $s, $m)) {
            $candidate = trim((string) ($m[1] ?? ''));
            if ($candidate !== '') {
                $s = $candidate;
                break;
            }
        }
    }

    // Remove leading quoted source fragments that sometimes remain
    // before translated output.
    $s = preg_replace('/^["“”\']+\s*/u', '', $s);
    $s = preg_replace('/\s*["“”\']+$/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
};

$isLowQuality = static function (string $text): bool {
    if ($text === '' || mb_strlen($text) < 40) {
        return true;
    }
    $badMarkers = [
        '页面没有找到', '404', 'Not Found',
        'Warning: Target URL returned error',
        'This page maybe not yet fully loaded',
        '安全验证', 'CAPTCHA', '请您登录后查看更多',
        'you are authorized to access this page',
        'jobs@zhihu.com', '验证你不是机器人',
        'Access Denied', 'Forbidden',
    ];
    foreach ($badMarkers as $marker) {
        if (mb_stripos($text, $marker) !== false) {
            return true;
        }
    }
    return false;
};

$containsNoise = static function (string $text) use ($noiseMarkers): bool {
    foreach ($noiseMarkers as $marker) {
        if (mb_stripos($text, $marker) !== false) {
            return true;
        }
    }
    return false;
};

$countNoiseHits = static function (string $text) use ($noiseMarkers): int {
    $hits = 0;
    foreach ($noiseMarkers as $marker) {
        if (mb_stripos($text, $marker) !== false) {
            $hits++;
        }
    }
    return $hits;
};

$isPetRelevant = static function (string $text) use ($petKeywords): bool {
    foreach ($petKeywords as $kw) {
        if (mb_stripos($text, $kw) !== false) {
            return true;
        }
    }
    return false;
};

$isMostlyChinese = static function (string $text): bool {
    $s = trim($text);
    if ($s === '') {
        return false;
    }
    $total = mb_strlen($s);
    if ($total === 0) {
        return false;
    }
    $zh = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $s);
    return ($zh / $total) > 0.60;
};

$hasChineseChars = static function (string $text): bool {
    return (bool) preg_match('/[\x{4e00}-\x{9fff}]/u', $text);
};

$hasExpectedScript = static function (string $text, string $lang): bool {
    if ($lang === 'en' || $lang === 'fr' || $lang === 'es') {
        return (bool) preg_match('/[A-Za-z]/u', $text);
    }
    if ($lang === 'ru') {
        return (bool) preg_match('/[\x{0400}-\x{04FF}]/u', $text);
    }
    if ($lang === 'ar') {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    }
    return true;
};

$looksUntranslatedOutput = static function (string $text): bool {
    if ($text === '') {
        return true;
    }
    if (preg_match('/(?:^|\s)(?:Translation|Translated Text|译文|翻译)\s*[:：]/iu', $text)) {
        return true;
    }
    return false;
};

$buildStructuredText = static function (string $content) use ($petKeywords): array {
    $sentences = preg_split('/(?<=[。！？!?;；])\s*/u', $content);
    $candidates = [];

    foreach ($sentences as $sentence) {
        $s = trim((string) $sentence);
        if ($s === '' || mb_strlen($s) < 14) {
            continue;
        }

        $score = 0;
        foreach ($petKeywords as $kw) {
            if (mb_stripos($s, $kw) !== false) {
                $score += 2;
            }
        }
        if (preg_match('/(课程|平台|首页|开课|高校|登录|导航)/u', $s)) {
            $score -= 2;
        }

        $score += min(3, (int) floor(mb_strlen($s) / 35));
        $candidates[] = ['text' => $s, 'score' => $score];
    }

    if (empty($candidates)) {
        return ['title' => '', 'summary' => '', 'display' => ''];
    }

    usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);
    $best = array_slice($candidates, 0, 3);

    // If all sentences are low-score generic UI text, treat as unusable.
    if (($best[0]['score'] ?? -99) < 1) {
        return ['title' => '', 'summary' => '', 'display' => ''];
    }

    $title = $best[0]['text'];
    $title = preg_replace('/^[#\-\s]+/u', '', $title);
    if (mb_strlen($title) > 34) {
        $title = mb_substr($title, 0, 34) . '...';
    }

    $summaryParts = [];
    foreach ($best as $row) {
        $summaryParts[] = $row['text'];
        if (count($summaryParts) >= 2) {
            break;
        }
    }
    $summary = implode(' ', $summaryParts);
    if (mb_strlen($summary) > 180) {
        $summary = mb_substr($summary, 0, 180) . '...';
    }

    $display = implode(' ', array_map(static fn($x) => $x['text'], $best));
    if (mb_strlen($display) > 260) {
        $display = mb_substr($display, 0, 260) . '...';
    }

    return [
        'title' => trim($title),
        'summary' => trim($summary),
        'display' => trim($display),
    ];
};

$makeTitle = static function (string $content): string {
    $parts = preg_split('/(?<=[。！？!?;；])\s*/u', $content);
    $title = trim((string) ($parts[0] ?? ''));
    if ($title === '') {
        $title = mb_substr($content, 0, 28);
    }
    if (mb_strlen($title) > 36) {
        $title = mb_substr($title, 0, 36) . '...';
    }
    return $title;
};

$queryTokens = [];
if ($q !== '') {
    $queryTokens = array_values(array_filter(
        preg_split('/\s+/u', mb_strtolower($q)) ?: [],
        static fn($token) => $token !== ''
    ));
}

$matchesQuery = static function (string $haystack, string $query, array $tokens): bool {
    if ($query === '') {
        return true;
    }
    $text = mb_strtolower($haystack);
    if (mb_stripos($text, mb_strtolower($query)) !== false) {
        return true;
    }
    if (empty($tokens)) {
        return false;
    }
    foreach ($tokens as $token) {
        if (mb_stripos($text, $token) === false) {
            return false;
        }
    }
    return true;
};

while ($row = $result->fetch_assoc()) {
    $localized = trim((string) ($row['localized_content'] ?? ''));
    $fallbackZh = trim((string) ($row['content_zh'] ?? ''));
    $zhContent = $cleanText($fallbackZh);
    if ($isLowQuality($zhContent) || !$isPetRelevant($zhContent)) {
        continue;
    }

    $zhNoiseHits = $countNoiseHits($zhContent);
    // Hard reject rows that look like footer/sidebar/navigation dumps.
    if ($zhNoiseHits >= 2) {
        continue;
    }
    if ($containsNoise($zhContent) && mb_strlen($zhContent) < 260) {
        continue;
    }

    $rawContent = $zhContent;
    if ($lang !== 'zh') {
        // Non-zh feeds must use their own localized content to keep language correctness.
        if ($localized === '') {
            continue;
        }
        $rawContent = $extractTranslatedOnly($localized);
    }

    $content = $cleanText($rawContent);
    if ($isLowQuality($content)) {
        continue;
    }
    $contentNoiseHits = $countNoiseHits($content);
    if ($contentNoiseHits >= 2) {
        continue;
    }
    if ($containsNoise($content) && !$isPetRelevant($content)) {
        continue;
    }
    if ($lang !== 'zh') {
        // Drop rows that are still mostly Chinese or still carry translation markers.
        if ($isMostlyChinese($content) || $looksUntranslatedOutput($content)) {
            continue;
        }
        // Non-zh cards should not include Chinese fragments.
        if ($hasChineseChars($content)) {
            continue;
        }
        if (!$hasExpectedScript($content, $lang)) {
            continue;
        }
    }

    $url = trim((string) ($row['url'] ?? ''));
    $structured = [
        'title' => trim((string) ($row['llm_title'] ?? '')),
        'summary' => trim((string) ($row['llm_summary'] ?? '')),
        'display' => '',
    ];
    if ($structured['title'] === '' || $structured['summary'] === '') {
        $structured = $buildStructuredText($content);
    }
    if (($structured['title'] ?? '') === '' || ($structured['summary'] ?? '') === '') {
        continue;
    }

    if (!$matchesQuery(
        implode("\n", [
            (string) ($structured['title'] ?? ''),
            (string) ($structured['summary'] ?? ''),
            $content,
            $url,
        ]),
        $q,
        $queryTokens
    )) {
        continue;
    }

    $savedImage = trim((string) ($row['image_url'] ?? ''));
    $image = $savedImage !== ''
        ? $savedImage
        : ($url !== ''
        ? 'https://image.thum.io/get/width/960/noanimate/' . rawurlencode($url)
        : 'https://placehold.co/800x450?text=Pet+Knowledge');

    $items[] = [
        'id' => (int)($row['id'] ?? 0),
        'title' => $structured['title'] ?: $makeTitle($content),
        'excerpt' => $structured['summary'] ?: mb_substr($content, 0, 180),
        'displayText' => $structured['display'] ?: mb_substr($content, 0, 220),
        'url' => $url,
        'image' => $image,
        'publishedAt' => $row['created_at'] ?? '',
        'lang' => $lang,
    ];

    if (count($items) >= $limit) {
        break;
    }
}

// Deduplicate feed cards caused by repeated crawling/upserts.
$seen = [];
$deduped = [];
foreach ($items as $item) {
    $urlKey = mb_strtolower(trim((string) ($item['url'] ?? '')));
    $titleKey = preg_replace('/\s+/u', '', mb_strtolower(trim((string) ($item['title'] ?? ''))));
    $excerptKey = preg_replace('/\s+/u', '', mb_strtolower(trim((string) ($item['excerpt'] ?? ''))));
    $key = $urlKey !== '' ? "url::{$urlKey}" : "tx::{$titleKey}::{$excerptKey}";
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    $deduped[] = $item;
}

$items = $deduped;

$writeJsonCache($feedCachePath, [
    'lang' => $lang,
    'query' => $q,
    'items' => $items,
    'updated_at' => date('c'),
]);

$stmt->close();
$mysqli->close();

echo json_encode([
    'lang' => $lang,
    'query' => $q,
    'count' => count($items),
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
