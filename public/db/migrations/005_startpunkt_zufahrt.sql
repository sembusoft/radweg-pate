-- ============================================================================
-- Migration 005: Startpunkt je Pate und berechnete Zufahrt zu den Touren
-- ============================================================================
-- Die Tour-Linien aus der KML bleiben unverändert (Kontrollstrecke). Neu berechnet wird
-- nur der Weg vom Startpunkt des Paten zum Anfang der Tour und vom Ende zurück
-- (Fahrrad-Routenplaner: openrouteservice oder BRouter, siehe .env.example).
-- Ergebnisse werden je Pate und Tour gespeichert und erst bei neuem Startpunkt oder
-- geänderter Tour-Linie neu gerechnet.
-- Nur erweiternd, idempotent, ohne DELIMITER.
-- ============================================================================

ALTER TABLE `radpate_users`
    ADD COLUMN IF NOT EXISTS `start_lat`   DECIMAL(9,6) NULL COMMENT 'Startpunkt des Paten' AFTER `password_version`,
    ADD COLUMN IF NOT EXISTS `start_lon`   DECIMAL(9,6) NULL AFTER `start_lat`,
    ADD COLUMN IF NOT EXISTS `start_label` VARCHAR(120) NULL AFTER `start_lon`;

-- GPX-Schlüssel kennen ab jetzt ihren Benutzer (GPX mit Zufahrt über dl.<host>).
ALTER TABLE `radpate_gpx_tokens`
    ADD COLUMN IF NOT EXISTS `user_id` INT UNSIGNED NULL AFTER `token`;

CREATE TABLE IF NOT EXISTS `radpate_approaches` (
    `user_id`      INT UNSIGNED  NOT NULL,
    `route_key`    VARCHAR(30)   NOT NULL COMMENT 'Tour-ID oder Kombination, z. B. 04 oder 02-03',
    `cache_key`    CHAR(40)      NOT NULL COMMENT 'sha1 aus Startpunkt, Tour-Anfang/-Ende, Anbieter',
    `to_line`      LONGTEXT      NOT NULL COMMENT 'JSON [[lon,lat],…] Startpunkt → Tour-Anfang',
    `to_m`         INT UNSIGNED  NOT NULL DEFAULT 0,
    `back_line`    LONGTEXT      NOT NULL COMMENT 'JSON Tour-Ende → Startpunkt',
    `back_m`       INT UNSIGNED  NOT NULL DEFAULT 0,
    `provider`     VARCHAR(20)   NOT NULL,
    `computed_at`  DATETIME      NOT NULL,
    PRIMARY KEY (`user_id`, `route_key`),
    CONSTRAINT `fk_radpate_appr_user` FOREIGN KEY (`user_id`) REFERENCES `radpate_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
