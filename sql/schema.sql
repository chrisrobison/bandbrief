CREATE DATABASE IF NOT EXISTS bandbrief
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE bandbrief;

CREATE TABLE IF NOT EXISTS venues (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    city VARCHAR(120) DEFAULT NULL,
    region VARCHAR(120) DEFAULT NULL,
    country VARCHAR(120) DEFAULT NULL,
    capacity INT UNSIGNED DEFAULT NULL,
    website_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_venues_name (name),
    KEY idx_venues_city (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO venues (name, city, region, country, capacity, website_url)
SELECT * FROM (
    SELECT 'Red Rocks Amphitheatre', 'Morrison', 'CO', 'USA', 9525, 'https://www.redrocksonline.com'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM venues WHERE name = 'Red Rocks Amphitheatre' AND city = 'Morrison'
);
