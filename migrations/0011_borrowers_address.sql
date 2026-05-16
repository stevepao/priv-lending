-- Optional mailing / street fields for borrowers.
ALTER TABLE borrowers ADD COLUMN address VARCHAR(255) NULL AFTER name;
ALTER TABLE borrowers ADD COLUMN city VARCHAR(100) NULL AFTER address;
ALTER TABLE borrowers ADD COLUMN state VARCHAR(100) NULL AFTER city;
ALTER TABLE borrowers ADD COLUMN zip VARCHAR(20) NULL AFTER state;
