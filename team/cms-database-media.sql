-- بخش 3: سیستم مدیریت رسانه و فایل‌ها
-- این بخش شامل مدیریت فایل‌ها، تصاویر و گالری است

-- -----------------------------------------------------
-- مدیریت رسانه
-- -----------------------------------------------------
CREATE TABLE `media` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `extension` VARCHAR(20) NOT NULL,
    `size` BIGINT UNSIGNED NOT NULL,
    `path` VARCHAR(500) NOT NULL,
    `disk` VARCHAR(50) DEFAULT 'local',
    `title` VARCHAR(255),
    `description` TEXT,
    `alt_text` VARCHAR(255),
    `meta_data` JSON,
    `dimensions` JSON, -- برای تصاویر
    `duration` INT UNSIGNED, -- برای ویدیو و صوت
    `status` ENUM('processing', 'ready', 'error') DEFAULT 'ready',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY