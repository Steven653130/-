import os
import re
import requests

try:
    from scripts.config import ZHIPU_API_KEY
except ImportError:
    from config import ZHIPU_API_KEY

ZHIPU_MODEL = os.getenv("ZHIPU_MODEL", "glm-4-flash")
OPT_TIMEOUT_SECONDS = int(os.getenv("ZHIPU_TIMEOUT_SECONDS", "40"))

NOISE_KEYWORDS = [
    "URL Source", "Markdown Content", "Title:", "分享", "一键分享", "QQ空间", "新浪微博",
    "百度经验", "写经验", "登录", "投诉", "帮助", "返回 顶部", "相关经验", "今日支出",
    "方法/步骤", "工具/原料", "END", "javascript:void", "http://", "https://",
]


def _compact_spaces(s):
    s = re.sub(r'\r\n?', '\n', s or "")
    s = re.sub(r'\n{3,}', '\n\n', s)
    return s.strip()


def clean_scraped_markdown(text):
    s = _compact_spaces(text)
    if s == "":
        return ""

    # Remove known wrappers from Jina output.
    s = re.sub(r'^\s*Title\s*:\s*.*$', '', s, flags=re.I | re.M)
    s = re.sub(r'^\s*URL Source\s*:\s*.*$', '', s, flags=re.I | re.M)
    s = re.sub(r'^\s*Markdown Content\s*:\s*$', '', s, flags=re.I | re.M)

    cleaned_lines = []
    for raw_line in s.split('\n'):
        line = raw_line.strip()
        if line == "":
            continue

        # Drop navigation/share/link-heavy lines.
        if line.startswith("[") and "](" in line:
            continue
        if line.startswith("!") and "](" in line:
            continue
        if line.startswith("* ["):
            continue
        if re.search(r'https?://', line):
            continue
        if line.startswith("###") or line.startswith("######"):
            continue

        hit = False
        for kw in NOISE_KEYWORDS:
            if kw.lower() in line.lower():
                hit = True
                break
        if hit:
            continue

        # Keep readable main-text lines.
        line = re.sub(r'^#{1,6}\s*', '', line)
        line = re.sub(r'^\*+\s*', '', line)
        line = re.sub(r'^\d+\.\s*', '', line)
        line = re.sub(r'\s+', ' ', line).strip()
        if len(line) < 8:
            continue
        cleaned_lines.append(line)

    # Deduplicate near-duplicate lines.
    out = []
    seen = set()
    for line in cleaned_lines:
        key = re.sub(r'\s+', '', line)[:120]
        if key in seen:
            continue
        seen.add(key)
        out.append(line)

    text_out = '\n'.join(out)
    return _compact_spaces(text_out)[:5000]


def _llm_polish_zh(text):
    if not ZHIPU_API_KEY:
        return text

    prompt = (
        "请将以下宠物知识正文整理为高质量中文文章正文。"
        "要求：\n"
        "1) 删除导航、分享、链接、广告、脚注、无关噪声；\n"
        "2) 保留事实，不编造；\n"
        "3) 输出为自然中文段落，约300-1200字；\n"
        "4) 仅输出正文，不要标题、编号、Markdown、说明。\n\n"
        f"原文:\n{text[:3500]}"
    )

    payload = {
        "model": ZHIPU_MODEL,
        "temperature": 0.25,
        "top_p": 0.7,
        "messages": [
            {"role": "system", "content": "你是宠物健康编辑，只输出清洗后的中文正文。"},
            {"role": "user", "content": prompt},
        ],
    }
    headers = {
        "Authorization": f"Bearer {ZHIPU_API_KEY}",
        "Content-Type": "application/json",
    }

    try:
        resp = requests.post(
            "https://open.bigmodel.cn/api/paas/v4/chat/completions",
            headers=headers,
            json=payload,
            timeout=OPT_TIMEOUT_SECONDS,
        )
        resp.raise_for_status()
        data = resp.json()
        content = (
            data.get("choices", [{}])[0]
            .get("message", {})
            .get("content", "")
            .strip()
        )
        if content:
            content = re.sub(r'\s+', ' ', content)
            content = content.replace('。 ', '。\n')
            return _compact_spaces(content)[:5000]
    except Exception as exc:
        print(f"[content-optimizer] llm polish failed: {exc}")

    return text


def optimize_zh_content(raw_text):
    cleaned = clean_scraped_markdown(raw_text)
    if cleaned == "":
        return ""
    # Only call LLM when text still looks long/noisy.
    if len(cleaned) > 260 or '\n' in cleaned:
        polished = _llm_polish_zh(cleaned)
        if polished and len(polished) >= 120:
            return polished
    return cleaned
