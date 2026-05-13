-- Allow one interest and one principal cash event per loan per scheduled check month (from /checks).
ALTER TABLE cash_events DROP INDEX uq_cash_events_loan_scheduled_check;
CREATE UNIQUE INDEX uq_cash_events_loan_scheduled_category ON cash_events (loan_id, scheduled_check_ym, category);
