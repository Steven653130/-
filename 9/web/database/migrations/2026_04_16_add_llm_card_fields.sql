USE petknowledge;

ALTER TABLE articles
ADD COLUMN llm_title_zh VARCHAR(300) DEFAULT '' AFTER content_ru,
ADD COLUMN llm_title_en VARCHAR(300) DEFAULT '' AFTER llm_title_zh,
ADD COLUMN llm_title_fr VARCHAR(300) DEFAULT '' AFTER llm_title_en,
ADD COLUMN llm_title_es VARCHAR(300) DEFAULT '' AFTER llm_title_fr,
ADD COLUMN llm_title_ar VARCHAR(300) DEFAULT '' AFTER llm_title_es,
ADD COLUMN llm_title_ru VARCHAR(300) DEFAULT '' AFTER llm_title_ar,
ADD COLUMN llm_summary_zh TEXT AFTER llm_title_ru,
ADD COLUMN llm_summary_en TEXT AFTER llm_summary_zh,
ADD COLUMN llm_summary_fr TEXT AFTER llm_summary_en,
ADD COLUMN llm_summary_es TEXT AFTER llm_summary_fr,
ADD COLUMN llm_summary_ar TEXT AFTER llm_summary_es,
ADD COLUMN llm_summary_ru TEXT AFTER llm_summary_ar;
