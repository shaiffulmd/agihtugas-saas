-- UrusTugas — skema berbilang sekolah (satu pangkalan data dikongsi). Rangka platform daripada koku-multi (5fb9ba9).
-- Setiap jadual data sekolah ada `school_id`. Rujukan antara jadual menggunakan kunci asing KOMPOSIT
-- (school_id, x_id) → induk(school_id, id) supaya baris sekolah A tidak boleh merujuk rekod sekolah B.
-- Rujukan pilihan (ON DELETE SET NULL) kekal satu lajur, pengesahan aplikasi + tapisan school_id meliputinya.

-- ---------- Peringkat platform ----------
CREATE TABLE schools (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code CHAR(7) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL,
 status ENUM('emel','menunggu','aktif','digantung','diarkib') NOT NULL DEFAULT 'aktif', notes VARCHAR(255) NOT NULL DEFAULT '',
 state VARCHAR(40) NOT NULL DEFAULT '', pic_name VARCHAR(120) NOT NULL DEFAULT '', pic_email VARCHAR(190) NOT NULL DEFAULT '', pic_phone VARCHAR(30) NOT NULL DEFAULT '',
 expires_on DATE NULL, approved_at DATETIME NULL, archived_at DATETIME NULL, notices TEXT NULL, addons VARCHAR(255) NOT NULL DEFAULT '',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(status,expires_on)
) ENGINE=InnoDB;
CREATE TABLE platform_admins (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, active TINYINT NOT NULL DEFAULT 1, auth_version INT NOT NULL DEFAULT 1,
 totp_secret TEXT NULL, last_totp_step BIGINT NOT NULL DEFAULT -1, last_login_at DATETIME NULL, allowed_ips VARCHAR(500) NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE platform_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NULL, action VARCHAR(60) NOT NULL,
 school_id BIGINT UNSIGNED NULL, detail VARCHAR(255) NOT NULL DEFAULT '', ip_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, seq BIGINT UNSIGNED NULL UNIQUE, chain CHAR(64) NULL, admin_ref BIGINT UNSIGNED NULL, school_ref BIGINT UNSIGNED NULL, INDEX(created_at),
 FOREIGN KEY(admin_id) REFERENCES platform_admins(id) ON DELETE SET NULL, FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE platform_settings (
 name VARCHAR(60) PRIMARY KEY, value MEDIUMTEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- Had cubaan dikongsi: kunci baldi ialah HMAC dengan subkunci sekolah (rate_key), jadi tidak bertembung antara sekolah.
CREATE TABLE rate_limits (bucket CHAR(64) PRIMARY KEY, attempts INT NOT NULL, window_start DATETIME NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS health_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, checked_at DATETIME NOT NULL, ok TINYINT NOT NULL,
 detail VARCHAR(255) NOT NULL DEFAULT '', source ENUM('monitor','manual') NOT NULL DEFAULT 'monitor', response_ms DECIMAL(8,1) NULL,
 INDEX(checked_at)
) ENGINE=InnoDB;

-- ---------- Data sekolah ----------
CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
 password_hash VARCHAR(255) NOT NULL, role ENUM('admin','pk','head','teacher') NOT NULL DEFAULT 'teacher', active TINYINT NOT NULL DEFAULT 1, auth_version INT NOT NULL DEFAULT 1,
 totp_secret TEXT NULL, last_totp_step BIGINT NOT NULL DEFAULT -1, must_change TINYINT NOT NULL DEFAULT 0, last_login_at DATETIME NULL, allowed_ips VARCHAR(500) NULL,
 approval ENUM('approved','pending') NOT NULL DEFAULT 'approved', email_verified_at DATETIME NULL, self_registered TINYINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY school_email (school_id,email), UNIQUE KEY school_row (school_id,id),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL, via_platform BIGINT UNSIGNED NULL, action VARCHAR(60) NOT NULL,
 entity VARCHAR(60) NOT NULL, entity_id BIGINT UNSIGNED NULL, detail VARCHAR(255) NOT NULL DEFAULT '', ip_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, seq BIGINT UNSIGNED NULL, chain CHAR(64) NULL, via_ref BIGINT UNSIGNED NULL,
 INDEX(school_id,created_at), INDEX action (action), UNIQUE KEY audit_seq (school_id,seq),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(via_platform) REFERENCES platform_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE recovery_codes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 code_hash CHAR(64) NOT NULL, used_at DATETIME NULL, FOREIGN KEY(school_id,user_id) REFERENCES users(school_id,id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settings (
 school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(60) NOT NULL, value MEDIUMTEXT NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(school_id,name), FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_avatars (
 school_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED PRIMARY KEY, mime VARCHAR(20) NOT NULL, data MEDIUMBLOB NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(school_id,user_id) REFERENCES users(school_id,id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, type ENUM('verify','reset') NOT NULL,
 token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY token_hash (token_hash), INDEX(user_id,type), FOREIGN KEY(school_id,user_id) REFERENCES users(school_id,id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Hebahan guru (v2.15)
CREATE TABLE IF NOT EXISTS staff_notices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, title VARCHAR(160) NOT NULL, body MEDIUMTEXT NOT NULL,
 style VARCHAR(20) NOT NULL DEFAULT 'makluman', audience ENUM('semua','pilih') NOT NULL DEFAULT 'semua',
 pinned TINYINT NOT NULL DEFAULT 0, status ENUM('terbit','draf') NOT NULL DEFAULT 'terbit', expire_at DATE NULL,
 created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(school_id,status,created_at), UNIQUE KEY school_row (school_id,id),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS staff_notice_targets (
 school_id BIGINT UNSIGNED NOT NULL, notice_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(notice_id,user_id), INDEX(user_id),
 FOREIGN KEY(school_id,notice_id) REFERENCES staff_notices(school_id,id) ON DELETE CASCADE, FOREIGN KEY(school_id,user_id) REFERENCES users(school_id,id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS staff_notice_reads (
 school_id BIGINT UNSIGNED NOT NULL, notice_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(notice_id,user_id), INDEX(user_id),
 FOREIGN KEY(school_id,notice_id) REFERENCES staff_notices(school_id,id) ON DELETE CASCADE, FOREIGN KEY(school_id,user_id) REFERENCES users(school_id,id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Rekod persetujuan & pusat sokongan (2.15-m5)
CREATE TABLE IF NOT EXISTS consents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NULL, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
 context VARCHAR(40) NOT NULL, version CHAR(16) NOT NULL, ip_hash CHAR(64) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(email), INDEX(created_at), FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS support_tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
 subject VARCHAR(160) NOT NULL, category VARCHAR(12) NOT NULL DEFAULT 'lain', status ENUM('baru','dibalas','selesai') NOT NULL DEFAULT 'baru',
 platform_read TINYINT NOT NULL DEFAULT 0, school_read TINYINT NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, closed_at DATETIME NULL,
 UNIQUE KEY school_row (school_id,id), INDEX(school_id,status), INDEX(platform_read,updated_at),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS support_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, ticket_id BIGINT UNSIGNED NOT NULL,
 sender ENUM('sekolah','platform') NOT NULL, author VARCHAR(120) NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(ticket_id), FOREIGN KEY(school_id,ticket_id) REFERENCES support_tickets(school_id,id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bayaran langganan, kod diskaun & tajaan (2.15-m6). Jadual platform: kekal selepas arkib (school_id SET NULL jika sekolah dipadam).
CREATE TABLE IF NOT EXISTS payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NULL, school_code CHAR(7) NOT NULL,
 method VARCHAR(12) NOT NULL, amount_sen INT UNSIGNED NOT NULL, years TINYINT UNSIGNED NOT NULL DEFAULT 1, sandbox TINYINT NOT NULL DEFAULT 0,
 status ENUM('menunggu','berjaya','semak','ditolak','gagal','dibatal') NOT NULL DEFAULT 'menunggu',
 ext_id VARCHAR(60) NOT NULL DEFAULT '', ref VARCHAR(80) NOT NULL DEFAULT '',
 payer_name VARCHAR(120) NOT NULL DEFAULT '', payer_email VARCHAR(190) NOT NULL DEFAULT '', payer_phone VARCHAR(30) NOT NULL DEFAULT '',
 proof VARCHAR(60) NULL, note VARCHAR(300) NOT NULL DEFAULT '', discount_code VARCHAR(30) NULL, discount_sen INT UNSIGNED NOT NULL DEFAULT 0,
 by_name VARCHAR(120) NOT NULL DEFAULT '', receipt_no VARCHAR(20) NULL UNIQUE, expires_from DATE NULL, expires_to DATE NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, paid_at DATETIME NULL,
 INDEX(school_id,status), INDEX(method,ext_id), INDEX(status,created_at),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS discounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, kind ENUM('peratus','tetap') NOT NULL,
 value INT UNSIGNED NOT NULL, ends_on DATE NULL, max_uses INT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1,
 note VARCHAR(200) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sponsors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL DEFAULT '',
 phone VARCHAR(30) NOT NULL DEFAULT '', note VARCHAR(500) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS sponsorships (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, sponsor_id BIGINT UNSIGNED NOT NULL, school_id BIGINT UNSIGNED NOT NULL,
 start_on DATE NOT NULL, end_on DATE NOT NULL, prev_expires DATE NULL, value_sen INT UNSIGNED NOT NULL DEFAULT 0, ref VARCHAR(100) NOT NULL DEFAULT '',
 status ENUM('aktif','batal') NOT NULL DEFAULT 'aktif', payment_id BIGINT UNSIGNED NULL, by_name VARCHAR(120) NOT NULL DEFAULT '', reminded DATE NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(school_id,status), INDEX(status,end_on),
 FOREIGN KEY(sponsor_id) REFERENCES sponsors(id), FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE,
 FOREIGN KEY(payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Keselamatan (2.15-m7): sekatan IP, laporan keselamatan, kepala rantai audit (school_id 0 = log platform).
CREATE TABLE IF NOT EXISTS ip_blocks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NULL, ip VARCHAR(64) NOT NULL,
 scope ENUM('semua','akaun') NOT NULL DEFAULT 'semua', reason VARCHAR(200) NOT NULL DEFAULT '', expires_at DATETIME NULL,
 auto TINYINT NOT NULL DEFAULT 0, by_name VARCHAR(120) NOT NULL DEFAULT '', cf_id VARCHAR(64) NULL, cf_error VARCHAR(200) NOT NULL DEFAULT '',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(school_id,scope), INDEX(expires_at),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS security_reports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(190) NOT NULL, title VARCHAR(160) NOT NULL,
 body TEXT NOT NULL, status ENUM('baru','disemak','selesai','ditolak') NOT NULL DEFAULT 'baru', note VARCHAR(500) NOT NULL DEFAULT '',
 ip_hash CHAR(64) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX(status,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS audit_heads (
 school_id BIGINT UNSIGNED PRIMARY KEY, seq BIGINT UNSIGNED NOT NULL DEFAULT 0, chain CHAR(64) NOT NULL
) ENGINE=InnoDB;
