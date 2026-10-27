<?php
declare(strict_types=1);
/*
 * Logik pemasangan & naik taraf yang dikongsi oleh CLI (bin/install.php, bin/upgrade.php)
 * dan pemasang web (public_html/install.php).
 * Pemasangan hanya dibenarkan sekali: selepas berjaya, storage/installed.lock ditulis dan
 * semua laluan pemasangan menolak untuk berjalan semula.
 */
const APP_VERSION = '1.2';
const APP_RELEASE = '2026-10-27'; // Tarikh keluaran (dipaparkan di footer). Sejarah perubahan: app/changelog.php
// Naikkan setiap kali skema pangkalan data berubah (jadual/lajur baharu), walaupun APP_VERSION kekal.
const APP_SCHEMA = '0.1-m4'; // m4: indeks beban — teachers.teaching_periods/exam_class/status/index_exempt, positions.level/term; m3: workflow_state.return_info (guru ditanda semasa dikembalikan); m1: rangka platform daripada koku-multi (5fb9ba9) — users peranan UrusTugas, audit_logs.detail; m2: jadual enjin agihan tugas (school_id) + users.teacher_id

function install_lock_path(): string { return dirname(__DIR__).'/storage/installed.lock'; }
function table_exists(string $t): bool { return (bool)query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t])->fetchColumn(); }
function column_exists(string $t,string $c): bool { return (bool)query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$t,$c])->fetchColumn(); }
function index_exists(string $t,string $i): bool { return (bool)query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$t,$i])->fetchColumn(); }
function db_table_count(): int { return (int)query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn(); }

