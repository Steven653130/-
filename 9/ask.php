<?php
function load_env_file(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '') {
            continue;
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function sse_send(string $text): void {
    $lines = preg_split("/\r\n|\r|\n/", $text);
    foreach ($lines as $line) {
        echo 'data: ' . $line . "\n";
    }
    echo "\n";
    flush();
}

// == 文本清洗 ==================================================================

function normalize_text(string $text): string {
    // 去掉图片 markdown
    $text = preg_replace('/!\[[^\]]*\]\([^\)]*\)/u', ' ', $text);
    // markdown 链接：保留链接文字
    $text = preg_replace('/\[([^\]]*)\]\([^\)]*\)/u', '$1', $text);
    // 裸 URL
    $text = preg_replace('/https?:\/\/\S+/u', ' ', $text);
    // Jina reader 头部字段
    $text = preg_replace('/^(Title|URL Source|Markdown Content|Published Time):\s*/mu', ' ', $text);
    // 去掉 AI 翻译前缀 [zh] [en] 等，保留正文
    $text = preg_replace('/\[(?:zh|en|ru|ja|fr|de)\]\s*/u', '', $text);
    // markdown 标题符号
    $text = preg_replace('/^#{1,6}\s+/mu', '', $text);
    // 合并空白
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function is_low_quality(string $text): bool {
    if (mb_strlen($text) < 150) {
        return true;
    }
    $badMarkers = [
        '404', 'Not Found', '页面没有找到',
        'Warning: Target URL returned error',
        '扫码下载', 'Access Denied', 'Forbidden',
    ];
    foreach ($badMarkers as $marker) {
        if (mb_stripos($text, $marker) !== false) {
            return true;
        }
    }
    // 中文字符占比 < 15% → 非中文或乱码
    $zh = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text);
    return ($zh / mb_strlen($text)) < 0.15;
}

// == 相似度检索：字符二元组余弦相似度 ==========================================

/**
 * 将文本转换为字符二元组（bigram）频率向量。
 * 只保留 CJK 汉字、假名、字母、数字，忽略标点空格。
 */
function get_bigrams(string $text): array {
    $text  = mb_strtolower($text);
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $chars = array_values(array_filter(
        $chars,
        static fn($c) => (bool) preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}A-Za-z0-9]/u', $c)
    ));
    $bigrams = [];
    for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
        $k = $chars[$i] . $chars[$i + 1];
        $bigrams[$k] = ($bigrams[$k] ?? 0) + 1;
    }
    return $bigrams;
}

/**
 * 两个 bigram 频率向量之间的余弦相似度，取值 [0, 1]。
 */
function cosine_sim(array $a, array $b): float {
    if (empty($a) || empty($b)) {
        return 0.0;
    }
    $dot = $na = $nb = 0.0;
    foreach ($a as $k => $v) {
        if (isset($b[$k])) {
            $dot += $v * $b[$k];
        }
        $na += $v * $v;
    }
    foreach ($b as $v) {
        $nb += $v * $v;
    }
    if ($na == 0.0 || $nb == 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($na) * sqrt($nb));
}

// == 工具函数 ==================================================================

function split_sentences(string $text): array {
    $parts = preg_split('/(?<=[。！？!?；;])\s*/u', $text);
    return array_values(array_filter(
        array_map('trim', $parts ?: []),
        static fn($s) => mb_strlen($s) >= 10
    ));
}

/**
 * 判断句子是否为机器翻译乱码或非中文内容。
 */
function is_garbled(string $s): bool {
    $zh = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $s);
    return mb_strlen($s) > 15 && ($zh / mb_strlen($s)) < 0.25;
}

