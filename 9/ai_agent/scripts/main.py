import time
import schedule
try:
    from scripts.scraper import search_pet_articles, extract_content
    from scripts.translator import translate_to_all_languages, translate_text
    from scripts.embedder import generate_embedding
    from scripts.summarizer import generate_zh_card
    from scripts.content_optimizer import optimize_zh_content
    from scripts.pusher import push_to_db
except ImportError:
    from scraper import search_pet_articles, extract_content
    from translator import translate_to_all_languages, translate_text
    from embedder import generate_embedding
    from summarizer import generate_zh_card
    from content_optimizer import optimize_zh_content
    from pusher import push_to_db


def clamp_title(text):
    value = (text or '').strip()
    return value[:180]


def clamp_summary(text):
    value = (text or '').strip()
    return value[:1200]

def daily_task():
    print("[agent] daily_task started")
    articles = search_pet_articles()
    if not articles:
        print("[agent] No URLs found this run")
        return

    for article in articles:
        url = (article or {}).get('url', '')
        image_url = (article or {}).get('image_url', '')
        if not url:
            continue

        print(f"[agent] Processing: {url}")
        extracted = extract_content(url, image_url)
        content = extracted.get('content', '') if isinstance(extracted, dict) else ''
        final_image_url = extracted.get('image_url', image_url) if isinstance(extracted, dict) else image_url

        if content:
            try:
                zh_source = optimize_zh_content(content)
                if zh_source == '':
                    zh_source = (content or '').strip()[:2000]

                translations = translate_to_all_languages(zh_source)
                embedding = generate_embedding(zh_source)

                card_zh = generate_zh_card(zh_source)
                card_title_zh = (card_zh.get('title') or '').strip()
                card_summary_zh = (card_zh.get('summary') or '').strip()

                if card_title_zh == '':
                    card_title_zh = zh_source[:28]
                if card_summary_zh == '':
                    card_summary_zh = zh_source[:140]

                card_title_zh = clamp_title(card_title_zh)
                card_summary_zh = clamp_summary(card_summary_zh)

                card_translations = {
                    'zh': {'title': card_title_zh, 'summary': card_summary_zh},
                    'en': {'title': clamp_title(translate_text(card_title_zh, 'en')), 'summary': clamp_summary(translate_text(card_summary_zh, 'en'))},
                    'fr': {'title': clamp_title(translate_text(card_title_zh, 'fr')), 'summary': clamp_summary(translate_text(card_summary_zh, 'fr'))},
                    'es': {'title': clamp_title(translate_text(card_title_zh, 'es')), 'summary': clamp_summary(translate_text(card_summary_zh, 'es'))},
                    'ar': {'title': clamp_title(translate_text(card_title_zh, 'ar')), 'summary': clamp_summary(translate_text(card_summary_zh, 'ar'))},
                    'ru': {'title': clamp_title(translate_text(card_title_zh, 'ru')), 'summary': clamp_summary(translate_text(card_summary_zh, 'ru'))},
                }

                ok = push_to_db(url, translations, embedding, final_image_url, card_translations)
                print(f"[agent] push_to_db={'ok' if ok else 'failed'}")
            except Exception as exc:
                print(f"[agent] Failed processing URL {url}: {exc}")
        else:
            print(f"[agent] Empty content from: {url}")

    print("[agent] daily_task finished")

# Schedule daily at 2 AM
schedule.every().day.at("02:00").do(daily_task)

if __name__ == "__main__":
    print("[agent] scheduler started, waiting for 02:00 job")
    while True:
        schedule.run_pending()
        time.sleep(60)  # Check every minute