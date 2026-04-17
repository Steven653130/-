import argparse
import time
import re
import pymysql

try:
    from scripts.config import DB_HOST, DB_USER, DB_PASS, DB_NAME
    from scripts.translator import translate_text
    from scripts.content_optimizer import optimize_zh_content
except ImportError:
    from config import DB_HOST, DB_USER, DB_PASS, DB_NAME
    from translator import translate_text
    from content_optimizer import optimize_zh_content

TARGET_LANGS = ["en", "fr", "es", "ar", "ru"]


def clean_for_translation(text):
    s = (text or "").strip()
    s = re.sub(r'\[(?:zh|en|fr|es|ar|ru)\]\s*', '', s, flags=re.I)
    s = re.sub(r'\b(?:Title|URL Source|Markdown Content|Published Time):\s*', ' ', s, flags=re.I)
    s = re.sub(r'!\[[^\]]*\]\([^\)]*\)', ' ', s)
    s = re.sub(r'\[([^\]]*)\]\([^\)]*\)', r'\1', s)
    s = re.sub(r'https?://\S+', ' ', s)
    s = re.sub(r'\|[^\n]*\|', ' ', s)
    s = re.sub(r'\s+', ' ', s)
    # Keep backfill translation payload modest to reduce length-related truncation.
    return s[:1200].strip()


def needs_backfill(value, lang_code):
    text = (value or "").strip()
    if text == "":
        return True
    if text.lower().startswith(f"[{lang_code}]"):
        return True
    # If target-language field is still mostly Chinese, treat as stale fallback and retranslate.
    if lang_code != "zh":
        total = len(text)
        if total > 0:
            zh_chars = len(re.findall(r'[\u4e00-\u9fff]', text))
            if zh_chars / total > 0.35:
                return True
    return False


def looks_like_failed_translation(source_zh, translated_text, lang_code):
    src = (source_zh or "").strip()
    out = (translated_text or "").strip()
    if out == "":
        return True
    if out.lower().startswith(f"[{lang_code}]"):
        return True
    # In this pipeline, identical source/target usually means fallback after API failure.
    if out == src:
        return True

    if lang_code in ("en", "fr", "es") and not re.search(r'[A-Za-z]', out):
        return True
    if lang_code == "ru" and not re.search(r'[\u0400-\u04FF]', out):
        return True
    if lang_code == "ar" and not re.search(r'[\u0600-\u06FF]', out):
        return True

    total = len(out)
    if total > 0:
        zh_chars = len(re.findall(r'[\u4e00-\u9fff]', out))
        zh_ratio = zh_chars / total
        if lang_code in ("en", "fr", "es") and zh_ratio > 0.12:
            return True
        if lang_code in ("ar", "ru") and zh_ratio > 0.05:
            return True

    return False


def parse_id_list(value):
    raw = (value or "").strip()
    if raw == "":
        return []
    out = []
    for part in raw.split(','):
        item = part.strip()
        if item.isdigit():
            out.append(int(item))
    return out


def row_has_bad_lang_value(row, lang_code):
    value = (row.get(f"content_{lang_code}") or "").strip()
    source_zh = (row.get("content_zh") or "").strip()
    return needs_backfill(value, lang_code) or looks_like_failed_translation(source_zh, value, lang_code)


def main():
    parser = argparse.ArgumentParser(description="Backfill multilingual translations for existing articles")
    parser.add_argument("--limit", type=int, default=20, help="Max rows to process in one run")
    parser.add_argument("--offset", type=int, default=0, help="Offset for pagination")
    parser.add_argument("--sleep-ms", type=int, default=300, help="Delay between language calls")
    parser.add_argument("--rewrite-zh", action="store_true", help="Rewrite content_zh with optimized正文")
    parser.add_argument("--force-langs", action="store_true", help="Force rewrite target language fields")
    parser.add_argument("--langs", type=str, default="en,fr,es,ar,ru", help="Comma-separated langs, use empty string to skip translations")
    parser.add_argument("--ids", type=str, default="", help="Comma-separated article IDs to process")
    parser.add_argument("--only-bad", action="store_true", help="Only retry rows whose selected language fields still look bad")
    args = parser.parse_args()

    selected_langs = []
    raw_langs = (args.langs or "").strip()
    if raw_langs != "":
        selected_langs = [x.strip() for x in raw_langs.split(",") if x.strip() in TARGET_LANGS]
    selected_ids = parse_id_list(args.ids)

    conn = pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )

    if selected_ids:
        placeholders = ",".join(["%s"] * len(selected_ids))
        select_sql = (
            "SELECT id, content_zh, content_en, content_fr, content_es, content_ar, content_ru "
            f"FROM articles WHERE id IN ({placeholders}) ORDER BY id ASC"
        )
        select_params = selected_ids
    else:
        select_sql = (
            "SELECT id, content_zh, content_en, content_fr, content_es, content_ar, content_ru "
            "FROM articles ORDER BY id ASC LIMIT %s OFFSET %s"
        )
        select_params = [args.limit, args.offset]

    updated_rows = 0
    checked_rows = 0

    try:
        with conn.cursor() as cur:
            cur.execute(select_sql, select_params)
            rows = cur.fetchall()

            for row in rows:
                checked_rows += 1
                if args.only_bad and selected_langs:
                    bad_for_any = any(row_has_bad_lang_value(row, lang) for lang in selected_langs)
                    if not bad_for_any:
                        print(f"[backfill] row={row['id']} skipped_not_bad")
                        continue

                raw_zh = row.get("content_zh") or ""
                zh_optimized = ""
                if args.rewrite_zh:
                    zh_optimized = optimize_zh_content(raw_zh)
                zh_source = zh_optimized or raw_zh
                zh = clean_for_translation(zh_source)
                if zh == "":
                    continue

                updates = {}
                if args.rewrite_zh and zh_optimized and zh_optimized != (raw_zh or ""):
                    updates["content_zh"] = zh_optimized

                for lang in selected_langs:
                    col = f"content_{lang}"
                    if args.force_langs or needs_backfill(row.get(col), lang):
                        print(f"[backfill] translating row={row['id']} lang={lang}")
                        translated = translate_text(zh, lang)
                        if looks_like_failed_translation(zh, translated, lang):
                            print(f"[backfill] skip failed row={row['id']} lang={lang}")
                        else:
                            updates[col] = translated
                        if args.sleep_ms > 0:
                            time.sleep(args.sleep_ms / 1000.0)

                if not updates:
                    print(f"[backfill] row={row['id']} no changes")
                    continue

                set_clause = ", ".join([f"{k}=%s" for k in updates.keys()])
                params = list(updates.values()) + [row["id"]]
                update_sql = f"UPDATE articles SET {set_clause} WHERE id=%s"
                cur.execute(update_sql, params)
                updated_rows += 1
                print(f"[backfill] row={row['id']} updated_fields={','.join(updates.keys())}")

        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

    print(f"[backfill] checked_rows={checked_rows} updated_rows={updated_rows}")


if __name__ == "__main__":
    main()
