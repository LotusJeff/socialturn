-- Migration 001: Secure invite tokens
--
-- Replaces the SHA1-derived invite URL scheme with a stored random token.
-- Run this migration before deploying the Phase 1 security fixes.

CREATE TABLE `invites` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `companyid`  INT UNSIGNED    NOT NULL,
    `email`      VARCHAR(255)    NOT NULL,
    `token`      CHAR(64)        NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `used_at`    DATETIME        NULL     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invites_token` (`token`),
    KEY `idx_invites_company_email` (`companyid`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
