<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Baseline schema. Identical to the tables of the first (plain PHP) version, so existing
 * data and every query keep working. New changes should be added as new migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `staff` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','representative','viewer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `audio_files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `filename` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `description` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `participants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_cipher` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `site` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `study_group` enum('unassigned','audio','usual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unassigned',
  `stage` enum('t0','allocation','session','wait_t2','t2','recovery','evening','followup','sleep','done') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 't0',
  `is_test` tinyint(1) NOT NULL DEFAULT '0',
  `sleep_opt_in` tinyint(1) NOT NULL DEFAULT '0',
  `consent_confirmed` tinyint(1) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `session_started_at` datetime DEFAULT NULL,
  `session_ended_at` datetime DEFAULT NULL,
  `listening_seconds` int NOT NULL DEFAULT '0',
  `session_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_id` int DEFAULT NULL,
  `randomized_at` datetime DEFAULT NULL,
  `allocation_reference` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sleep_audio_id` int DEFAULT NULL,
  `sleep_listening_seconds` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `assessments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `participant_id` int NOT NULL,
  `type` enum('t0','t2','evening','sleep') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('partial','completed','declined','missed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'partial',
  `answers` json NOT NULL,
  `revision` int NOT NULL DEFAULT '1',
  `opened_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `saved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` datetime DEFAULT NULL,
  `missing` json DEFAULT NULL,
  `core_completed` tinyint(1) NOT NULL DEFAULT '0',
  `entry_method` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Patient Digital',
  `instrument_version` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1.0-draft',
  `closure_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `participant_type` (`participant_id`,`type`),
  CONSTRAINT `assessments_ibfk_1` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `assessment_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `participant_id` int NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `revision` int NOT NULL,
  `answers` json NOT NULL,
  `missing` json DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `history_revision` (`participant_id`,`type`,`revision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `operational_records` (
  `participant_id` int NOT NULL,
  `record` json NOT NULL,
  `revision` int NOT NULL DEFAULT '1',
  `updated_by` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`participant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `allocation_sequences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `site` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` int NOT NULL,
  `study_group` enum('audio','usual') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `participant_id` int DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sequence_site_position` (`site`,`position`),
  UNIQUE KEY `participant_id` (`participant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `audit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `participant_id` int DEFAULT NULL,
  `actor` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `ip` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `failures` int NOT NULL DEFAULT '0',
  `last_attempt` datetime NOT NULL,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['login_attempts', 'audit', 'allocation_sequences', 'operational_records', 'assessment_history', 'assessments', 'participants', 'audio_files', 'staff'] as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