function has_column(mysqli $mysqli, string $table, string $column): bool {
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
    $res = $mysqli->query($sql);
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function source_label(array $item): string {
    $url = trim((string) ($item['url'] ?? ''));
    $title = trim((string) ($item['title'] ?? ''));
    $domain = trim((string) ($item['domain'] ?? ''));

    if ($url !== '') {
        return $url;
    }
    if ($title !== '') {
        return $title;
    }
    if ($domain !== '') {
        return $domain;
    }
    return '未知来源';
}

function clean_url_for_output(string $url): string {
    $url = trim($url);
    // 清理常见尾部噪音，避免出现 ")" 或中文括号残留
    $url = rtrim($url, ")）]】,，。;； \"'“”‘’");
    return $url;
}

function clean_snippet_for_output(string $snippet): string {
    $snippet = trim($snippet);
    // 句子被截断时，去掉句尾悬空左括号（含被引号包裹的情况），避免出现“（\n出处：...”。
    $snippet = preg_replace('/[\s\"\'“”‘’]*[（\(\[\{【<]+[\s\"\'“”‘’]*$/u', '', $snippet);
    return trim($snippet);
}

function format_citations(array $items, int $maxItems = 3): array {
    $citations = [];
    $seen = [];

    foreach ($items as $item) {
        $label = source_label($item);
        $snippet = trim((string) ($item['text'] ?? ''));
        if ($snippet === '') {
            continue;
        }

        // 同一来源只保留一条，避免 [来源1]/[来源2] 指向同一 URL
        if (isset($seen[$label])) {
            continue;
        }
        $seen[$label] = true;

        $citations[] = [
            'snippet' => clean_snippet_for_output(mb_substr($snippet, 0, 100)),
            'url' => clean_url_for_output((string) ($item['url'] ?? '')),
            'fallback' => $label,
        ];

        if (count($citations) >= $maxItems) {
            break;
        }
    }

    if (empty($citations)) {
        $citations[] = [
            'snippet' => '无可用摘录',
            'url' => '',
            'fallback' => '无来源信息',
        ];
    }

    return $citations;
}

function render_answer_with_citations(string $answer, array $citations): string {
    $cleanAnswer = trim($answer);
    $cleanAnswer = preg_replace('/^\s*回答：\s*/u', '', $cleanAnswer);
    $cleanAnswer = preg_replace('/^\s*回答：\s*/u', '', $cleanAnswer);
    $cleanAnswer = preg_replace('/\s*来源编号：.*$/u', '', $cleanAnswer);

    $lines = ["回答：{$cleanAnswer}", '出处：'];
    foreach ($citations as $item) {
        $snippet = str_replace(["\n", "\r"], ' ', $item['snippet']);
        $snippet = clean_snippet_for_output($snippet);
        $url = str_replace(["\n", "\r"], ' ', $item['url']);
        $url = clean_url_for_output($url);
        $fallback = str_replace(["\n", "\r"], ' ', $item['fallback']);

        if ($url !== '') {
            $lines[] = "{$snippet}（来源：{$url}）";
        } elseif ($fallback !== '') {
            $lines[] = "{$snippet} - {$fallback}";
        } else {
            $lines[] = $snippet;
        }
    }
    return implode("\n", $lines);
}

function build_fallback_answer(array $selected): string {
    $top = array_slice($selected, 0, 2);
    $answer = implode('', array_map(static fn($x) => $x['text'], $top));
    $citations = format_citations($top, 2);
    return render_answer_with_citations($answer, $citations);
}

function build_user_prompt(string $question, array $contexts): string {
    $parts = [];
    foreach ($contexts as $idx => $ctx) {
        $n = $idx + 1;
        $label = source_label($ctx);
        $parts[] = "[来源{$n}] 来源={$label} 片段=" . $ctx['text'];
    }
    $contextText = implode("\n", $parts);

    return "问题：{$question}\n\n可用证据（仅可基于这些内容回答）：\n{$contextText}\n\n请只输出一段“回答”，不要输出“来源编号”、不要编号、不要列表、不要多余字段。";
}

function stream_llm_answer(string $question, array $contexts, string $apiKey, string $model): bool {
    if (!function_exists('curl_init')) {
        return false;
    }

    $url = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
    $payload = [
        'model' => $model,
        'stream' => true,
        'temperature' => 0.1,
        'top_p' => 0.7,
        'messages' => [
            [
                'role' => 'system',
                'content' => '你是宠物知识问答助手。只允许基于证据回答，禁止编造。输出只包含“回答：...”一段，不要来源编号，不要编号列表。'
            ],
            [
                'role' => 'user',
                'content' => build_user_prompt($question, $contexts)
            ]
        ]
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }

    $buffer = '';
    $emitted = false;
    $fullText = '';

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'Accept: text/event-stream'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($curl, $chunk) use (&$buffer, &$emitted, &$fullText) {
        $buffer .= $chunk;

        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $pos));
            $buffer = substr($buffer, $pos + 1);

            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            $json = json_decode($data, true);
            if (!is_array($json)) {
                continue;
            }

            $piece = '';
            if (isset($json['choices'][0]['delta']['content']) && is_string($json['choices'][0]['delta']['content'])) {
                $piece = $json['choices'][0]['delta']['content'];
            } elseif (isset($json['choices'][0]['message']['content']) && is_string($json['choices'][0]['message']['content'])) {
                $piece = $json['choices'][0]['message']['content'];
            } elseif (isset($json['output_text']) && is_string($json['output_text'])) {
                $piece = $json['output_text'];
            }

            if ($piece !== '') {
                // Defensive cleanup: remove accidental SSE marker text from model chunk.
                $piece = preg_replace('/\bdata:\s*/iu', '', $piece);
                // Avoid numbered list artifacts like "1)" / "2)" / "2）".
                $piece = preg_replace('/\b\d+[\)）]\s*/u', '', $piece);
                $fullText .= $piece;
            }
        }

        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    $normalized = trim($fullText);
    if ($normalized === '') {
        return false;
    }

    // Final normalization to enforce stable output contract.
    $normalized = preg_replace('/\bdata:\s*/iu', '', $normalized);
    $normalized = preg_replace('/\b\d+[\)）]\s*/u', '', $normalized);
    $normalized = preg_replace('/[ \t]+/u', ' ', $normalized);

    $answer = '';
    $sourceIdsText = '';
    if (preg_match('/回答：\s*(.+?)(?:来源编号：|依据：|$)/u', $normalized, $m)) {
        $answer = trim($m[1]);
    }
    if (preg_match('/来源编号：\s*(.+)$/u', $normalized, $m)) {
        $sourceIdsText = trim($m[1]);
    } elseif (preg_match('/依据：\s*(.+)$/u', $normalized, $m)) {
        $sourceIdsText = trim($m[1]);
    }

    if ($answer === '') {
        $answer = $normalized;
    }

    $ids = [];
    if ($sourceIdsText !== '' && $sourceIdsText !== '无') {
        if (preg_match_all('/\[(?:来源|证据)\s*(\d+)\]/u', $sourceIdsText, $mm)) {
            $ids = array_map('intval', $mm[1]);
        }
    }
    if (empty($ids)) {
        // fallback to top-2 contexts when model omits source ids
        $ids = [1, 2];
    }

    $picked = [];
    $used = [];
    foreach ($ids as $id) {
        if ($id < 1 || $id > count($contexts) || isset($used[$id])) {
            continue;
        }
        $used[$id] = true;
        $picked[] = $contexts[$id - 1];
        if (count($picked) >= 3) {
            break;
        }
    }

    if (empty($picked)) {
        $picked = array_slice($contexts, 0, 2);
    }

    $citations = format_citations($picked, 3);
    $final = render_answer_with_citations($answer, $citations);
    sse_send($final);
    $emitted = true;

    return $emitted;
}

