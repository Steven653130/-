<?php
/**
 * 一次性管理工具：清洗 petknowledge 数据库里的低质量文章。
 *
 * 用法：
 *   预览（不删除）: GET /cleanup_db.php?secret=YOUR_ADMIN_SECRET
 *   正式删除:       GET /cleanup_db.php?secret=YOUR_ADMIN_SECRET&confirm
 *
 * 前提：在服务器 .env 文件里添加一行  ADMIN_SECRET=你自己设定的密钥
 */

function load_env_file(string $path): void {
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k !== '') {
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
        }
    }
}

load_env_file(__DIR__ . '/.env');

// ── 鉴权 ──────────────────────────────────────────────────────────────────────
$adminSecret = $_ENV['ADMIN_SECRET'] ?? '';
$provided    = $_GET['secret'] ?? '';

if ($adminSecret === '' || !hash_equals($adminSecret, $provided)) {
    http_response_code(403);
    exit('403 Forbidden');
}

// ── 数据库连接 ─────────────────────────────────────────────────────────────────
$mysqli = new mysqli(
    $_ENV['DB_HOST'] ?? '127.0.0.1',
    $_ENV['DB_USER'] ?? 'root',
    $_ENV['DB_PASS'] ?? '',
    $_ENV['DB_NAME'] ?? 'petknowledge'
);
if ($mysqli->connect_error) {
    exit('DB connect failed: ' . $mysqli->connect_error);
}

$dryRun = !array_key_exists('confirm', $_GET);

header('Content-Type: text/plain; charset=utf-8');
echo $dryRun
    ? "=== DRY RUN – 不会删除任何记录。末尾加 &confirm 才正式删除 ===\n\n"
    : "=== LIVE DELETE ===\n\n";

// ── 低质量判定规则 ─────────────────────────────────────────────────────────────
$badPatterns = [
    '404',
    'Not Found',
    '页面没有找到',
    'Warning: Target URL returned error',
    '扫码下载',
    '举报',
    'robots.txt',
    'Access Denied',
    'Forbidden',
    '403 Forbidden',
    '该内容已被屏蔽',
    '服务器异常',
];

$result   = $mysqli->query("SELECT id, url, content_zh FROM articles ORDER BY id ASC");
$toDelete = [];
$kept     = 0;

while ($row = $result->fetch_assoc()) {
    $content = trim($row['content_zh'] ?? '');
    $len     = mb_strlen($content);
    $reason  = null;

    // 1. 内容太短（导航页、无实质内容）
    if ($len < 200) {
        $reason = "太短（{$len} 字）";
    }

    // 2. 包含已知错误/无关标志词
    if (!$reason) {
        foreach ($badPatterns as $p) {
            if (mb_stripos($content, $p) !== false) {
                $reason = "含关键词: {$p}";
                break;
            }
        }
    }

    // 3. 中文字符密度低于 15%（主要是英文页或机器翻译乱码）
    if (!$reason) {
        $zh    = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $content);
        $ratio = $len > 0 ? $zh / $len : 0;
        if ($ratio < 0.15) {
            $reason = sprintf('中文密度仅 %.1f%%', $ratio * 100);
        }
    }

    $url = mb_substr($row['url'] ?? '', 0, 90);

    if ($reason) {
        $toDelete[] = (int) $row['id'];
        echo "[BAD] id={$row['id']} 原因={$reason}\n      url={$url}\n";
    } else {
        $kept++;
        echo "[OK ] id={$row['id']} 长度={$len}\n      url={$url}\n";
    }
}

echo "\n--- 汇总 ---\n";
echo "保留: {$kept} 篇\n";
echo "将删除: " . count($toDelete) . " 篇\n";

if (!$dryRun && count($toDelete) > 0) {
    // IDs already cast to int above, safe to interpolate
    $ids = implode(',', $toDelete);
    $mysqli->query("DELETE FROM articles WHERE id IN ({$ids})");
    echo "已删除: " . $mysqli->affected_rows . " 行\n";
} elseif ($dryRun) {
    echo "(dry run，未做任何变更)\n";
}

$mysqli->close();
?>
