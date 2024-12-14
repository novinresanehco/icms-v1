-- بخش 5: متادیتا و تنظیمات تکمیلی
-- -----------------------------------------------------
-- سیستم متا برای افزودن فیلدهای سفارشی به هر نوع محتوا
-- -----------------------------------------------------
CREATE TABLE `meta` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `meta_key` VARCHAR(191) NOT NULL,
    `meta_value` LONGTEXT,
    `meta_type` VARCHAR(20) DEFAULT 'string',
    `content_type` VARCHAR(100) NOT NULL,
    `content_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `meta_content_key_unique` (`content_type`, `content_id`, `meta_key`),
    KEY `meta_key_index` (`meta_key`),
    KEY `meta_content_index` (`content_type`, `content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- سیستم ردیابی و آمار
-- -----------------------------------------------------
CREATE TABLE `visits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `content_type` VARCHAR(100) NOT NULL,
    `content_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `session_id` VARCHAR(100),
    `ip_address` VARCHAR(45),
    `user_agent` VARCHAR(255),
    `referer` VARCHAR(500),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `visits_content_index` (`content_type`, `content_id`),
    KEY `visits_user_id_index` (`user_id`),
    KEY `visits_created_at_index` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- سیستم اعلان‌ها
-- -----------------------------------------------------
CREATE TABLE `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(191) NOT NULL,
    `notifiable_type` VARCHAR(191) NOT NULL,
    `notifiable_id` BIGINT UNSIGNED NOT NULL,
    `data` JSON NOT NULL,
    `read_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `notifications_notifiable_index` (`notifiable_type`, `notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- سیستم کش داینامیک
-- -----------------------------------------------------
CREATE TABLE `cache` (
    `key` VARCHAR(191) NOT NULL,
    `value` LONGTEXT NOT NULL,
    `expiration` INT NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- سیستم کارهای زمان‌بندی شده
-- -----------------------------------------------------
CREATE TABLE `scheduled_tasks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(191) NOT NULL,
    `command` VARCHAR(191) NOT NULL,
    `parameters` JSON,
    `expression` VARCHAR(191) NOT NULL,
    `timezone` VARCHAR(191) DEFAULT 'UTC',
    `is_active` BOOLEAN DEFAULT TRUE,
    `without_overlapping` BOOLEAN DEFAULT FALSE,
    `run_in_maintenance` BOOLEAN DEFAULT FALSE,
    `last_run_at` TIMESTAMP NULL,
    `next_run_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `scheduled_tasks_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
