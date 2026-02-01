-- 1. Create Media Social Reactions Table (Hearts/Fire)
CREATE TABLE IF NOT EXISTS `media_social_reactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `media_id` INT NOT NULL,
  `type` ENUM('heart', 'fire') NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_media_reaction` (`media_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create Media Social Comments Table
CREATE TABLE IF NOT EXISTS `media_social_comments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `media_id` INT NOT NULL,
  `user_name` VARCHAR(100) NOT NULL,
  `comment_body` TEXT NOT NULL,
  `status` TINYINT(1) DEFAULT 1, -- 1=active, 0=hidden
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_media_id` (`media_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Register the Filter Plugin in the Core Plugins Table
-- This ensures the system knows the Gatekeeper is a default core component
INSERT INTO `plugins` (`slug`, `name`, `description`, `version`, `is_active`, `is_core`) 
VALUES ('filter', 'Gatekeeper Filter', 'Default aggressive content scrubbing and branding enforcement.', '2.0.9', 1, 1)
ON DUPLICATE KEY UPDATE version='2.0.9', is_active=1;
