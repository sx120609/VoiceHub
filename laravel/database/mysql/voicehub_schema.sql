SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `User` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `username` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `grade` varchar(64) DEFAULT NULL,
  `class` varchar(64) DEFAULT NULL,
  `avatar` text,
  `role` varchar(32) NOT NULL DEFAULT 'USER',
  `password` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `emailVerified` tinyint(1) NOT NULL DEFAULT 0,
  `lastLogin` datetime DEFAULT NULL,
  `lastLoginIp` varchar(255) DEFAULT NULL,
  `passwordChangedAt` datetime DEFAULT NULL,
  `forcePasswordChange` tinyint(1) NOT NULL DEFAULT 1,
  `meowNickname` varchar(255) DEFAULT NULL,
  `meowBoundAt` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `statusChangedAt` datetime DEFAULT NULL,
  `statusChangedBy` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_username` (`username`),
  KEY `idx_user_email` (`email`),
  KEY `idx_user_role` (`role`),
  KEY `idx_user_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PlayTime` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) NOT NULL,
  `startTime` varchar(32) DEFAULT NULL,
  `endTime` varchar(32) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RequestTime` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) NOT NULL,
  `startTime` datetime NOT NULL,
  `endTime` datetime NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `description` text,
  `expected` bigint NOT NULL DEFAULT 0,
  `accepted` bigint NOT NULL DEFAULT 0,
  `past` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_request_time_range` (`startTime`, `endTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Semester` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name` varchar(255) NOT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_semester_name` (`name`),
  KEY `idx_semester_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SystemSettings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `enablePlayTimeSelection` tinyint(1) NOT NULL DEFAULT 0,
  `siteTitle` varchar(255) DEFAULT NULL,
  `siteLogoUrl` text,
  `schoolLogoHomeUrl` text,
  `schoolLogoPrintUrl` text,
  `siteDescription` text,
  `submissionGuidelines` text,
  `icpNumber` varchar(128) DEFAULT NULL,
  `gonganNumber` varchar(128) DEFAULT NULL,
  `enableSubmissionLimit` tinyint(1) NOT NULL DEFAULT 0,
  `dailySubmissionLimit` int DEFAULT NULL,
  `weeklySubmissionLimit` int DEFAULT NULL,
  `monthlySubmissionLimit` int DEFAULT NULL,
  `showBlacklistKeywords` tinyint(1) NOT NULL DEFAULT 0,
  `hideStudentInfo` tinyint(1) NOT NULL DEFAULT 1,
  `smtpEnabled` tinyint(1) NOT NULL DEFAULT 0,
  `smtpHost` varchar(255) DEFAULT NULL,
  `smtpPort` int DEFAULT 587,
  `smtpSecure` tinyint(1) DEFAULT 0,
  `smtpUsername` varchar(255) DEFAULT NULL,
  `smtpPassword` varchar(255) DEFAULT NULL,
  `smtpFromEmail` varchar(255) DEFAULT NULL,
  `smtpFromName` varchar(255) DEFAULT '校园广播站',
  `enableRegistrationEmailVerification` tinyint(1) NOT NULL DEFAULT 0,
  `enableRequestTimeLimitation` tinyint(1) NOT NULL DEFAULT 0,
  `forceBlockAllRequests` tinyint(1) NOT NULL DEFAULT 0,
  `enableReplayRequests` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Song` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `title` varchar(255) NOT NULL,
  `artist` varchar(255) NOT NULL,
  `requesterId` int NOT NULL,
  `played` tinyint(1) NOT NULL DEFAULT 0,
  `playedAt` datetime DEFAULT NULL,
  `semester` varchar(255) DEFAULT NULL,
  `preferredPlayTimeId` int DEFAULT NULL,
  `cover` text,
  `playUrl` text,
  `musicPlatform` varchar(64) DEFAULT NULL,
  `musicId` varchar(255) DEFAULT NULL,
  `hitRequestId` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_song_requester` (`requesterId`),
  KEY `idx_song_played` (`played`),
  KEY `idx_song_semester` (`semester`),
  KEY `idx_song_created_at` (`createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Vote` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `songId` int NOT NULL,
  `userId` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vote_song_user` (`songId`, `userId`),
  KEY `idx_vote_song` (`songId`),
  KEY `idx_vote_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Schedule` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `songId` int NOT NULL,
  `playDate` datetime NOT NULL,
  `played` tinyint(1) NOT NULL DEFAULT 0,
  `sequence` int NOT NULL DEFAULT 1,
  `playTimeId` int DEFAULT NULL,
  `isDraft` tinyint(1) NOT NULL DEFAULT 0,
  `publishedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_schedule_song` (`songId`),
  KEY `idx_schedule_date` (`playDate`),
  KEY `idx_schedule_played` (`played`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Notification` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `type` varchar(64) NOT NULL,
  `message` text NOT NULL,
  `read` tinyint(1) NOT NULL DEFAULT 0,
  `userId` int NOT NULL,
  `songId` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notification_user_read` (`userId`, `read`),
  KEY `idx_notification_song` (`songId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `NotificationSettings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `userId` int NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `songRequestEnabled` tinyint(1) NOT NULL DEFAULT 1,
  `songVotedEnabled` tinyint(1) NOT NULL DEFAULT 1,
  `songPlayedEnabled` tinyint(1) NOT NULL DEFAULT 1,
  `songCommentEnabled` tinyint(1) NOT NULL DEFAULT 1,
  `refreshInterval` int NOT NULL DEFAULT 60,
  `songVotedThreshold` int NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notification_settings_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SongBlacklist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `type` varchar(32) NOT NULL,
  `value` varchar(255) NOT NULL,
  `reason` text,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `createdBy` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_song_blacklist_type` (`type`),
  KEY `idx_song_blacklist_active` (`isActive`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `UserIdentity` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `userId` int NOT NULL,
  `provider` varchar(64) NOT NULL,
  `providerUserId` varchar(255) NOT NULL,
  `providerUsername` text,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_identity_provider_user` (`provider`, `providerUserId`),
  KEY `idx_user_identity_user` (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `song_vote_offsets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `song_id` int NOT NULL,
  `vote_offset` int NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_song_vote_offsets_song` (`song_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `song_replay_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `song_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_song_replay_song_user` (`song_id`, `user_id`),
  KEY `idx_song_replay_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `song_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `song_id` int NOT NULL,
  `user_id` int NOT NULL,
  `parent_comment_id` int DEFAULT NULL,
  `content` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_song_comments_song` (`song_id`),
  KEY `idx_song_comments_user` (`user_id`),
  KEY `idx_song_comments_parent` (`parent_comment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_status_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) NOT NULL,
  `reason` text,
  `operator_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status_logs_user` (`user_id`),
  KEY `idx_user_status_logs_operator` (`operator_id`),
  KEY `idx_user_status_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `EmailTemplate` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `createdAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `key` varchar(100) NOT NULL,
  `name` varchar(200) NOT NULL,
  `subject` varchar(300) NOT NULL,
  `html` longtext NOT NULL,
  `updatedByUserId` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email_template_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `key_hash` varchar(255) NOT NULL,
  `key_prefix` varchar(10) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_used_at` datetime DEFAULT NULL,
  `created_by_user_id` int NOT NULL,
  `usage_count` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_keys_key_hash` (`key_hash`),
  KEY `idx_api_keys_prefix` (`key_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_key_permissions` (
  `id` char(36) NOT NULL,
  `api_key_id` char(36) NOT NULL,
  `permission` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_key_permissions_key` (`api_key_id`),
  KEY `idx_api_key_permissions_perm` (`permission`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_logs` (
  `id` char(36) NOT NULL,
  `api_key_id` char(36) DEFAULT NULL,
  `endpoint` varchar(500) NOT NULL,
  `method` varchar(10) NOT NULL,
  `ip_address` varchar(255) NOT NULL,
  `user_agent` text,
  `status_code` int NOT NULL,
  `response_time_ms` int NOT NULL,
  `request_body` longtext,
  `response_body` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `error_message` text,
  PRIMARY KEY (`id`),
  KEY `idx_api_logs_key` (`api_key_id`),
  KEY `idx_api_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `song_collaborators` (
  `id` char(36) NOT NULL,
  `song_id` int NOT NULL,
  `user_id` int NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_song_collaborators_song` (`song_id`),
  KEY `idx_song_collaborators_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `collaboration_logs` (
  `id` char(36) NOT NULL,
  `collaborator_id` char(36) NOT NULL,
  `action` varchar(50) NOT NULL,
  `operator_id` int NOT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_collaboration_logs_collab` (`collaborator_id`),
  KEY `idx_collaboration_logs_operator` (`operator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `SystemSettings` (
  `createdAt`, `updatedAt`, `enablePlayTimeSelection`, `siteTitle`, `siteLogoUrl`, `siteDescription`,
  `submissionGuidelines`, `enableSubmissionLimit`, `showBlacklistKeywords`, `hideStudentInfo`,
  `smtpEnabled`, `enableRegistrationEmailVerification`, `enableReplayRequests`,
  `enableRequestTimeLimitation`, `forceBlockAllRequests`
)
SELECT
  NOW(), NOW(), 0, 'VoiceHub', '/images/logo.png', '校园广播站点歌系统 - 让你的声音被听见',
  '请遵守校园规定，提交健康向上的歌曲。', 0, 0, 1,
  0, 0, 0, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM `SystemSettings` LIMIT 1);

SET FOREIGN_KEY_CHECKS = 1;
