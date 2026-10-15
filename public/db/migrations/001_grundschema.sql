-- ============================================================================
-- Migration 001: Grundschema Radwegpate (bisher JSON-Dateien in radwegpate_private)
-- ============================================================================
-- Alle Tabellen tragen das Präfix radpate_: die Datenbank bei all-inkl teilen
-- sich mehrere Mini-Projekte.
--
-- Eigenschaften (wie bei fightReg, siehe db/README.md):
--   * IDEMPOTENT     — CREATE TABLE IF NOT EXISTS, mehrfaches Ausführen gefahrlos.
--   * MARIADB 10.11  — lokal wie produktiv (all-inkl 10.11.14).
--   * OHNE DELIMITER — der Runner trennt Anweisungen an „;“ am Zeilenende.
--
-- Zeiten: DATETIME in UTC. Kennungen bleiben die bisherigen (hex aus random_bytes),
-- damit Altdaten, Foto-Dateinamen und die App unverändert weiterpassen.
-- Fotos selbst bleiben Dateien in radwegpate_private/photos; hier nur der Verweis.
-- ============================================================================

-- Einzelwerte: Passwort-Hash, Passwort-Version, KML-Dateiname, Importzeitpunkte …
CREATE TABLE IF NOT EXISTS `radpate_settings` (
    `name`       VARCHAR(64)  NOT NULL,
    `value`      TEXT         NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Die sieben Touren (01–07).
CREATE TABLE IF NOT EXISTS `radpate_routes` (
    `id`           CHAR(2)       NOT NULL,
    `name`         VARCHAR(90)   NOT NULL,
    `type`         VARCHAR(20)   NOT NULL DEFAULT 'abfahrt',
    `area`         VARCHAR(180)  NOT NULL DEFAULT '',
    `note`         TEXT          NOT NULL,
    `km`           DECIMAL(4,1)  NULL,
    `link_komoot`  VARCHAR(1500) NOT NULL DEFAULT '' COMMENT 'Feldname historisch; Inhalt: Naviki/Komoot-Link',
    `link_google`  VARCHAR(1500) NOT NULL DEFAULT '',
    `link_apple`   VARCHAR(1500) NOT NULL DEFAULT '',
    `link_mymaps`  VARCHAR(1500) NOT NULL DEFAULT '',
    `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kontrollfahrten (Tagebuch).
CREATE TABLE IF NOT EXISTS `radpate_entries` (
    `id`          CHAR(24)      NOT NULL,
    `trip_date`   DATE          NOT NULL,
    `start_time`  TIME          NULL,
    `end_time`    TIME          NULL,
    `running`     TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = laufende Kontrollfahrt',
    `notes`       TEXT          NOT NULL,
    `lat`         DECIMAL(9,6)  NULL,
    `lon`         DECIMAL(9,6)  NULL,
    `created_at`  DATETIME      NOT NULL,
    `updated_at`  DATETIME      NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_entries_date` (`trip_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Welche Touren eine Kontrollfahrt umfasst (Reihenfolge wie gewählt).
CREATE TABLE IF NOT EXISTS `radpate_entry_routes` (
    `entry_id`  CHAR(24)          NOT NULL,
    `route_id`  CHAR(2)           NOT NULL,
    `position`  TINYINT UNSIGNED  NOT NULL DEFAULT 0,
    PRIMARY KEY (`entry_id`, `route_id`),
    KEY `ix_entry_routes_route` (`route_id`),
    CONSTRAINT `fk_radpate_er_entry` FOREIGN KEY (`entry_id`) REFERENCES `radpate_entries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_radpate_er_route` FOREIGN KEY (`route_id`) REFERENCES `radpate_routes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mängel einer Kontrollfahrt.
CREATE TABLE IF NOT EXISTS `radpate_issues` (
    `id`            VARCHAR(26)        NOT NULL COMMENT 'i_ + 24 hex',
    `entry_id`      CHAR(24)           NOT NULL,
    `route_id`      CHAR(2)            NOT NULL,
    `position`      SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    `kind`          VARCHAR(30)        NOT NULL,
    `issue_time`    TIME               NULL,
    `place`         VARCHAR(180)       NOT NULL DEFAULT '',
    `description`   TEXT               NOT NULL,
    `status`        VARCHAR(20)        NOT NULL DEFAULT 'offen',
    `lat`           DECIMAL(9,6)       NULL,
    `lon`           DECIMAL(9,6)       NULL,
    `created_at`    DATETIME           NOT NULL,
    `completed_at`  DATETIME           NULL,
    PRIMARY KEY (`id`),
    KEY `ix_issues_entry` (`entry_id`, `position`),
    KEY `ix_issues_route_status` (`route_id`, `status`),
    CONSTRAINT `fk_radpate_issue_entry` FOREIGN KEY (`entry_id`) REFERENCES `radpate_entries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_radpate_issue_route` FOREIGN KEY (`route_id`) REFERENCES `radpate_routes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fotos: allgemein zur Fahrt (issue_id NULL) oder zu einem Mangel. Datei in radwegpate_private/photos.
CREATE TABLE IF NOT EXISTS `radpate_photos` (
    `id`          CHAR(24)          NOT NULL,
    `entry_id`    CHAR(24)          NOT NULL,
    `issue_id`    VARCHAR(26)       NULL,
    `position`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `file`        VARCHAR(64)       NOT NULL,
    `name`        VARCHAR(120)      NOT NULL DEFAULT '',
    `mime`        VARCHAR(30)       NOT NULL,
    `size`        INT UNSIGNED      NOT NULL DEFAULT 0,
    `created_at`  DATETIME          NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_photos_entry` (`entry_id`, `issue_id`, `position`),
    CONSTRAINT `fk_radpate_photo_entry` FOREIGN KEY (`entry_id`) REFERENCES `radpate_entries` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_radpate_photo_issue` FOREIGN KEY (`issue_id`) REFERENCES `radpate_issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KML-Ebenen (aus dem Browser-Import) mit Zuordnung zur Tour.
-- Die Linien bleiben JSON: bis zu 110.000 Punkte, gelesen werden sie immer am Stück.
CREATE TABLE IF NOT EXISTS `radpate_map_sources` (
    `id`          CHAR(16)          NOT NULL,
    `position`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `name`        VARCHAR(160)      NOT NULL,
    `route_id`    CHAR(2)           NULL COMMENT 'Zuordnung zur Tour; NULL = keine',
    `line_data`   LONGTEXT          NOT NULL COMMENT 'JSON: [[[lon,lat],…],…]',
    `turnpoints`  TEXT              NOT NULL COMMENT 'JSON: [{name,point:[lon,lat]}]',
    PRIMARY KEY (`id`),
    KEY `ix_map_sources_route` (`route_id`),
    CONSTRAINT `fk_radpate_map_route` FOREIGN KEY (`route_id`) REFERENCES `radpate_routes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GPX-Schlüssel für die iPhone-App (12 h gültig, nur GPX-Download).
CREATE TABLE IF NOT EXISTS `radpate_gpx_tokens` (
    `token`       CHAR(32)  NOT NULL,
    `expires_at`  DATETIME  NOT NULL,
    PRIMARY KEY (`token`),
    KEY `ix_gpx_tokens_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anmeldeversuche je IP (gehasht) für die Sperre nach 10 Fehlversuchen in 15 Minuten.
CREATE TABLE IF NOT EXISTS `radpate_login_attempts` (
    `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `ip_hash`       CHAR(64)         NOT NULL,
    `attempted_at`  DATETIME         NOT NULL,
    PRIMARY KEY (`id`),
    KEY `ix_login_attempts_ip` (`ip_hash`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Die sieben Touren mit den bisherigen Vorgaben (bestehende Zeilen bleiben unverändert).
INSERT IGNORE INTO `radpate_routes` (`id`, `name`, `type`, `area`, `note`) VALUES
    ('01', 'West / Illerbrücke Oberkirchberg', 'abfahrt', 'Richtung Iller / westliche Gemeindegrenze', 'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'),
    ('02', 'Nord / Freudenegg – Gerlenhofen', 'abfahrt', 'Richtung Aufheim / nördliche Gemeindegrenze', 'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'),
    ('03', 'Nordost / Hittistetten', 'abfahrt', 'Nördlicher Bereich Richtung Hittistetten', 'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'),
    ('04', 'Ost / Wullenstetten – Witzighausen', 'abfahrt', 'Wullenstetten / Witzighausen / Richtung Weißenhorn', 'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'),
    ('05', 'Süd / Richtung Illerzell', 'abfahrt', 'Südlicher Außenbereich von Senden', 'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'),
    ('06', 'Südost / Richtung Illerberg', 'abfahrt', 'Südöstlicher Bereich Richtung Illerberg', 'Verlauf und Endpunkt noch anhand der Originalkarte prüfen.'),
    ('07', 'Gartenparadies-Runde', 'runde', 'Rosa Rundtour (Originalroute ca. 39,5 km, ohne individuelle Zu-/Rückfahrt)', 'Start / Ende Flurweg 7: individuelle Zu- und Rückfahrt noch prüfen.');
