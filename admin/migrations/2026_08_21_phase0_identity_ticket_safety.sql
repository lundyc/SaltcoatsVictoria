-- Phase 0 identity safety: prevent duplicate login emails while still
-- allowing non-login/dependant people to have NULL or blank email addresses.

ALTER TABLE season_ticket_holders
    ADD COLUMN email_normalized VARCHAR(190) NULL AFTER email;

UPDATE season_ticket_holders
SET email_normalized = NULLIF(LOWER(TRIM(email)), '')
WHERE email IS NOT NULL;

ALTER TABLE season_ticket_holders
    ADD UNIQUE KEY uq_season_ticket_holders_email_normalized (email_normalized);
