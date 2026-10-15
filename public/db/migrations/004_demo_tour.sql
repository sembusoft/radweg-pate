-- ============================================================================
-- Migration 004: Demo-Tour „00“ zum Ausprobieren
-- ============================================================================
-- Die Demo-Tour hat keine eigene KML-Linie: sie nutzt die Strecke einer echten Tour
-- (demo_source, Vorgabe 07, vom Admin wählbar). Fahrten und Mängel auf der Demo-Tour
-- zählen nicht bei den offenen Mängeln, tragen im Bericht „DEMO“, löschen sich nach
-- 7 Tagen selbst und können vom Admin jederzeit gelöscht werden. Eine Demo-Fahrt
-- enthält nur die Demo-Tour (keine Mischung mit echten Touren).
-- Nur erweiternd, idempotent, ohne DELIMITER.
-- ============================================================================

ALTER TABLE `radpate_routes`
    ADD COLUMN IF NOT EXISTS `is_demo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Demo-Tour (zählt nicht)' AFTER `type`,
    ADD COLUMN IF NOT EXISTS `demo_source` CHAR(2) NULL COMMENT 'Demo-Tour: Strecke dieser Tour verwenden' AFTER `is_demo`;

INSERT IGNORE INTO `radpate_routes` (`id`, `name`, `type`, `is_demo`, `demo_source`, `area`, `note`) VALUES
    ('00', 'Demo-Tour zum Ausprobieren', 'demo', 1, '07', 'Übungsstrecke – Fahrten und Mängel hier zählen nicht',
     'Zum Kennenlernen der App: Fahrt starten, Mängel mit Foto und GPS erfassen, Fahrt beenden, Bericht senden. Demo-Daten löschen sich nach 7 Tagen.');
