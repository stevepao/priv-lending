-- Loans columns used by GET /checks (fixed vs declining-balance).
-- Plain ADD COLUMN for MySQL 5.7+ / MariaDB. If a column already exists (e.g. from 0002 or 0003),
-- bin/migrate.php skips ER_DUP_FIELDNAME (1060) and continues so this file can still be marked applied.

ALTER TABLE loans ADD COLUMN monthly_interest DECIMAL(12, 2) NULL AFTER annual_interest_rate;

ALTER TABLE loans ADD COLUMN interest_calc_method ENUM('fixed', 'declining_balance') NOT NULL DEFAULT 'fixed' AFTER monthly_interest;

ALTER TABLE loans ADD COLUMN principal_payment_monthly DECIMAL(12, 2) NULL AFTER interest_calc_method;
