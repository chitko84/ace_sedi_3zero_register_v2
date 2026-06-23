-- Standardize every table in the currently selected database to
-- utf8mb4 / utf8mb4_unicode_ci.
--
-- IMPORTANT:
-- 1. In phpMyAdmin, first click/select your hosting database.
-- 2. Then run this script from that database's SQL tab.
--
-- This script intentionally does not hard-code a database name because
-- hosted databases usually use account-prefixed names such as u905714680_xxx.
--
-- Optional, only if your hosting user has ALTER DATABASE permission:
-- ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS convert_current_database_to_utf8mb4_unicode_ci$$

CREATE PROCEDURE convert_current_database_to_utf8mb4_unicode_ci()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE table_name VARCHAR(255);
    DECLARE table_cursor CURSOR FOR
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_TYPE = 'BASE TABLE';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    OPEN table_cursor;

    read_loop: LOOP
        FETCH table_cursor INTO table_name;
        IF done THEN
            LEAVE read_loop;
        END IF;

        SET @sql = CONCAT(
            'ALTER TABLE `',
            REPLACE(table_name, '`', '``'),
            '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;

    CLOSE table_cursor;
END$$

DELIMITER ;

CALL convert_current_database_to_utf8mb4_unicode_ci();

DROP PROCEDURE IF EXISTS convert_current_database_to_utf8mb4_unicode_ci;
