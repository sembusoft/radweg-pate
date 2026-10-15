-- ============================================================================
-- Migration 002: Mehrere Benutzer (Admin / Pate)
-- ============================================================================
-- Bisher gab es EIN Passwort (radpate_settings.password_hash). Ab 3.10.0 meldet man sich
-- mit Name + Passwort an. Der bisherige Zugang wird zur Benutzerin „Siglinde“ (Admin),
-- ihr gehören alle vorhandenen Kontrollfahrten.
--
-- Sichtbarkeit (Code, nicht Schema): Kontrollfahrten sieht nur, wer sie angelegt hat
-- (Admin: alle); Mängel sehen alle. Status erledigt/offen darf jeder setzen (status_by).
--
-- Nur erweiternd: neue Tabelle, neue NULL-fähige Spalten. Der Code von 3.9.0 läuft
-- mit diesem Schema unverändert weiter. Idempotent, ohne DELIMITER.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `radpate_users` (
    `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(60)   NOT NULL COMMENT 'Anmeldename, ohne Groß/Klein-Unterschied eindeutig',
    `password_hash`     VARCHAR(255)  NOT NULL,
    `role`              VARCHAR(10)   NOT NULL DEFAULT 'pate' COMMENT 'admin | pate',
    `active`            TINYINT(1)    NOT NULL DEFAULT 1,
    `must_change_pw`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = Startpasswort, beim nächsten Login ändern',
    `password_version`  INT UNSIGNED  NOT NULL DEFAULT 1 COMMENT 'erhöht bei Passwortwechsel – meldet andere Sitzungen ab',
    `created_at`        DATETIME      NOT NULL,
    `last_login_at`     DATETIME      NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_radpate_users_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `radpate_entries`
    ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED NULL COMMENT 'angelegt von' AFTER `id`,
    ADD KEY IF NOT EXISTS `ix_entries_user` (`user_id`);

ALTER TABLE `radpate_issues`
    ADD COLUMN IF NOT EXISTS `status_by` INT UNSIGNED NULL COMMENT 'Status zuletzt geändert von' AFTER `completed_at`;

-- Fremdschlüssel: Benutzer werden nie gelöscht, nur deaktiviert (Zuordnung bleibt lesbar).
ALTER TABLE `radpate_entries`
    ADD CONSTRAINT `fk_radpate_entry_user` FOREIGN KEY IF NOT EXISTS (`user_id`) REFERENCES `radpate_users` (`id`);
ALTER TABLE `radpate_issues`
    ADD CONSTRAINT `fk_radpate_issue_status_by` FOREIGN KEY IF NOT EXISTS (`status_by`) REFERENCES `radpate_users` (`id`) ON DELETE SET NULL;

-- Bisherigen Zugang übernehmen: Siglinde (Admin), gleiches Passwort, gleiche Passwort-Version.
INSERT IGNORE INTO `radpate_users` (`name`, `password_hash`, `role`, `active`, `must_change_pw`, `password_version`, `created_at`)
    SELECT 'Siglinde', s.`value`, 'admin', 1, 0,
           GREATEST(1, COALESCE((SELECT CAST(v.`value` AS UNSIGNED) FROM `radpate_settings` v WHERE v.`name` = 'password_version'), 1)),
           UTC_TIMESTAMP()
    FROM `radpate_settings` s
    WHERE s.`name` = 'password_hash' AND s.`value` IS NOT NULL AND s.`value` <> ''
      AND NOT EXISTS (SELECT 1 FROM `radpate_users`);

-- Alle bisherigen Kontrollfahrten gehören dem ersten Admin.
UPDATE `radpate_entries`
    SET `user_id` = (SELECT MIN(`id`) FROM `radpate_users` WHERE `role` = 'admin')
    WHERE `user_id` IS NULL;
