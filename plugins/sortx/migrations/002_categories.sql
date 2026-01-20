-- SortX Plugin Migration 002: Category Schema
-- Creates tables for per-user category and field definitions

CREATE TABLE IF NOT EXISTS sortx_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    category_key VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    description TEXT,
    enabled TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_key (user_id, category_key),
    KEY idx_user_enabled (user_id, enabled),
    FOREIGN KEY (user_id) REFERENCES BUSER(BID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sortx_category_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    field_name VARCHAR(128) NOT NULL,
    field_type VARCHAR(32) NOT NULL COMMENT 'text, date, number, enum, boolean',
    enum_values JSON DEFAULT NULL,
    description TEXT,
    required TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_category_field (category_id, field_key),
    FOREIGN KEY (category_id) REFERENCES sortx_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