// == 主流程 ====================================================================

load_env_file(__DIR__ . '/.env');

session_start();
if (empty($_SESSION['user'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$data     = json_decode(file_get_contents('php://input'), true);
$question = trim($data['question'] ?? '');

if ($question === '') {
    sse_send('请输入问题。');
    exit;
}

$mysqli = new mysqli(
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_USER'] ?? 'root',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? 'petknowledge'
);
if ($mysqli->connect_error) {
    sse_send('问答服务暂时不可用，请稍后再试。');
    exit;
}

$hasTitleColumn = has_column($mysqli, 'articles', 'title');
$hasDomainColumn = has_column($mysqli, 'articles', 'domain');

$titleSelect = $hasTitleColumn ? 'title' : "'' AS title";
$domainSelect = $hasDomainColumn ? 'domain' : "'' AS domain";
$sql = "SELECT url, {$titleSelect}, {$domainSelect}, content_zh FROM articles ORDER BY created_at DESC LIMIT 30";
$result = $mysqli->query($sql);
$mysqli->close();

// 问题的 bigram 向量
$qVec = get_bigrams($question);

// 对每篇文章的每个句子计算余弦相似度
$candidates = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $content = normalize_text($row['content_zh'] ?? '');
        if ($content === '' || is_low_quality($content)) {
            continue;
        }
        foreach (split_sentences($content) as $sentence) {
            if (is_garbled($sentence)) {
                continue;
            }
            $score = cosine_sim($qVec, get_bigrams($sentence));
            if ($score > 0.04) {
                $candidates[] = [
                    'text' => $sentence,
                    'score' => $score,
                    'url' => trim((string) ($row['url'] ?? '')),
                    'title' => trim((string) ($row['title'] ?? '')),
                    'domain' => trim((string) ($row['domain'] ?? '')),
                ];
            }
        }
    }
}

// 按相似度降序排列
usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);

$topScore = $candidates[0]['score'] ?? 0.0;

// 按句子前 20 字去重，先取前 6 句作为 RAG 上下文
$seen     = [];
$selected = [];
foreach ($candidates as $c) {
    $key = mb_substr($c['text'], 0, 20);
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;
    $selected[] = [
        'text' => $c['text'],
        'url' => $c['url'],
        'title' => $c['title'],
        'domain' => $c['domain'],
    ];
    if (count($selected) >= 6) {
        break;
    }
}

if (empty($selected)) {
    sse_send('当前知识库中暂未找到与该问题直接相关的内容，请尝试换一种表达方式。');
} else {
    // 证据门槛：避免在弱相关数据上让模型“编格式凑答案”
    if (count($selected) < 2 || $topScore < 0.08) {
        sse_send("回答：证据不足，无法给出确定结论。\n出处：\n- 无可用摘录 @@URL= @@FALLBACK=无来源信息");
        exit;
    }

    $apiKey = trim($_ENV['ZHIPU_API_KEY'] ?? '');
    $model = trim($_ENV['ZHIPU_MODEL'] ?? 'glm-4-flash');

    if ($apiKey !== '' && stream_llm_answer($question, $selected, $apiKey, $model)) {
        exit;
    }

    // 大模型流式失败或未配置 key 时，回退到抽取式答案
    sse_send(build_fallback_answer($selected));
}
?>