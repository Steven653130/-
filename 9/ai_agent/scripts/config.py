import os
from dotenv import load_dotenv

# Load environment variables from deterministic locations instead of relying on cwd.
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
AI_AGENT_ROOT = os.path.abspath(os.path.join(SCRIPT_DIR, '..'))
PROJECT_ROOT = os.path.abspath(os.path.join(AI_AGENT_ROOT, '..'))


def _load_env_files():
	candidates = [
		os.path.join(PROJECT_ROOT, '.env'),
		os.path.join(AI_AGENT_ROOT, '.env'),
		os.path.join(os.getcwd(), '.env'),
	]
	for env_path in candidates:
		if os.path.exists(env_path):
			load_dotenv(env_path, override=False)


_load_env_files()

# API Keys
BRAVE_API_KEY = os.getenv('BRAVE_API_KEY')
JINA_API_KEY = os.getenv('JINA_API_KEY')
ZHIPU_API_KEY = os.getenv('ZHIPU_API_KEY')
GOOGLE_CLIENT_ID = os.getenv('GOOGLE_CLIENT_ID')
GOOGLE_CLIENT_SECRET = os.getenv('GOOGLE_CLIENT_SECRET')

# Database
DB_HOST = os.getenv('DB_HOST', 'localhost')
DB_USER = os.getenv('DB_USER', 'root')
DB_PASS = os.getenv('DB_PASS', '')
DB_NAME = os.getenv('DB_NAME', 'pet_knowledge')

# Languages
LANGUAGES = ['zh', 'en', 'fr', 'es', 'ar', 'ru']

# HMAC Secret
HMAC_SECRET = os.getenv('HMAC_SECRET')

# Web API base URL for pushing processed content.
# Example: http://127.0.0.1 or https://petqaa.com
WEB_BASE_URL = os.getenv('WEB_BASE_URL', 'http://127.0.0.1')