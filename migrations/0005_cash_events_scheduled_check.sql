-- Track interest-check postings per loan per calendar month (YYYY-MM) so /checks can hide completed items.
ALTER TABLE cash_events ADD COLUMN scheduled_check_ym CHAR(7) NULL COMMENT 'YYYY-MM when posted from /checks' AFTER loan_id;

CREATE UNIQUE INDEX uq_cash_events_loan_scheduled_check ON cash_events (loan_id, scheduled_check_ym);
