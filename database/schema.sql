-- UrusTugas — skema berbilang sekolah (satu pangkalan data dikongsi). Rangka platform daripada koku-multi (5fb9ba9), enjin agihan tugas (0.1-m2).
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
-- Guru (profil tugas, bukan akaun log masuk). external_id = ID sistem luar yang menyegerak melalui API (cth. "koku-multi:12:34"), unik dalam sekolah.
-- Indeks beban: teaching_periods = masa mengajar seminggu (0 = belum diisi), exam_class = mengajar kelas peperiksaan, status cuti/bertukar & index_exempt (pentadbir) tidak dikira.
CREATE TABLE teachers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(160) NOT NULL, email VARCHAR(190) NOT NULL DEFAULT '',
 specialty VARCHAR(160) NOT NULL DEFAULT '', capacity TINYINT UNSIGNED NOT NULL, external_id VARCHAR(160) NULL,
 teaching_periods TINYINT UNSIGNED NOT NULL DEFAULT 0, exam_class TINYINT NOT NULL DEFAULT 0, status ENUM('aktif','cuti','bertukar') NOT NULL DEFAULT 'aktif', index_exempt TINYINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY school_row (school_id,id), UNIQUE KEY school_external (school_id,external_id), INDEX(school_id,name),
 CONSTRAINT teacher_capacity CHECK (capacity BETWEEN 1 AND 100), CONSTRAINT teacher_periods CHECK (teaching_periods<=60),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
 password_hash VARCHAR(255) NOT NULL, role ENUM('admin','pk','head','teacher') NOT NULL DEFAULT 'teacher', active TINYINT NOT NULL DEFAULT 1, auth_version INT NOT NULL DEFAULT 1,
 totp_secret TEXT NULL, last_totp_step BIGINT NOT NULL DEFAULT -1, must_change TINYINT NOT NULL DEFAULT 0, last_login_at DATETIME NULL, allowed_ips VARCHAR(500) NULL,
 approval ENUM('approved','pending') NOT NULL DEFAULT 'approved', email_verified_at DATETIME NULL, self_registered TINYINT NOT NULL DEFAULT 0,
 teacher_id BIGINT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY school_email (school_id,email), UNIQUE KEY school_row (school_id,id), UNIQUE KEY school_teacher (school_id,teacher_id),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE,
 CONSTRAINT users_teacher_fk FOREIGN KEY(school_id,teacher_id) REFERENCES teachers(school_id,id)
) ENGINE=InnoDB;
-- Katalog mata pelajaran sekolah (pilihan untuk kepakaran guru). Kepakaran guru kekal teks (nama dipisah koma); nama di sini tiada koma.
CREATE TABLE subjects (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL, grp VARCHAR(60) NOT NULL DEFAULT '',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY school_row (school_id,id), UNIQUE KEY school_name (school_id,name),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- Peranan standard & skor (Pengerusi 4, Ketua Panitia 5, Setiausaha 3, AJK 1 …). Jawatan yang memilih peranan mengambil pemberat = skor peranan.
CREATE TABLE roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(60) NOT NULL, score TINYINT UNSIGNED NOT NULL, sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY school_row (school_id,id), UNIQUE KEY school_name (school_id,name),
 CONSTRAINT role_score CHECK (score BETWEEN 1 AND 10),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- Jawatan / tugas. Bidang tetap (CATEGORIES dalam kod). Pemberat beban 1–10. level × term melaraskan skor tugas dalam indeks beban (bukan mata kapasiti).
CREATE TABLE positions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(160) NOT NULL,
 category ENUM('Pentadbiran','Kurikulum','HEM','Kokurikulum') NOT NULL, weight TINYINT UNSIGNED NOT NULL,
 level ENUM('utama','sokongan','sampingan') NOT NULL DEFAULT 'sokongan', term ENUM('tahunan','bermusim','program') NOT NULL DEFAULT 'tahunan', role_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY school_row (school_id,id), INDEX(school_id,category,name),
 CONSTRAINT position_weight CHECK (weight BETWEEN 1 AND 10),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE,
 CONSTRAINT positions_role_fk FOREIGN KEY(school_id,role_id) REFERENCES roles(school_id,id)
) ENGINE=InnoDB;
-- Lantikan: satu jawatan = satu penyandang. Guru/jawatan yang dilantik tidak boleh dipadam (RESTRICT).
CREATE TABLE assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, teacher_id BIGINT UNSIGNED NOT NULL, position_id BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY school_row (school_id,id), UNIQUE KEY school_position (school_id,position_id), INDEX(school_id,teacher_id),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE,
 FOREIGN KEY(school_id,teacher_id) REFERENCES teachers(school_id,id), FOREIGN KEY(school_id,position_id) REFERENCES positions(school_id,id)
) ENGINE=InnoDB;
-- Syarat auto-agihan setiap jawatan (JSON: allowed_teachers[], fixed_teacher_id, required_specialty). ID guru dalam JSON disahkan oleh aplikasi dalam sekolah yang sama.
CREATE TABLE position_rules (
 school_id BIGINT UNSIGNED NOT NULL, position_id BIGINT UNSIGNED NOT NULL, payload MEDIUMTEXT NOT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(school_id,position_id), CONSTRAINT position_rules_json CHECK (JSON_VALID(payload)),
 FOREIGN KEY(school_id,position_id) REFERENCES positions(school_id,id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- Syarat umum auto-agihan (JSON) — satu baris setiap sekolah (dahulu jadual tunggal id=1).
CREATE TABLE allocation_config (
 school_id BIGINT UNSIGNED PRIMARY KEY, payload MEDIUMTEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT allocation_config_json CHECK (JSON_VALID(payload)),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- Pratonton auto-agihan: snapshot = sha256 keadaan semasa — sah 30 minit, sekali guna, dibuang selepas 24 jam. Masa dikira oleh DB (NOW()).
CREATE TABLE allocation_plans (
 id CHAR(48) PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, snapshot CHAR(64) NOT NULL, payload MEDIUMTEXT NOT NULL,
 created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, applied_at DATETIME NULL,
 INDEX(school_id,created_at), CONSTRAINT allocation_plans_json CHECK (JSON_VALID(payload)),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- Aliran kelulusan: setiap hantaran = satu revisi (snapshot JSON tetap + hash). Surat & "Tugas Saya" membaca revisi muktamad terkini.
CREATE TABLE workflow_revisions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL,
 status ENUM('submitted','approved','finalized','returned') NOT NULL DEFAULT 'submitted', payload MEDIUMTEXT NOT NULL, source_hash CHAR(64) NOT NULL,
 submitted_by BIGINT UNSIGNED NOT NULL, submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL, finalized_by BIGINT UNSIGNED NULL, finalized_at DATETIME NULL,
 returned_by BIGINT UNSIGNED NULL, returned_at DATETIME NULL, return_reason VARCHAR(500) NULL,
 UNIQUE KEY school_row (school_id,id), INDEX(school_id,status,id), CONSTRAINT workflow_payload_json CHECK (JSON_VALID(payload)),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE,
 FOREIGN KEY(school_id,submitted_by) REFERENCES users(school_id,id), FOREIGN KEY(school_id,approved_by) REFERENCES users(school_id,id),
 FOREIGN KEY(school_id,finalized_by) REFERENCES users(school_id,id), FOREIGN KEY(school_id,returned_by) REFERENCES users(school_id,id)
) ENGINE=InnoDB;
-- Keadaan aliran semasa — satu baris setiap sekolah — kunci optimistik melalui version.
-- return_info: JSON pemulangan terakhir (pelaku, masa, sebab, guru ditanda) — dikosongkan apabila draf dihantar semula.
CREATE TABLE workflow_state (
 school_id BIGINT UNSIGNED PRIMARY KEY, status ENUM('draft','submitted','approved','finalized') NOT NULL DEFAULT 'draft',
 revision_id BIGINT UNSIGNED NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
 return_info TEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY(school_id,revision_id) REFERENCES workflow_revisions(school_id,id)
) ENGINE=InnoDB;
-- Logo sekolah & Jata Negara untuk surat: id = sha256 kandungan, unik DALAM sekolah (surat muktamad lama kekal merujuk logo lama).
CREATE TABLE school_assets (
 school_id BIGINT UNSIGNED NOT NULL, id CHAR(64) NOT NULL, mime VARCHAR(20) NOT NULL, width SMALLINT UNSIGNED NOT NULL, height SMALLINT UNSIGNED NOT NULL,
 bytes MEDIUMBLOB NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(school_id,id), FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- Kunci API: token tidak disimpan — token_hash = HMAC-SHA256 dengan subkunci platform (unik global) supaya sekolah ditentukan oleh kunci,
-- kemudian disemak sama dengan sekolah dalam URL. prefix = 12 aksara awal untuk paparan.
CREATE TABLE api_keys (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, school_id BIGINT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, token_hash CHAR(64) NOT NULL UNIQUE,
 prefix VARCHAR(16) NOT NULL, scope ENUM('read','write') NOT NULL, created_by BIGINT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, last_used_at DATETIME NULL, revoked_at DATETIME NULL,
 UNIQUE KEY school_row (school_id,id), INDEX(school_id,revoked_at),
 FOREIGN KEY(school_id) REFERENCES schools(id) ON DELETE CASCADE, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
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
