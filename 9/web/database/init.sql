
USE petknowledge;

CREATE TABLE articles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(500) NOT NULL,
    image_url VARCHAR(1000) DEFAULT '',
    content_zh TEXT,
    content_en TEXT,
    content_fr TEXT,
    content_es TEXT,
    content_ar TEXT,
    content_ru TEXT,
    llm_title_zh VARCHAR(300) DEFAULT '',
    llm_title_en VARCHAR(300) DEFAULT '',
    llm_title_fr VARCHAR(300) DEFAULT '',
    llm_title_es VARCHAR(300) DEFAULT '',
    llm_title_ar VARCHAR(300) DEFAULT '',
    llm_title_ru VARCHAR(300) DEFAULT '',
    llm_summary_zh TEXT,
    llm_summary_en TEXT,
    llm_summary_fr TEXT,
    llm_summary_es TEXT,
    llm_summary_ar TEXT,
    llm_summary_ru TEXT,
    embedding JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    google_id VARCHAR(255) UNIQUE,
    email VARCHAR(255),
    name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    question TEXT,
    answer TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);