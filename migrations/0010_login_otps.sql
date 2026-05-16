CREATE TABLE IF NOT EXISTS login_otps (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    request_ip VARCHAR(45) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_login_otps_email_expires (email, expires_at),
    KEY idx_login_otps_ip_created (request_ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
