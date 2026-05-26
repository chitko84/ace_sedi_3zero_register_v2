-- Optional database updates for admin feature additions.
-- Safe to run multiple times in MySQL/MariaDB.

CREATE TABLE IF NOT EXISTS birthday_email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT(10) UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    sent_by INT(10) UNSIGNED NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_message TEXT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_birthday_email_logs_user_id (user_id),
    INDEX idx_birthday_email_logs_sent_at (sent_at),
    INDEX idx_birthday_email_logs_status (status)
);

DROP PROCEDURE IF EXISTS add_birthday_email_log_column_if_missing;

DELIMITER $$

CREATE PROCEDURE add_birthday_email_log_column_if_missing(
    IN p_column_name VARCHAR(64),
    IN p_column_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'birthday_email_logs'
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE birthday_email_logs ADD COLUMN ', p_column_definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL add_birthday_email_log_column_if_missing('email', 'email VARCHAR(255) NULL AFTER user_id');
CALL add_birthday_email_log_column_if_missing('sent_by', 'sent_by INT(10) UNSIGNED NULL AFTER email');
CALL add_birthday_email_log_column_if_missing('status', 'status ENUM(''sent'',''failed'') NOT NULL DEFAULT ''sent'' AFTER sent_by');
CALL add_birthday_email_log_column_if_missing('error_message', 'error_message TEXT NULL AFTER status');

DROP PROCEDURE IF EXISTS add_birthday_email_log_column_if_missing;
