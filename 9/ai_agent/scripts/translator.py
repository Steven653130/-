import os
import time
import re
import requests
try:
    from scripts.config import ZHIPU_API_KEY, LANGUAGES
except ImportError:
    from config import ZHIPU_API_KEY, LANGUAGES

ZHIPU_MODEL = os.getenv("ZHIPU_MODEL", "glm-4-flash")
ZHIPU_TIMEOUT_SECONDS = int(os.getenv("ZHIPU_TIMEOUT_SECONDS", "60"))
ZHIPU_MAX_RETRIES = int(os.getenv("ZHIPU_MAX_RETRIES", "4"))
ZHIPU_RETRY_BASE_SECONDS = float(os.getenv("ZHIPU_RETRY_BASE_SECONDS", "2"))
ZHIPU_MAX_INPUT_CHARS = int(os.getenv("ZHIPU_MAX_INPUT_CHARS", "1200"))
ZHIPU_CHUNK_CHARS = int(os.getenv("ZHIPU_CHUNK_CHARS", "320"))
ENABLE_REMOTE_TRANSLATION = (
    os.getenv("ENABLE_REMOTE_TRANSLATION", "1") == "1" and bool((ZHIPU_API_KEY or "").strip())
)

LANG_NAME_MAP = {
    "en": "English",
    "fr": "French",
    "es": "Spanish",
    "ar": "Arabic",
    "ru": "Russian",
}


def _extract_text_from_response(data):
    if not isinstance(data, dict):
        return ""

    # Newer schema shortcut
    output_text = data.get("output_text")
    if isinstance(output_text, str) and output_text.strip():
        return output_text.strip()

    choices = data.get("choices")
    if isinstance(choices, list) and choices:
        c0 = choices[0] if isinstance(choices[0], dict) else {}

        # Some SDKs return plain content directly under choice
        c0_content = c0.get("content")
        if isinstance(c0_content, str) and c0_content.strip():
            return c0_content.strip()

        message = c0.get("message") if isinstance(c0.get("message"), dict) else {}
        msg_content = message.get("content")
        if isinstance(msg_content, str) and msg_content.strip():
            return msg_content.strip()

        # Some responses put result in reasoning_content with a final 'Translation:' block.
        reasoning_content = message.get("reasoning_content")
        if isinstance(reasoning_content, str) and reasoning_content.strip():
            m = re.search(r'(?:^|\n)(?:Translation|译文|翻译)\s*[:：]\s*(.+)$', reasoning_content, re.I | re.S)
            if m:
                return m.group(1).strip()

        # Some models return content as blocks [{type,text}, ...]
        if isinstance(msg_content, list):
            parts = []
            for block in msg_content:
                if isinstance(block, dict):
                    text = block.get("text")
                    if isinstance(text, str) and text.strip():
                        parts.append(text.strip())
            if parts:
                return "\n".join(parts).strip()

        delta = c0.get("delta") if isinstance(c0.get("delta"), dict) else {}
        delta_content = delta.get("content")
        if isinstance(delta_content, str) and delta_content.strip():
            return delta_content.strip()

    return ""


def _call_zhipu_translate(source_text, target_lang):
    url = "https://open.bigmodel.cn/api/paas/v4/chat/completions"
    target_name = LANG_NAME_MAP.get(target_lang, target_lang)
    headers = {
        "Authorization": f"Bearer {ZHIPU_API_KEY}",
        "Content-Type": "application/json",
    }

    prompt_prefix = (
        "Task: translate Chinese pet-knowledge text to "
        f"{target_name}.\n"
        "Rules:\n"
        "1) Output ONLY the translated text.\n"
        "2) No analysis, no bullet points, no headings, no explanation.\n"
        "3) Keep facts unchanged.\n"
        "4) Keep output concise and complete within 600 words.\n\n"
    )

    last_exc = None
    working_text = source_text
    for attempt in range(1, ZHIPU_MAX_RETRIES + 1):
        try:
            payload = {
                "model": ZHIPU_MODEL,
                "temperature": 0.2,
                "top_p": 0.7,
                "max_tokens": 600,
                "messages": [
                    {"role": "system", "content": "You are a professional translator for pet knowledge content."},
                    {"role": "user", "content": prompt_prefix + working_text},
                ],
            }
            response = requests.post(url, json=payload, headers=headers, timeout=ZHIPU_TIMEOUT_SECONDS)
            response.raise_for_status()
            data = response.json()
            text = _extract_text_from_response(data)
            if text:
                return text

            # Help diagnose model/schema mismatches in production logs.
            top_keys = list(data.keys())[:8] if isinstance(data, dict) else []
            preview = str(data)[:500]
            print(f"[translator] empty content; keys={top_keys}; preview={preview}")
            raise RuntimeError("empty translation content")
        except Exception as exc:
            last_exc = exc
            status_code = getattr(getattr(exc, "response", None), "status_code", None)
            if status_code == 400 and getattr(exc, "response", None) is not None:
                body = (exc.response.text or "")[:400]
                print(f"[translator] 400 body ({target_lang}): {body}")
                if len(working_text) > 600:
                    working_text = working_text[: int(len(working_text) * 0.7)]
                    print(f"[translator] shrink input for {target_lang} to {len(working_text)} chars")

            if attempt >= ZHIPU_MAX_RETRIES:
                break
            sleep_seconds = ZHIPU_RETRY_BASE_SECONDS * (2 ** (attempt - 1))
            print(
                f"[translator] retry {attempt}/{ZHIPU_MAX_RETRIES} for {target_lang} after error: {exc}"
            )
            time.sleep(sleep_seconds)

    raise RuntimeError(f"translation failed after retries: {last_exc}")


