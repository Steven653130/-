# Multilingual Chinese Pet Knowledge Aggregation and Intelligent Q&A Platform

This platform aggregates pet knowledge from various sources, translates it into multiple languages, and provides an AI-powered Q&A system.

## Architecture

- **Web System**: Native PHP 8 backend with MySQL 8, responsive HTML/CSS/JS frontend using Material Design 3.
- **AI Agent Pipeline**: Python 3 daemon for daily data scraping, translation, and embedding generation.

## Features

- Daily automated scraping of pet knowledge articles using Brave Search and Jina Reader API.
- Multi-language translation and localization using Zhipu GLM-4 model (Chinese, English, French, Spanish, Arabic, Russian).
- News-style knowledge feed: display scraped pet knowledge as cards with cover images and source links.
- Multi-language content browsing in the feed with language switch (zh/en/fr/es/ar/ru).
- Vector embeddings for RAG-based Q&A.
- Secure API with HMAC-SHA256 signatures.
- Google OAuth login for foreign users.
- Streaming SSE responses for AI answers.
- Real cover image pipeline: image URL is extracted in scraping and stored in DB for feed rendering.
- Offline LLM card pipeline: title/summary are generated in the AI agent and stored in DB (multilingual), frontend reads directly without per-request LLM generation.

## Setup

### Prerequisites

- PHP 8
- MySQL 8
- Python 3.8+
- Brave Search API key
- Jina Reader API key
- Zhipu GLM-4 API key
- Google OAuth credentials

### Installation

1. Clone the repository.
2. Set up MySQL database using `web/database/init.sql`.
3. Configure environment variables in `web/backend/config.php` and `ai_agent/scripts/config.py`.
4. Install Python dependencies: `pip install -r ai_agent/requirements.txt`.
5. Run the web server (e.g., using Apache or Nginx).
6. Start the AI agent daemon: `python ai_agent/scripts/main.py`.

### Existing Deployment Migration

If your site is already deployed, run this migration once:

1. Open your database console in BT panel.
2. Execute SQL from `web/database/migrations/2026_04_16_add_image_url.sql`.
3. Confirm `articles` table has `image_url` column.

Then run card-fields migration:

1. Execute SQL from `web/database/migrations/2026_04_16_add_llm_card_fields.sql`.
2. Confirm `articles` table has `llm_title_*` and `llm_summary_*` columns.

### Enable Real Multi-language Translation

Set these variables in your `.env` (server side):

- `ZHIPU_API_KEY=your_real_key`
- `ENABLE_REMOTE_TRANSLATION=1`
- `ZHIPU_MODEL=glm-4-flash` (optional)

After updating `.env`, restart the AI agent process.

### Backfill Existing Old Rows (Important)

If existing rows still contain placeholder translations like `[en] ...`, run:

1. `cd ai_agent`
2. `pip3 install -r requirements.txt`
3. `python3 scripts/backfill_translations.py --limit 50 --offset 0`

Repeat with larger offsets if needed (e.g. 50, 100, 150...).

Backfill card title/summary fields for existing rows:

1. `cd ai_agent`
2. `python3 scripts/backfill_card_fields.py --limit 50 --offset 0`
3. Repeat with larger offsets (50, 100, 150...).

## Usage

- Access the web interface at the configured URL.
- In the "宠物知识资讯" section, switch languages to read translated knowledge cards and open source pages.
- Use the AI Ask module for questions after logging in with Google.

## API Endpoints

- `POST /ask.php`: AI Q&A streaming (SSE).
- `POST /web/backend/api/push_data.php`: Signed ingestion endpoint for AI agent pipeline.
- `GET /web/backend/api/articles.php?lang=zh&limit=12`: Latest multilingual knowledge feed data.

## BT Panel Upload And Test Steps

When local edits are complete, upload these files to your server project path:

- `ai_agent/scripts/scraper.py`
- `ai_agent/scripts/main.py`
- `ai_agent/scripts/pusher.py`
- `web/backend/api/push_data.php`
- `web/backend/api/articles.php`
- `web/database/migrations/2026_04_16_add_image_url.sql`
- `web/database/init.sql` (only for fresh installs)
- `web/frontend/index.html`
- `web/frontend/script.js`
- `web/frontend/styles.css`
- `README.md`

Then execute:

1. Run migration SQL (`2026_04_16_add_image_url.sql`) in your production database.
2. Reload Nginx and restart PHP-FPM in BT panel.
3. Restart AI agent process so scraper/pusher updates take effect.
4. Open your domain and verify:
	- Knowledge feed cards load.
	- Language switch shows translated text.
	- New incoming articles display real images where available.

## License

[Add license here]