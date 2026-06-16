CREATE DATABASE IF NOT EXISTS agent_ksef
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE agent_ksef;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_type ENUM('app', 'user') NOT NULL DEFAULT 'app',
    scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    setting_key VARCHAR(100) NOT NULL,
    setting_value_text TEXT NULL,
    setting_value_json LONGTEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_settings_scope (scope_type, scope_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS encrypted_secrets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    secret_key VARCHAR(100) NOT NULL,
    secret_value LONGTEXT NOT NULL,
    encryption_driver VARCHAR(40) NOT NULL DEFAULT 'openssl',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_secret_key_user (user_id, secret_key),
    CONSTRAINT fk_encrypted_secrets_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploaded_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(50) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_uploaded_files_user_created (user_id, created_at),
    CONSTRAINT fk_uploaded_files_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    source ENUM('KSEF', 'CSV', 'ACCOUNTING_PDF') NOT NULL,
    ksef_reference_number VARCHAR(120) NULL,
    invoice_number VARCHAR(120) NULL,
    issuer_name VARCHAR(255) NULL,
    issuer_tax_id VARCHAR(32) NULL,
    issue_date DATE NULL,
    sale_date DATE NULL,
    due_date DATE NULL,
    gross_amount DECIMAL(15, 2) NULL,
    net_amount DECIMAL(15, 2) NULL,
    vat_amount DECIMAL(15, 2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PLN',
    bank_account VARCHAR(64) NULL,
    payment_description VARCHAR(255) NULL,
    raw_payload_json LONGTEXT NULL,
    validation_status VARCHAR(40) NULL,
    validation_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_invoices_user_issue_date (user_id, issue_date),
    KEY idx_invoices_ksef_ref (ksef_reference_number),
    KEY idx_invoices_invoice_number (invoice_number),
    CONSTRAINT fk_invoices_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_fetch_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    environment VARCHAR(20) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'created',
    invoice_count INT UNSIGNED NOT NULL DEFAULT 0,
    warning_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_invoice_fetch_jobs_user_created (user_id, created_at),
    CONSTRAINT fk_invoice_fetch_jobs_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_import_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    source_file_id BIGINT UNSIGNED NULL,
    export_file_id BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'created',
    transfer_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'PLN',
    warning_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_bank_import_jobs_user_created (user_id, created_at),
    CONSTRAINT fk_bank_import_jobs_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_bank_import_jobs_source_file
        FOREIGN KEY (source_file_id) REFERENCES uploaded_files (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bank_import_jobs_export_file
        FOREIGN KEY (export_file_id) REFERENCES uploaded_files (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounting_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    source_file_id BIGINT UNSIGNED NULL,
    row_lp VARCHAR(20) NULL,
    event_date DATE NULL,
    document_number VARCHAR(120) NULL,
    contractor_name VARCHAR(255) NULL,
    contractor_address VARCHAR(255) NULL,
    business_event_description VARCHAR(255) NULL,
    revenue_amount DECIMAL(15, 2) NULL,
    purchase_goods_amount DECIMAL(15, 2) NULL,
    side_purchase_costs_amount DECIMAL(15, 2) NULL,
    other_expenses_amount DECIMAL(15, 2) NULL,
    total_expenses_amount DECIMAL(15, 2) NULL,
    notes TEXT NULL,
    raw_text LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_accounting_entries_user_event_date (user_id, event_date),
    KEY idx_accounting_entries_document_number (document_number),
    CONSTRAINT fk_accounting_entries_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_entries_file
        FOREIGN KEY (source_file_id) REFERENCES uploaded_files (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NULL,
    accounting_entry_id BIGINT UNSIGNED NULL,
    status ENUM('BOTH', 'ONLY_KSEF', 'ONLY_ACCOUNTING', 'UNCERTAIN_MATCH') NOT NULL,
    confidence_score DECIMAL(5, 2) NULL,
    reasoning TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    matched_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_invoice_matches_user_status (user_id, status),
    CONSTRAINT fk_invoice_matches_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_invoice_matches_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_invoice_matches_accounting_entry
        FOREIGN KEY (accounting_entry_id) REFERENCES accounting_entries (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_action_created (action, created_at),
    KEY idx_audit_logs_user_created (user_id, created_at),
    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
