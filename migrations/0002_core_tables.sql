CREATE TABLE borrowers (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE entities (
    id INT NOT NULL AUTO_INCREMENT,
    borrower_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_entities_borrower FOREIGN KEY (borrower_id) REFERENCES borrowers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE loans (
    id INT NOT NULL AUTO_INCREMENT,
    entity_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    principal_amount DECIMAL(12, 2) NOT NULL,
    annual_interest_rate DECIMAL(6, 3) NOT NULL,
    funding_source ENUM('JPM', 'NTRS') NOT NULL,
    origin_date DATE NOT NULL,
    maturity_date DATE NULL,
    payment_type ENUM('interest_only', 'prepaid', 'amortizing') NOT NULL,
    prepaid_interest_amount DECIMAL(10, 2) NULL,
    prepaid_interest_date DATE NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_loans_entity FOREIGN KEY (entity_id) REFERENCES entities (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE cash_events (
    id INT NOT NULL AUTO_INCREMENT,
    loan_id INT NULL,
    event_date DATE NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    category ENUM('interest', 'loc_interest', 'principal_out') NOT NULL,
    deposit_to ENUM('JPM', 'NTRS') NULL,
    check_number VARCHAR(50) NULL,
    notes TEXT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_cash_events_loan FOREIGN KEY (loan_id) REFERENCES loans (id),
    KEY idx_cash_events_event_date (event_date),
    KEY idx_cash_events_category_event_date (category, event_date),
    KEY idx_cash_events_deposit_to_event_date (deposit_to, event_date),
    KEY idx_cash_events_loan_id_event_date (loan_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
