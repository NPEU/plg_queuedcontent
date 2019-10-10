CREATE TABLE IF NOT EXISTS `#__content_queue` (
  `content_id` int(10) unsigned NOT NULL DEFAULT 0,
  `publish-date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `queued-content` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;