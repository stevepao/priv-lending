-- Manual close: last month shown on Checks is the calendar month of closed_date (inclusive).
ALTER TABLE loans ADD COLUMN closed_date DATE NULL AFTER maturity_date;
