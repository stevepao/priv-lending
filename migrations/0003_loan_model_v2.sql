-- Loan model v2: extend loans and cash_events without removing legacy loan columns yet.
-- Target: databases whose `loans` table still has `interest_type`, `monthly_interest`, and prepaid
-- columns (pre-application-update installs). If `principal_amount` or `payment_type` already
-- exists on `loans`, resolve duplicates before applying or merge changes manually.

ALTER TABLE loans ADD COLUMN principal_amount DECIMAL(12, 2) NULL AFTER name;

ALTER TABLE loans ADD COLUMN annual_interest_rate DECIMAL(6, 3) NULL AFTER principal_amount;

ALTER TABLE loans ADD COLUMN payment_type ENUM('interest_only', 'amortizing', 'prepaid') NOT NULL DEFAULT 'interest_only' AFTER annual_interest_rate;

ALTER TABLE loans ADD COLUMN principal_payment_monthly DECIMAL(12, 2) NULL AFTER payment_type;

ALTER TABLE loans ADD COLUMN status ENUM('active', 'paid_off') NOT NULL DEFAULT 'active' AFTER principal_payment_monthly;

UPDATE loans
SET payment_type = 'prepaid'
WHERE interest_type = 'prepaid' OR prepaid_interest_amount IS NOT NULL;

ALTER TABLE cash_events MODIFY COLUMN category ENUM('interest', 'principal_in', 'loc_interest', 'principal_out') NOT NULL;

ALTER TABLE cash_events ADD COLUMN check_uid VARCHAR(36) NULL AFTER check_number;

/* Manual validation checklist (after php bin/migrate.php or equivalent):
   1) SHOW COLUMNS FROM loans and confirm principal_amount, annual_interest_rate, payment_type, principal_payment_monthly, and status exist while interest_type, monthly_interest, prepaid_interest_amount, and prepaid_interest_date are still present
   2) SELECT id, interest_type, prepaid_interest_amount, payment_type FROM loans and confirm each row with interest_type=prepaid or non-null prepaid_interest_amount has payment_type=prepaid and all others remain interest_only unless manually set
   3) SHOW COLUMNS FROM cash_events LIKE 'category' and confirm ENUM lists interest, principal_in, loc_interest, principal_out
   4) SHOW COLUMNS FROM cash_events LIKE 'check_uid' and confirm VARCHAR(36) NULL
   5) INSERT a test cash_event with category=principal_in and a UUID in check_uid then DELETE it or leave for dev data as appropriate
   6) Confirm existing application screens still load (legacy columns untouched in this migration)
*/
