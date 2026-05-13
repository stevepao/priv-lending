-- Track prepaid interest receipt posted from /checks (one-time per loan).
ALTER TABLE loans ADD COLUMN prepaid_interest_received TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 after prepaid interest cash event posted from Checks' AFTER prepaid_interest_date;
