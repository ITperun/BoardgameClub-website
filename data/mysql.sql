SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

USE `mop`;

DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`) VALUES
(1,	'admin'),
(2,	'user'),
(3,	'moderator'),
(4,	'organizer');

-- 2. GAMES
DROP TABLE IF EXISTS `games`;
CREATE TABLE `games` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `genre` varchar(100) DEFAULT NULL,
  `description` text,
  `image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `games` (`id`, `name`, `genre`, `description`, `image`) VALUES
(1,	'Catan',	'Strategy',	'Classic resource trading board game.',	NULL),
(2,	'Carcassonne',	'Tile Placement',	'Build cities and roads with tiles.',	NULL),
(3,	'Mahjong',	NULL,	NULL,	NULL),
(4,	'Dungeons&Dragons',	NULL,	NULL,	NULL),
(5,	'Dartz',	'',	'',	NULL);

-- 3. USERS
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `firstname` varchar(100) DEFAULT NULL,
  `lastname` varchar(100) DEFAULT NULL,
  `password` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role_id` int NOT NULL,
  `birthdate` date DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `fk_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `username`, `firstname`, `lastname`, `password`, `email`, `role_id`, `birthdate`, `image`, `phone`, `address`, `city`, `created_at`) VALUES
(1,	'admin',	'admin',	'admin',	'$2y$10$YnNs9oaMEDeJNR4jXU4ER.Z/T40FRZtTEmvOhie50ddZsMZOisl6i',	'admin@example.coam',	1,	'1985-12-16',	NULL,	NULL,	NULL,	NULL,	'2000-01-01 10:00:00');

-- 4. EVENTS
DROP TABLE IF EXISTS `events`;
CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text,
  `location` varchar(255) NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `game_id` int DEFAULT NULL,
  `organizer_id` int NOT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_events_game` (`game_id`),
  KEY `fk_events_organizer` (`organizer_id`),
  CONSTRAINT `fk_events_game` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_events_organizer` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `events` (`id`, `title`, `description`, `location`, `start_time`, `end_time`, `game_id`, `organizer_id`, `status`, `created_at`) VALUES
(2,	'CoYz',	'test',	'Praha, klubovna č.99',	'2025-12-04 13:35:00',	'2025-12-05 13:35:00',	3,	1,	'closed',	'2025-12-03 12:35:53');

DROP TABLE IF EXISTS `event_moderation`;
CREATE TABLE `event_moderation` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_id` int NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `author_id` int NOT NULL,
  `moderator_id` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id_unique` (`event_id`),
  KEY `fk_mod_event` (`event_id`),
  KEY `fk_mod_author` (`author_id`),
  KEY `fk_mod_moderator` (`moderator_id`),
  CONSTRAINT `fk_mod_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mod_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mod_moderator` FOREIGN KEY (`moderator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `event_moderation` (`id`, `event_id`, `status`, `author_id`, `moderator_id`, `updated_at`) VALUES
(1,	2,	'approved',	1,	1,	'2025-12-09 13:04:41');

DROP TABLE IF EXISTS `comments`;
CREATE TABLE `comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_id` int NOT NULL,
  `user_id` int NOT NULL,
  `content` text NOT NULL,
  `is_announcement` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_comments_event` (`event_id`),
  KEY `fk_comments_user` (`user_id`),
  CONSTRAINT `fk_comments_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `event_participants`;
CREATE TABLE `event_participants` (
  `event_id` int NOT NULL,
  `user_id` int NOT NULL,
  `joined_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`,`user_id`),
  KEY `fk_ep_user` (`user_id`),
  CONSTRAINT `fk_ep_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ep_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `event_blocked_users`;
CREATE TABLE `event_blocked_users` (
  `event_id` int NOT NULL,
  `user_id` int NOT NULL,
  `blocked_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`,`user_id`),
  KEY `fk_ebu_user` (`user_id`),
  CONSTRAINT `fk_ebu_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ebu_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `event_id` int NOT NULL,
  `message` text NOT NULL,
  `send_at` datetime NOT NULL,
  `is_sent` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_notifications_user` (`user_id`),
  KEY `fk_notifications_event` (`event_id`),
  CONSTRAINT `fk_notifications_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;