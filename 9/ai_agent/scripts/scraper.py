import requests
import re
try:
    from scripts.config import BRAVE_API_KEY, JINA_API_KEY
except ImportError:
    from config import BRAVE_API_KEY, JINA_API_KEY


def _safe_str(value):
    return value.strip() if isinstance(value, str) else ""


def _extract_image_from_search_item(item):
    if not isinstance(item, dict):
        return ""

    direct_keys = ["thumbnail", "image", "image_url"]
    for key in direct_keys:
        value = item.get(key)
        if isinstance(value, str) and value.startswith(("http://", "https://")):
            return value

    thumb_obj = item.get("thumbnail")
    if isinstance(thumb_obj, dict):
        for key in ["src", "url"]:
            value = thumb_obj.get(key)
            if isinstance(value, str) and value.startswith(("http://", "https://")):
                return value

    return ""


def _extract_first_markdown_image(markdown_text):
    if not isinstance(markdown_text, str) or markdown_text == "":
        return ""

    match = re.search(r'!\[[^\]]*\]\((https?://[^\s\)]+)', markdown_text)
    if match:
        return match.group(1).strip()

    return ""

def search_pet_articles():
    url = "https://api.search.brave.com/res/v1/web/search"
    headers = {
        "Accept": "application/json",
        "Accept-Encoding": "gzip",
        "X-Subscription-Token": BRAVE_API_KEY
    }
    params = {
        "q": "宠物 饲养 健康 知识",
        "count": 10
    }
    try:
        response = requests.get(url, headers=headers, params=params, timeout=20)
    except requests.RequestException as exc:
        print(f"[scraper] Brave request failed: {exc}")
        return []

    if response.status_code == 200:
        payload = response.json()
        results = payload.get('web', {}).get('results', [])
        if not isinstance(results, list):
            print(f"[scraper] Unexpected Brave response structure: {payload}")
            return []
        articles = []
        for item in results:
            if not isinstance(item, dict):
                continue
            url = _safe_str(item.get('url'))
            if not url:
                continue
            articles.append({
                'url': url,
                'title': _safe_str(item.get('title')),
                'image_url': _extract_image_from_search_item(item),
            })

        print(f"[scraper] Found {len(articles)} candidate URLs")
        return articles

    print(f"[scraper] Brave API status={response.status_code}, body={response.text[:300]}")
    return []

def extract_content(url, fallback_image_url=""):
    headers = {
        "Authorization": f"Bearer {JINA_API_KEY}",
        "X-Return-Format": "markdown"
    }
    try:
        response = requests.get(f"https://r.jina.ai/{url}", headers=headers, timeout=25)
    except requests.RequestException as exc:
        print(f"[scraper] Jina request failed for {url}: {exc}")
        return {
            'content': "",
            'image_url': _safe_str(fallback_image_url),
        }

    if response.status_code == 200:
        text = response.text
        extracted_image = _extract_first_markdown_image(text)
        return {
            'content': text,
            'image_url': extracted_image or _safe_str(fallback_image_url),
        }
    print(f"[scraper] Jina status={response.status_code} for {url}")
    return {
        'content': "",
        'image_url': _safe_str(fallback_image_url),
    }