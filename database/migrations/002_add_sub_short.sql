-- Migration 002: short subscribe URL column (idempotent via runner)

ALTER TABLE accounts
    ADD COLUMN sub_short VARCHAR(12) NULL DEFAULT NULL
        AFTER subscribe_token;

CREATE UNIQUE INDEX uk_accounts_sub_short ON accounts (sub_short);
