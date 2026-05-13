-- Loans columns used by GET /checks (fixed vs declining-balance).
-- Idempotent on MySQL 8.0.29+ and MariaDB 10.2.19+ (ADD COLUMN IF NOT EXISTS).
-- Older servers: run the three ALTERs from README.md manually and skip lines that error.

ALTER TABLE loans
    ADD COLUMN IF NOT EXISTS monthly_interest DECIMAL(12, 2) NULL AFTER annual_interest_rate,
    ADD COLUMN IF NOT EXISTS interest_calc_method ENUM('fixed', 'declining_balance') NOT NULL DEFAULT 'fixed' AFTER monthly_interest,
    ADD COLUMN IF NOT EXISTS principal_payment_monthly DECIMAL(12, 2) NULL AFTER interest_calc_method;
