-- بخش 4: سیستم نظرات و تعاملات کاربران
-- -----------------------------------------------------
-- سیستم نظرات با پشتیبانی از سلسله مراتب و انواع مختلف محتوا
-- -----------------------------------------------------
CREATE TABLE `comments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NULL, -- NULL برای نظرات مهمان
    `content_type` VARCHAR(100) NOT NULL, -- نوع محتوا (post, page, product, etc.)
    `content_id` BIGINT UNSIGNED NOT NULL,
    `parent_id` BIGINT UNSIGNED NULL,
    `comment` TEXT NOT NULL,
    `author_name` VARCHAR(100), -- برای نظرات مهمان
    `author_email` VARCHAR(191), -- برای نظرات مهمان
    `author_ip` VARCHAR(45),
    `status` ENUM('pending', 'approved', 'spam', 'trash') DEFAULT 'pending',
    `is_pinned` BOOLEAN DEFAULT FALSE,
    `meta_data` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `comments_user_id_index` (`user_id`),
    KEY `comments_content_index` (`content_type`, `content_id`),
    KEY `comments_parent_id_index` (`parent_id`),
    KEY `comments_status_index` (`status`),
    KEY `comments_created_at_index` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- سیستم امتیازدهی و لایک
-- -----------------------------------------------------
CREATE TABLE `ratings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `content_type` VARCHAR(100) NOT NULL,
    `content_id` BIGINT UNSIGNED NOT NULL,
    `rating` DECIMAL(3,2) NOT NULL,
    `review` TEXT,
    `meta_data` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ratings_user_content_unique` (`user_id`, `content_type`, `content_id`),
    KEY `ratings_content_index` (`content_type`, `content_id`),
    KEY `ratings_created_at_index` (`created_at`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `likes` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `content_type` VARCHAR(100) NOT NULL,
    `content_id` BIGINT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `content_type`, `content_id`),
    KEY `likes_content_index` (`content_type`, `content_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
