PRIMARY KEY (`id`),
    KEY `media_user_id_index` (`user_id`),
    KEY `media_created_at_index` (`created_at`),
    KEY `media_mime_type_index` (`mime_type`),
    KEY `media_status_index` (`status`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- نسخه‌های مختلف رسانه (مثل سایزهای مختلف تصاویر)
-- -----------------------------------------------------
CREATE TABLE `media_variations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `media_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL, -- مثل thumbnail, medium, large
    `filename` VARCHAR(255) NOT NULL,
    `path` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `size` BIGINT UNSIGNED NOT NULL,
    `dimensions` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `media_variations_media_id_index` (`media_id`),
    FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- رابطه رسانه با سایر محتواها
-- -----------------------------------------------------
CREATE TABLE `media_relationships` (
    `media_id` BIGINT UNSIGNED NOT NULL,
    `related_type` VARCHAR(100) NOT NULL,
    `related_id` BIGINT UNSIGNED NOT NULL,
    `collection` VARCHAR(100) DEFAULT NULL,
    `order` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`media_id`, `related_type`, `related_id`, `collection`),
    KEY `media_relationships_related_index` (`related_type`, `related_id`),
    FOREIGN KEY (`media_id`) REFERENCES `media` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
