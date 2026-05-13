-- Track whether optional loan funding cash event (principal_out) was created on save.
-- Requires 0006_loans_prepaid_interest_received.sql (column prepaid_interest_received must exist).
ALTER TABLE loans ADD COLUMN funding_principal_out_posted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 after funding principal_out cash event posted from loan new/edit' AFTER prepaid_interest_received;
