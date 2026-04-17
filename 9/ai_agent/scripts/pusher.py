import requests
import json
import hmac
import hashlib
try:
    from scripts.config import HMAC_SECRET, WEB_BASE_URL
except ImportError:
    from config import HMAC_SECRET, WEB_BASE_URL

def encode_data(data):
    return json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def sign_data(data):
    return hmac.new(HMAC_SECRET.encode(), encode_data(data), hashlib.sha256).hexdigest()

def push_to_db(url, translations, embedding, image_url="", card_translations=None):
    data = {
        'url': url,
        'translations': translations,
        'embedding': embedding,
        'image_url': image_url,
        'card_translations': card_translations or {},
    }
    signature = sign_data(data)
    headers = {
        'Content-Type': 'application/json',
        'X-Signature': signature
    }
    url = f"{WEB_BASE_URL.rstrip('/')}/web/backend/api/push_data.php"
    try:
        response = requests.post(url, data=encode_data(data), headers=headers, timeout=20)
    except requests.RequestException as exc:
        print(f"[pusher] Request failed: {exc}")
        return False

    if response.status_code != 200:
        print(f"[pusher] status={response.status_code}, body={response.text[:300]}")
    return response.status_code == 200