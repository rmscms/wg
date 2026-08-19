-- Migration 002: add sub_short column for short subscription URL
-- Run once on the server:
--   mysql -u USER -p DATABASE < database/migrations/002_add_sub_short.sql

ALTER TABLE accounts
    ADD COLUMN sub_short VARCHAR(12) NULL DEFAULT NULL
        AFTER subscribe_token,
    ADD UNIQUE KEY uk_accounts_sub_short (sub_short);