/** Dipasang jika fail kunci wujud ATAU jadual schools sudah ada (pemasangan lama tanpa fail kunci). */
function is_installed(): bool {
    if(is_file(install_lock_path())) return true;
    try { return table_exists('schools'); } catch(Throwable) { return false; }
}
function write_install_lock(string $via): void {
    $path=install_lock_path();
    if(!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
    $prev=is_file($path)?(json_decode((string)@file_get_contents($path),true)?:[]):[];
    $ok=file_put_contents($path,json_encode(['installed_at'=>$prev['installed_at']??date('c'),'updated_at'=>date('c'),'version'=>APP_VERSION,'schema'=>APP_SCHEMA,'via'=>$via],JSON_PRETTY_PRINT)."\n",LOCK_EX);
    if($ok===false) throw new RuntimeException('Tidak dapat menulis storage/installed.lock. Semak kebenaran tulis folder storage/.');
    @chmod($path,0600);
}
function read_install_lock(): ?array { $p=install_lock_path(); if(!is_file($p)) return null; $d=json_decode((string)@file_get_contents($p),true); return is_array($d)?$d:['version'=>'?']; }
/** ready | not_installed | needs_upgrade | broken. Murah apabila fail kunci sepadan dengan versi semasa. */
function app_status(): string {
    $lock=read_install_lock();
    if($lock && ($lock['version']??'')===APP_VERSION && ($lock['schema']??'')===APP_SCHEMA) return 'ready';
    if(!table_exists('schools')) return $lock?'broken':'not_installed';
    if(needs_upgrade()) return 'needs_upgrade';
    write_install_lock($lock['via']??'auto'); return 'ready';
}
function needs_upgrade(): bool {
    // Pemasangan bermula dengan skema penuh (database/schema.sql). Tambah semakan di sini apabila skema berubah.
    return !table_exists('platform_settings') || !column_exists('audit_logs','detail') || !table_exists('audit_heads')
        || !column_exists('users','teacher_id') || !column_exists('workflow_state','return_info') || !column_exists('teachers','teaching_periods') || !column_exists('positions','level') || array_filter(ENGINE_TABLES,fn($t)=>!table_exists($t))!==[];
}

/** Semakan keperluan pelayan. Setiap item: [label, status ok|warn|fail, butiran]. */
function install_requirements(bool $web=false): array {
    $r=[];
    $r[]=['PHP 8.2 atau lebih baharu',version_compare(PHP_VERSION,'8.2.0','>=')?'ok':'fail','Versi semasa '.PHP_VERSION];
    foreach(['pdo_mysql','sodium','mbstring','zip','dom','gd'] as $ext) $r[]=['Sambungan PHP '.$ext,extension_loaded($ext)?'ok':'fail',extension_loaded($ext)?'Tersedia':'Tiada; minta pihak hosting mengaktifkannya'];
    $r[]=['Argon2id (password_hash)',defined('PASSWORD_ARGON2ID')?'ok':'fail',defined('PASSWORD_ARGON2ID')?'Tersedia':'PHP perlu dibina dengan libargon2/libsodium'];
    $r[]=['GD dengan WebP',function_exists('imagewebp')?'ok':'fail',function_exists('imagewebp')?'Tersedia':'Diperlukan untuk gambar laporan & logo'];
    $r[]=['Sambungan PHP curl (sandaran Google Drive)',extension_loaded('curl')?'ok':'warn',extension_loaded('curl')?'Tersedia':'Pilihan: diperlukan untuk sandaran Google Drive'];
    $r[]=['Fail .env',is_file(dirname(__DIR__).'/.env')?'ok':'fail',is_file(dirname(__DIR__).'/.env')?'Dijumpai di luar web root':'Salin .env.example kepada .env'];
    $keyOk=false; try { key_bytes(); $keyOk=true; } catch(Throwable) {}
    $r[]=['APP_KEY sah (32 bait base64)',$keyOk?'ok':'fail',$keyOk?'Sah':'Tetapkan APP_KEY rawak dalam .env'];
    $dbOk=false; $dbMsg='';
    try { db()->query('SELECT 1'); $dbOk=true; $dbMsg='Bersambung'; } catch(Throwable $ex) { $dbMsg='Gagal bersambung: semak DB_HOST, DB_NAME, DB_USER, DB_PASS'; }
    $r[]=['Pangkalan data MySQL/MariaDB',$dbOk?'ok':'fail',$dbMsg];
    $storage=dirname(__DIR__).'/storage';
    $r[]=['Folder storage/ boleh ditulis',(is_dir($storage) && is_writable($storage))?'ok':'fail',(is_dir($storage) && is_writable($storage))?'Boleh ditulis':'Benarkan proses PHP menulis ke storage/ (bukan chmod 777)'];
    $prod=env('APP_ENV')==='production';
    $r[]=['APP_ENV=production',$prod?'ok':'warn',$prod?'Mod production':'Mod '.env('APP_ENV','?').' — hanya untuk ujian setempat'];
    $r[]=['REQUIRE_2FA=1',env('REQUIRE_2FA','1')==='1'?'ok':'warn',env('REQUIRE_2FA','1')==='1'?'2FA diwajibkan':'2FA tidak diwajibkan — tidak disyorkan'];
    $r[]=['SESSION_SECURE=1 (kuki HTTPS sahaja)',env('SESSION_SECURE','1')==='1'?'ok':($prod?'fail':'warn'),env('SESSION_SECURE','1')==='1'?'Aktif':'Wajib 1 untuk production'];
    return $r;
}
function requirements_ok(array $r): bool { foreach($r as $i) if($i[1]==='fail') return false; return true; }

/** Pasang skema pada pangkalan data KOSONG sahaja. */
function install_schema(): void {
    if(is_installed()) throw new DomainException('Sistem telah dipasang. Pemasang dikunci.');
    if(db_table_count()>0) throw new DomainException('Pangkalan data tidak kosong. Gunakan pangkalan data baharu yang kosong, atau jalankan naik taraf untuk pemasangan sedia ada.');
    $sql=(string)preg_replace('/^--.*$/m','',(string)file_get_contents(dirname(__DIR__).'/database/schema.sql')); // komen dibuang dahulu: ';' dalam komen tidak memecahkan pernyataan
    foreach(explode(';',$sql) as $statement) if(trim(preg_replace('/^--.*$/m','',$statement))!=='') db()->exec($statement);
}
function validate_admin(string $name,string $email,string $password,string $confirm): array {
    $name=trim($name); $email=strtolower(trim($email));
    if($name==='' || mb_strlen($name)>120) throw new DomainException('Nama pentadbir tidak sah (1–120 aksara).');
    if(strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel pentadbir tidak sah.');
    if(!valid_password($password)) throw new DomainException('Kata laluan mesti 12 hingga 128 aksara.');
    if(!hash_equals($password,$confirm)) throw new DomainException('Pengesahan kata laluan tidak sepadan.');
    $local=strtolower(explode('@',$email)[0]);
    if(strtolower($password)===$email || (strlen($local)>=4 && str_contains(strtolower($password),$local))) throw new DomainException('Kata laluan tidak boleh mengandungi e-mel anda.');
    return [$name,$email];
}
/** Pentadbir sekolah semasa (sid()). */
function create_admin(string $name,string $email,string $password,bool $mustChange=false): int {
    if(sid()<=0) throw new LogicException('Tiada sekolah dipilih.');
    if(query('SELECT id FROM users WHERE school_id=? AND email=?',[sid(),$email])->fetch()) throw new DomainException('E-mel ini sudah didaftarkan di sekolah ini.');
    query('INSERT INTO users (school_id,name,email,password_hash,role,must_change) VALUES (?,?,?,?,"admin",?)',[sid(),$name,$email,hash_password($password),$mustChange?1:0]);
    return (int)db()->lastInsertId();
}
/** Pentadbir platform (super-admin) — mengurus sekolah, tiada akses terus ke data sekolah. */
function create_platform_admin(string $name,string $email,string $password): int {
    if(query('SELECT id FROM platform_admins WHERE email=?',[$email])->fetch()) throw new DomainException('E-mel ini sudah didaftarkan sebagai pentadbir platform.');
    query('INSERT INTO platform_admins (name,email,password_hash) VALUES (?,?,?)',[$name,$email,hash_password($password)]);
    return (int)db()->lastInsertId();
}
/** Jadual enjin agihan tugas mengikut susunan cipta (induk dahulu). Rujukan: database/schema.sql. */
const ENGINE_TABLES=['teachers','positions','assignments','position_rules','allocation_config','allocation_plans','workflow_revisions','workflow_state','school_assets','api_keys'];
/** DDL satu jadual daripada database/schema.sql (sumber tunggal untuk pemasangan baharu & naik taraf). */
function schema_ddl(string $table): string {
    $sql=(string)preg_replace('/^--.*$/m','',(string)file_get_contents(dirname(__DIR__).'/database/schema.sql'));
    if(!preg_match('/CREATE TABLE (?:IF NOT EXISTS )?'.preg_quote($table,'/').' \((?:[^;])*?\) ENGINE=InnoDB/s',$sql,$m)) throw new RuntimeException('DDL '.$table.' tiada dalam schema.sql');
    return $m[0];
}
/** Naik taraf idempoten; tiada jadual atau data dipadam. */
function run_upgrade(): array {
    $done=[];
    if(!table_exists('schools') || !column_exists('users','school_id')) throw new DomainException('Pangkalan data ini bukan pemasangan UrusTugas berbilang sekolah. Gunakan pangkalan data kosong dan jalankan pemasang.');
    // Langkah naik taraf idempoten (cth. ALTER TABLE … jika !column_exists()). DDL jadual baharu diambil daripada schema.sql.
    // 0.1-m2: enjin agihan tugas. teachers dicipta dahulu (users.teacher_id merujuknya), kemudian jadual lain ikut kebergantungan.
    $made=[]; foreach(ENGINE_TABLES as $t) if(!table_exists($t)){ db()->exec(schema_ddl($t)); $made[]=$t; }
    if(!column_exists('users','teacher_id')){
        db()->exec('ALTER TABLE users ADD COLUMN teacher_id BIGINT UNSIGNED NULL AFTER self_registered, ADD UNIQUE KEY school_teacher (school_id,teacher_id), ADD CONSTRAINT users_teacher_fk FOREIGN KEY(school_id,teacher_id) REFERENCES teachers(school_id,id)');
        $made[]='users.teacher_id';
    }
    if(!column_exists('workflow_state','return_info')){ db()->exec('ALTER TABLE workflow_state ADD COLUMN return_info TEXT NULL AFTER version'); $made[]='workflow_state.return_info'; }
    if(!column_exists('teachers','teaching_periods')){
        db()->exec("ALTER TABLE teachers ADD COLUMN teaching_periods TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER external_id, ADD COLUMN exam_class TINYINT NOT NULL DEFAULT 0 AFTER teaching_periods, ADD COLUMN status ENUM('aktif','cuti','bertukar') NOT NULL DEFAULT 'aktif' AFTER exam_class, ADD COLUMN index_exempt TINYINT NOT NULL DEFAULT 0 AFTER status, ADD CONSTRAINT teacher_periods CHECK (teaching_periods<=60)");
        $made[]='teachers (indeks beban)';
    }
    if(!column_exists('positions','level')){ db()->exec("ALTER TABLE positions ADD COLUMN level ENUM('utama','sokongan','sampingan') NOT NULL DEFAULT 'sokongan' AFTER weight, ADD COLUMN term ENUM('tahunan','bermusim','program') NOT NULL DEFAULT 'tahunan' AFTER level"); $made[]='positions.level/term'; }
    $n=db()->exec('INSERT IGNORE INTO workflow_state(school_id) SELECT id FROM schools');
    if($made) $done[]='Enjin agihan tugas: '.implode(', ',$made).' dicipta; keadaan aliran (draf) disediakan untuk '.(int)$n.' sekolah';
    write_install_lock('upgrade'); $done[]='Fail kunci pemasangan dikemas kini ke versi '.APP_VERSION;
    return $done;
}

/* ===================== Pemasang web (wizard) ===================== */

/**
 * Pastikan pelayan web boleh membaca fail awam (CSS/JS/gambar): folder 755, fail 644.
 * Hosting kongsi biasanya menjalankan PHP sebagai pemilik fail, jadi PHP boleh membetulkannya sendiri.
 * Pulangkan senarai laluan yang masih tidak boleh dibaca.
 */
function fix_public_permissions(): array {
    $base=dirname(__DIR__).'/public_html'; $bad=[];
    if(!is_dir($base)) return [];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach([new SplFileInfo($base),...$it] as $f){
        if($f->isLink()) continue;
        $path=$f->getPathname(); $perm=@fileperms($path)&0777;
        $want=$f->isDir()?0755:0644; $need=$f->isDir()?0005:0004;
        if(($perm&$need)!==$need){ @chmod($path,$want); clearstatcache(true,$path); if(((@fileperms($path)&0777)&$need)!==$need) $bad[]=substr($path,strlen(dirname(__DIR__))+1); }
    }
    return $bad;
}
/** Semakan pelayan sebelum konfigurasi wujud (tiada .env diperlukan). */
function web_requirements(): array {
    $r=[]; $root=dirname(__DIR__); $env=$root.'/.env';
    $r[]=['PHP 8.2 atau lebih baharu',version_compare(PHP_VERSION,'8.2.0','>=')?'ok':'fail','Versi semasa '.PHP_VERSION];
    $missing=array_values(array_filter(['pdo_mysql','sodium','mbstring','zip','dom','gd'],fn($x)=>!extension_loaded($x)));
    $r[]=['Sambungan PHP (pdo_mysql, sodium, mbstring, zip, dom, gd)',$missing?'fail':'ok',$missing?'Tiada: '.implode(', ',$missing).' — aktifkan di cPanel → Select PHP Version → Extensions':'Semua tersedia'];
    $r[]=['Argon2id & GD WebP',(defined('PASSWORD_ARGON2ID') && function_exists('imagewebp'))?'ok':'fail',(defined('PASSWORD_ARGON2ID') && function_exists('imagewebp'))?'Tersedia':'Minta pihak hosting mengaktifkan libargon2/libsodium dan GD WebP'];
    $r[]=['Sambungan PHP curl (sandaran Google Drive)',extension_loaded('curl')?'ok':'warn',extension_loaded('curl')?'Tersedia':'Pilihan — aktifkan di cPanel → Select PHP Version → Extensions untuk sandaran Google Drive'];
    $badPublic=fix_public_permissions();
    $r[]=['Fail CSS/JS boleh dibaca pelayan web',$badPublic?'fail':'ok',$badPublic?'Tetapkan kebenaran 755 (folder) / 644 (fail) untuk: '.implode(', ',array_slice($badPublic,0,4)):'Kebenaran 755/644 betul'];
    $st=$root.'/storage'; $stOk=is_dir($st) && is_writable($st);
    $r[]=['Folder storage/ boleh ditulis',$stOk?'ok':'fail',$stOk?'Boleh ditulis':'Tetapkan folder storage kepada 755 (pemilik akaun anda)'];
    $envOk=is_file($env)?is_writable($env):is_writable($root);
    $r[]=['Fail konfigurasi .env boleh dicipta',$envOk?'ok':'warn',$envOk?'Pemasang akan menulis .env secara automatik':'Folder tidak boleh ditulis — kandungan .env akan dipaparkan untuk disalin'];
    $https=request_is_https();
    $r[]=['Sambungan HTTPS',$https?'ok':'warn',$https?'Selamat':'Disyorkan: pasang SSL (cPanel → SSL/TLS Status / AutoSSL) sebelum digunakan'];
    $docroot=realpath((string)($_SERVER['DOCUMENT_ROOT']??'')) ?: ''; $project=realpath($root) ?: $root;
    $bad=$docroot!=='' && ($docroot===$project || !str_starts_with($docroot,$project.DIRECTORY_SEPARATOR));
    $r[]=['Folder sulit di luar web root',$bad?'fail':'ok',$bad?'Hanya isi folder public_html boleh berada dalam web root. Pindahkan folder lain satu aras di atas':'Betul'];
    return $r;
}

/** Hos pangkalan data mesti tempatan/rangkaian peribadi supaya pemasang tidak boleh dihalakan ke pelayan DB orang lain. */
function db_host_allowed(string $host): bool {
    $host=trim($host,'[] ');
    if($host==='' || strlen($host)>120 || !preg_match('/^[A-Za-z0-9._:-]+$/D',$host)) return false;
    if(in_array(strtolower($host),['localhost','127.0.0.1','::1'],true)) return true;
    $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:(gethostbynamel($host)?:[]);
    if(!$ips) return false;
    foreach($ips as $ip) if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false) return false; // IP awam ditolak
    return true;
}
function db_config_from_input(): array {
    $c=['host'=>trim(input('db_host'))?:'localhost','port'=>trim(input('db_port'))?:'3306','name'=>trim(input('db_name')),'user'=>trim(input('db_user')),'pass'=>input('db_pass')];
    if(!db_host_allowed($c['host'])) throw new DomainException('Hos pangkalan data mesti "localhost" atau pelayan dalam rangkaian peribadi.');
    if(!ctype_digit($c['port']) || (int)$c['port']<1 || (int)$c['port']>65535) throw new DomainException('Port pangkalan data tidak sah.');
    if(!preg_match('/^[A-Za-z0-9_$-]{1,64}$/D',$c['name'])) throw new DomainException('Nama pangkalan data tidak sah (huruf, nombor, _ sahaja).');
    if($c['user']==='' || strlen($c['user'])>80 || preg_match('/[\s"\'\\\\=#]/',$c['user'])) throw new DomainException('Nama pengguna pangkalan data tidak sah.');
    if($c['pass']==='' || strlen($c['pass'])>200 || preg_match('/[\r\n]/',$c['pass']) || $c['pass']!==trim($c['pass'])) throw new DomainException('Kata laluan pangkalan data diperlukan (tanpa ruang di awal/akhir).');
    return $c;
}
function install_connect(array $c): PDO {
    try {
        return new PDO('mysql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['name'].';charset=utf8mb4',$c['user'],$c['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
    } catch(PDOException $ex) {
        $m=$ex->getMessage();
        throw new DomainException(match(true){
            str_contains($m,'1045')=>'Pengguna atau kata laluan pangkalan data salah.',
            str_contains($m,'1044') || str_contains($m,'1049')=>'Pangkalan data "'.$c['name'].'" tidak wujud atau pengguna belum diberi akses (cPanel → MySQL Databases → Add User To Database → ALL PRIVILEGES).',
            str_contains($m,'2002') || str_contains($m,'2005')=>'Pelayan pangkalan data tidak dapat dihubungi. Untuk cPanel, gunakan hos "localhost".',
            default=>'Sambungan pangkalan data gagal. Semak semua butiran.'});
    }
}
/** Pastikan DB kosong dan pengguna mempunyai hak mencipta jadual. */
function check_db_ready(PDO $pdo): void {
    $n=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    if($n>0) throw new DomainException('Pangkalan data ini tidak kosong ('.$n.' jadual). Gunakan pangkalan data baharu yang kosong.');
    try { $pdo->exec('CREATE TABLE urustugas_install_check (id INT) ENGINE=InnoDB'); $pdo->exec('DROP TABLE urustugas_install_check'); }
    catch(PDOException) { throw new DomainException('Pengguna pangkalan data tiada hak mencipta jadual. Beri ALL PRIVILEGES di cPanel.'); }
    $v=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
    if(stripos($v,'mariadb')===false && version_compare($v,'8.0.0','<')) throw new DomainException('Memerlukan MySQL 8.0+ atau MariaDB 10.6+ (semasa: '.$v.').');
}
function env_content(array $c,string $appKey,bool $secure,array $extra=[]): string {
    $q=fn(string $v)=>preg_match('/^[A-Za-z0-9_.@:\/+=-]*$/D',$v)?$v:'"'.$v.'"';
    return "# Dijana oleh pemasang UrusTugas pada ".date('Y-m-d H:i')."\n# JANGAN kongsi fail ini. Simpan salinan APP_KEY di tempat selamat: tanpanya data sulit tidak boleh dipulihkan.\n"
        ."APP_NAME=\"UrusTugas\"\nAPP_ENV=production\nAPP_KEY=".$appKey."\n"
        ."DB_HOST=".$q($c['host'])."\nDB_PORT=".$c['port']."\nDB_NAME=".$q($c['name'])."\nDB_USER=".$q($c['user'])."\nDB_PASS=".$q($c['pass'])."\n"
        ."SESSION_SECURE=".($secure?'1':'0')."\nREQUIRE_2FA=".($extra['REQUIRE_2FA']??'1')."\n"
        .implode('',array_map(fn($k,$v)=>$k.'='.$q((string)$v)."\n",array_keys(array_diff_key($extra,['REQUIRE_2FA'=>1])),array_diff_key($extra,['REQUIRE_2FA'=>1])));
}
/** Tulis .env secara atomik (salinan lama disimpan sebagai .env.bak-*). Pulangkan false jika folder tidak boleh ditulis. */
function write_env_file(string $content): bool {
    $root=dirname(__DIR__); $env=$root.'/.env'; $tmp=$root.'/.env.tmp-'.bin2hex(random_bytes(4));
    if(@file_put_contents($tmp,$content,LOCK_EX)===false) return false;
    @chmod($tmp,0600);
    if(is_file($env)) @rename($env,$root.'/.env.bak-'.date('Ymd-His'));
    if(!@rename($tmp,$env)) { @unlink($tmp); return false; }
    return true;
}

/* ---------- Had cubaan berasaskan fail (jadual rate_limits mungkin belum wujud) ---------- */
function install_attempts_allowed(string $ip,bool $hit=false): bool {
    $path=dirname(__DIR__).'/storage/install-attempts.json';
    $fh=@fopen($path,'c+'); if(!$fh) return true;
    try {
        flock($fh,LOCK_EX); $data=json_decode((string)stream_get_contents($fh),true) ?: [];
        $key=hash('sha256',$ip); $now=time();
        $data=array_filter($data,fn($v)=>$now-($v['t']??0)<900);
        $count=$data[$key]['n']??0;
        if($hit){ $data[$key]=['n'=>$count+1,'t'=>$data[$key]['t']??$now]; ftruncate($fh,0); rewind($fh); fwrite($fh,json_encode($data)); }
        return $count<10;
    } finally { flock($fh,LOCK_UN); fclose($fh); @chmod($path,0600); }
}

/* ---------- Input CLI (tanpa bergantung kepada sambungan readline) ---------- */
function cli_ask(string $prompt): string {
    if(function_exists('readline')) return trim((string)readline($prompt));
    echo $prompt; return trim((string)fgets(STDIN));
}
function cli_secret(string $prompt): string {
    $tty=function_exists('shell_exec') ? trim((string)@shell_exec('stty -g 2>/dev/null')) : '';
    if($tty==='') { fwrite(STDERR,"Terminal tidak menyokong input tersembunyi. Gunakan terminal interaktif (SSH).\n"); exit(1); }
    shell_exec('stty -echo');
    try { echo $prompt; $v=rtrim((string)fgets(STDIN),"\r\n"); echo PHP_EOL; } finally { shell_exec('stty '.escapeshellarg($tty)); }
    return $v;
}
function cli_print_requirements(array $r): void {
    foreach($r as [$label,$status,$detail]) echo ['ok'=>'  [OK]   ','warn'=>'  [AMARAN]','fail'=>'  [GAGAL]'][$status],' ',$label,' — ',$detail,PHP_EOL;
}
