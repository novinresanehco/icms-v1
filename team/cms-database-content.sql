-- بخش 2: سیستم مدیریت محتوا
-- این بخش شامل جداول مربوط به محتوا، دسته‌بندی‌ها، تگ‌ها و مدیا است

-- -----------------------------------------------------
-- مدیریت محتوا با پشتیبانی از انواع مختلف محتوا
-- -----------------------------------------------------
CREATE TABLE `content_types` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `fields` JSON,
    `is_system` BOOLEAN DEFAULT FALSE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `content_types_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `slug` VARCHAR(191) NOT NULL,
    `excerpt` TEXT,
    `content` LONGTEXT,
    `meta_data` JSON,
    `featured_image` VARCHAR(255),
    `status` ENUM('draft', 'published', 'scheduled', 'private', 'archived') NOT NULL DEFAULT 'draft',
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `contents_slug_unique` (`slug`),
    KEY `contents_user_id_index` (`user_id`),
    KEY `contents_type_id_index` (`type_id`),
    KEY `contents_status_index` (`status`),
    KEY `contents_created_at_index` (`created_at`),
    FOREIGN KEY (`type_id`) REFERENCES `content_types` (`id`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- سیستم نسخه‌بندی محتوا
-- -----------------------------------------------------
CREATE TABLE `content_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `content_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `content` LONGTEXT,
    `meta_data` JSON,
    `version` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `content_versions_content_id_index` (`content_id`),
    KEY `content_versions_user_id_index` (`user_id`),
    FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- سیستم دسته‌بندی و تگ‌ها
-- -----------------------------------------------------
CREATE TABLE `taxonomies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `hierarchical` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `taxonomies_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `terms` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `taxonomy_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `slug` VARCHAR(191) NOT NULL,
    `description` TEXT,
    `parent_id` BIGINT UNSIGNED DEFAULT NULL,
    `meta_data` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `terms_taxonomy_slug_unique` (`taxonomy_id`, `slug`),
    KEY `terms_parent_id_index` (`parent_id`),
    FOREIGN KEY (`taxonomy_id`) REFERENCES `taxonomies` (`id`),
    FOREIGN KEY (`parent_id`) REFERENCES `terms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `term_relationships` (
    `term_id` BIGINT UNSIGNED NOT NULL,
    `content_id` BIGINT UNSIGNED NOT NULL,
    `order` INT UNSIGNED DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`term_id`, `content_id`),
    KEY `term_relationships_content_id_index` (`content_id`),
    FOREIGN KEY (`term_id`) REFERENCES `terms` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