def _split_text_for_translation(text, max_chunk_chars):
    s = (text or "").strip()
    if s == "":
        return []
    if len(s) <= max_chunk_chars:
        return [s]

    # Python 3.6: avoid zero-width split patterns that can raise ValueError.
    raw_parts = re.split(r'([。！？!?；;\n]+)', s)
    parts = []
    i = 0
    while i < len(raw_parts):
        seg = (raw_parts[i] or '').strip()
        delim = (raw_parts[i + 1] if i + 1 < len(raw_parts) else '')
        merged = (seg + delim).strip()
        if merged:
            parts.append(merged)
        i += 2

    if not parts:
        parts = [s]

    chunks = []
    buf = ""
    for part in parts:
        p = part.strip()
        if p == "":
            continue
        if len(p) > max_chunk_chars:
            if buf:
                chunks.append(buf)
                buf = ""
            start = 0
            while start < len(p):
                chunks.append(p[start:start + max_chunk_chars])
                start += max_chunk_chars
            continue

        if len(buf) + len(p) + 1 <= max_chunk_chars:
            buf = (buf + " " + p).strip()
        else:
            if buf:
                chunks.append(buf)
            buf = p
    if buf:
        chunks.append(buf)
    return chunks


def _chinese_ratio(text):
    s = (text or "").strip()
    if s == "":
        return 0.0
    zh = len(re.findall(r'[\u4e00-\u9fff]', s))
    return zh / max(1, len(s))


def _has_expected_script(text, lang):
    if lang in ("en", "fr", "es"):
        return bool(re.search(r'[A-Za-z]', text or ""))
    if lang == "ru":
        return bool(re.search(r'[\u0400-\u04FF]', text or ""))
    if lang == "ar":
        return bool(re.search(r'[\u0600-\u06FF]', text or ""))
    return True


def _valid_translation(text, target_lang):
    out = (text or "").strip()
    if out == "":
        return False
    if not _has_expected_script(out, target_lang):
        return False

    ratio = _chinese_ratio(out)
    if target_lang in ("en", "fr", "es") and ratio > 0.12:
        return False
    if target_lang in ("ar", "ru") and ratio > 0.05:
        return False
    return True


def _sanitize_translated_output(text):
    s = (text or "").strip()
    if s == "":
        return ""

    s = re.sub(r'^\s*\[(?:zh|en|fr|es|ar|ru)\]\s*', '', s, flags=re.I)
    s = re.sub(r'\b(?:Title|URL Source|Markdown Content|Published Time)\s*:\s*', ' ', s, flags=re.I)
    s = re.sub(r'!\[[^\]]*\]\([^\)]*\)', ' ', s)
    s = re.sub(r'\[([^\]]*)\]\([^\)]*\)', r'\1', s)
    s = re.sub(r'https?://\S+', ' ', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s


def translate_text(text, target_lang):
    if target_lang == "zh":
        return text

    if not ENABLE_REMOTE_TRANSLATION:
        return ""

    source_text = str(text or "").strip()
    if source_text == "":
        return ""

    # Keep upper bound for total request budget, then translate in chunks.
    source_text = source_text[: max(600, ZHIPU_MAX_INPUT_CHARS)]
    chunks = _split_text_for_translation(source_text, max(180, ZHIPU_CHUNK_CHARS))
    if not chunks:
        return ""

    translated_chunks = []
    for chunk in chunks:
        try:
            out = _call_zhipu_translate(chunk, target_lang)
        except Exception as exc:
            print(f"[translator] Remote translation failed for {target_lang}: {exc}")
            return ""
        out = _sanitize_translated_output(out)
        if not _valid_translation(out, target_lang):
            print(f"[translator] Invalid translated output for {target_lang}, dropped")
            return ""
        translated_chunks.append(out.strip())

    return "\n".join([x for x in translated_chunks if x]).strip()

def translate_to_all_languages(text):
    translations = {}
    for lang in LANGUAGES:
        print(f"[translator] translating -> {lang}")
        translations[lang] = translate_text(text, lang)
    return translations