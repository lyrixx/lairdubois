-- Schema for sessions

CREATE TABLE IF NOT EXISTS `sessions` (
    `sess_id` VARCHAR(128) NOT NULL PRIMARY KEY,
    `sess_data` BLOB NOT NULL,
    `sess_time` INTEGER UNSIGNED NOT NULL,
    `sess_lifetime` INTEGER UNSIGNED NOT NULL,
    INDEX `sessions_sess_lifetime_idx` (`sess_lifetime`)
) COLLATE utf8_bin, ENGINE = InnoDB;

-- A table created before Symfony 4.4: sess_lifetime now holds the expiry timestamp, too big for
-- its former MEDIUMINT, and the garbage collection deletes the expired sessions by this column
ALTER TABLE `sessions`
    MODIFY `sess_lifetime` INTEGER UNSIGNED NOT NULL,
    ADD INDEX IF NOT EXISTS `sessions_sess_lifetime_idx` (`sess_lifetime`);
