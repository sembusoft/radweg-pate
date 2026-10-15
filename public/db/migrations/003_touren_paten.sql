-- ============================================================================
-- Migration 003: Touren ↔ Paten (mehrere Paten je Tour, mehrere Touren je Pate)
-- ============================================================================
-- Wirkung (Code): Eigene Touren stehen beim Paten vorn und sind vorausgewählt
-- (Touren-Knöpfe, Karte, „Fahrt beginnen“); andere Touren bleiben erreichbar.
-- Ohne Zuordnung gilt nichts als „eigene“ – alles wie bisher.
-- Zugeordnet wird vom Admin im Benutzer-Dialog.
-- Nur erweiternd, idempotent, ohne DELIMITER.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `radpate_user_routes` (
    `user_id`   INT UNSIGNED  NOT NULL,
    `route_id`  CHAR(2)       NOT NULL,
    PRIMARY KEY (`user_id`, `route_id`),
    KEY `ix_user_routes_route` (`route_id`),
    CONSTRAINT `fk_radpate_ur_user`  FOREIGN KEY (`user_id`)  REFERENCES `radpate_users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_radpate_ur_route` FOREIGN KEY (`route_id`) REFERENCES `radpate_routes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
