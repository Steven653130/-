import argparse
import re
import time
import pymysql

try:
    from scripts.config import DB_HOST, DB_USER, DB_PASS, DB_NAME
    from scripts.summarizer import generate_zh_card
    from scripts.translator import translate_text
except ImportError:
    from config import DB_HOST, DB_USER, DB_PASS, DB_NAME
    from summarizer import generate_zh_card
    from translator import translate_text

LANGS = ["en", "fr", "es", "ar", "ru"]


def is_empty(value):
    return (value or "").strip() == ""


def clamp_title(text):
    return (text or "").strip()[:180]


def clamp_summary(text):
    return (text or "").strip()[:1200]


def has_chinese(text):
    return bool(re.search(r'[\u4e00-\u9fff]', text or ''))


def has_expected_script(lang, text):
    s = text or ""
    if lang in ("en", "fr", "es"):
        return bool(re.search(r'[A-Za-z]', s))
    if lang == "ru":
        return bool(re.search(r'[\u0400-\u04FF]', s))
    if lang == "ar":
        return bool(re.search(r'[\u0600-\u06FF]', s))
    return True


def is_valid_translation(lang, text):
    s = (text or "").strip()
    if s == "":
        return False
    if has_chinese(s):
        return False
    return has_expected_script(lang, s)


def main():
    parser = argparse.ArgumentParser(description="Backfill llm_title/llm_summary fields")
    parser.add_argument("--limit", type=int, default=50)
    parser.add_argument("--offset", type=int, default=0)
    parser.add_argument("--langs", type=str, default="en,fr,es,ar,ru", help="Comma-separated langs, e.g. en,fr")
    parser.add_argument("--sleep-ms", type=int, default=300, help="Delay between translation calls")
    parser.add_argument("--refresh-zh", action="store_true", help="Regenerate zh title/summary even when already filled")
    parser.add_argument("--force-langs", action="store_true", help="Overwrite selected language fields even when already filled")
    args = parser.parse_args()

    selected_langs = [x.strip() for x in (args.langs or "").split(",") if x.strip() in LANGS]
    if not selected_langs:
        selected_langs = LANGS[:]

    conn = pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )

    sql = (
        "SELECT id, content_zh, llm_title_zh, llm_summary_zh, "
        "llm_title_en, llm_title_fr, llm_title_es, llm_title_ar, llm_title_ru, "
        "llm_summary_en, llm_summary_fr, llm_summary_es, llm_summary_ar, llm_summary_ru "
        "FROM articles ORDER BY id ASC LIMIT %s OFFSET %s"
    )

    checked = 0
    updated = 0

    try:
        with conn.cursor() as cur:
            cur.execute(sql, (args.limit, args.offset))
            rows = cur.fetchall()

            for row in rows:
                checked += 1
                source = (row.get("content_zh") or "").strip()
                if source == "":
                    continue

                updates = {}
                zh_title = (row.get("llm_title_zh") or "").strip()
                zh_summary = (row.get("llm_summary_zh") or "").strip()

                if args.refresh_zh or zh_title == "" or zh_summary == "":
                    card = generate_zh_card(source)
                    zh_title = clamp_title((card.get("title") or "").strip() or source[:28])
                    zh_summary = clamp_summary((card.get("summary") or "").strip() or source[:140])
                    updates["llm_title_zh"] = zh_title
                    updates["llm_summary_zh"] = zh_summary

                # Keep translated summary concise to lower timeout risk in batch mode.
                zh_summary_for_translate = zh_summary[:320]

                for lang in selected_langs:
                    tcol = f"llm_title_{lang}"
                    scol = f"llm_summary_{lang}"
                    if args.force_langs or is_empty(row.get(tcol)):
                        title_out = clamp_title(translate_text(zh_title, lang))
                        if is_valid_translation(lang, title_out):
                            updates[tcol] = title_out
                        else:
                            print(f"[backfill-card] skip invalid title row={row['id']} lang={lang}")
                        if args.sleep_ms > 0:
                            time.sleep(args.sleep_ms / 1000.0)
                    if args.force_langs or is_empty(row.get(scol)):
                        summary_out = clamp_summary(translate_text(zh_summary_for_translate, lang))
                        if is_valid_translation(lang, summary_out):
                            updates[scol] = summary_out
                        else:
                            print(f"[backfill-card] skip invalid summary row={row['id']} lang={lang}")
                        if args.sleep_ms > 0:
                            time.sleep(args.sleep_ms / 1000.0)

                if not updates:
                    continue

                set_clause = ", ".join([f"{k}=%s" for k in updates.keys()])
                params = list(updates.values()) + [row["id"]]
                cur.execute(f"UPDATE articles SET {set_clause} WHERE id=%s", params)
                updated += 1

        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()

    print(f"[backfill-card] checked_rows={checked} updated_rows={updated}")


if __name__ == "__main__":
    main()
