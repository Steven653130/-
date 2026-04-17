USE petknowledge;

ALTER TABLE articles
ADD COLUMN image_url VARCHAR(1000) DEFAULT '' AFTER url;
