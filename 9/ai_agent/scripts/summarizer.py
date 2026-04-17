import os
import json
import re
import requests

try:
    from scripts.config import ZHIPU_API_KEY
except ImportError:
    from config import ZHIPU_API_KEY

ZHIPU_MODEL = os.getenv("ZHIPU_MODEL", "glm-4-flash")


def _strip_scrape_noise(text):
    s = (text or "").strip()
    if s == "":
        return ""
    s = re.sub(r'^\s*Title\s*:\s*.*$', ' ', s, flags=re.I | re.M)
    s = re.sub(r'^\s*URL Source\s*:\s*.*$', ' ', s, flags=re.I | re.M)
    s = re.sub(r'^\s*Published Time\s*:\s*.*$', ' ', s, flags=re.I | re.M)
    s = re.sub(r'^\s*Markdown Content\s*:\s*$', ' ', s, flags=re.I | re.M)
    s = re.sub(r'!\[[^\]]*\]\([^\)]*\)', ' ', s)
    s = re.sub(r'\[([^\]]*)\]\([^\)]*\)', r'\1', s)
    s = re.sub(r'https?://\S+', ' ', s)
    s = re.sub(r'^#{1,6}\s*', '', s, flags=re.M)
    s = re.sub(r'\|[^\n]*\|', ' ', s)
    s = re.sub(r'\s+', ' ', s)
    return s.strip()


def _clean_text(text):
    s = _strip_scrape_noise(text)
    s = re.sub(r'\s+', ' ', s)
    s = re.sub(r'^(?:标题|题目|摘要|内容)\s*[:：]\s*', '', s)
    return s.strip()


def _normalize_title(text):
    s = _clean_text(text)
    s = re.sub(r'[。；;！!？?]+$', '', s)
    if len(s) > 32:
        s = s[:31].rstrip() + '…'
    if len(s) < 12:
        s = (s + '：核心要点')[:24]
    return s


def _normalize_summary(text):
    s = _clean_text(text)
    if len(s) > 220:
        s = s[:220].rstrip('，,。;； ') + '。'
    return s


def _extract_sentences(text):
    s = _clean_text(text)
    if s == "":
        return []
    parts = re.split(r'(?<=[。！？!?])\s+|(?<=[。！？!?])', s)
    out = []
    for p in parts:
        t = p.strip()
        if len(t) >= 8:
            out.append(t)
    return out


def _fallback_card(text):
    sentences = _extract_sentences(text)
    if not sentences:
        base = _clean_text(text)
        title = _normalize_title(base[:24] or '宠物知识：实用指南')
        summary = _normalize_summary(base[:140] or '围绕宠物健康、行为与日常照护给出实用建议，帮助主人降低风险并提升陪伴质量。')
        return {"title": title, "summary": summary}

    first = sentences[0]
    title = _normalize_title(first)
    summary_seed = ''.join(sentences[:3])
    if len(summary_seed) < 90 and len(sentences) > 3:
        summary_seed += sentences[3]
    summary = _normalize_summary(summary_seed)

    if len(summary) < 60:
        summary = _normalize_summary(
            summary + ' 研究显示，稳定陪伴、规律护理与及时干预能显著提升宠物及主人的长期福祉。'
        )
    return {"title": title, "summary": summary}


def _extract_json_obj(content):
    s = (content or '').strip()
    if s == '':
        return None
    if s.startswith('```'):
        s = re.sub(r'^```(?:json)?\s*', '', s)
        s = re.sub(r'\s*```$', '', s)
    if '{' in s and '}' in s:
        s = s[s.find('{'):s.rfind('}') + 1]
    try:
        return json.loads(s)
    except Exception:
        return None


def generate_zh_card(text):
    source = _strip_scrape_noise(text)
    if source == "":
        return {"title": "", "summary": ""}
    if not ZHIPU_API_KEY:
        return _fallback_card(source)

    source = source[:2200]
    prompt = (
        "你是一名中文宠物健康栏目主编。"
        "请基于给定内容产出高质量资讯卡片，要求信息密度高、可读性强、避免空话。"
        "只输出JSON，不要输出任何解释。"
        "JSON格式固定为: {\"title\":\"...\",\"summary\":\"...\"}。"
        "标题要求：14-28字，尽量使用“主题：结论/价值”结构，具体且不夸张。"
        "摘要要求：80-180字，覆盖关键结论、作用机制或适用场景，语言自然流畅，不改变事实。"
        "禁止：模板化口号、与原文无关的信息、英文输出。\n\n"
        f"内容:\n{source}"
    )

    payload = {
        "model": ZHIPU_MODEL,
        "temperature": 0.35,
        "top_p": 0.7,
        "messages": [
            {"role": "system", "content": "你是宠物知识编辑助手，只输出JSON。"},
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
            timeout=30,
        )
        resp.raise_for_status()
        data = resp.json()
        content = (
            data.get("choices", [{}])[0]
            .get("message", {})
            .get("content", "")
            .strip()
        )
        obj = _extract_json_obj(content)
        if not isinstance(obj, dict):
            return _fallback_card(source)

        title = _normalize_title(str(obj.get("title", "")))
        summary = _normalize_summary(str(obj.get("summary", "")))
        if title == '' or summary == '' or len(summary) < 50:
            return _fallback_card(source)
        return {"title": title, "summary": summary}
    except Exception as exc:
        print(f"[summarizer] failed: {exc}")
        return _fallback_card(source)
