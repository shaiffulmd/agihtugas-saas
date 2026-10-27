<?php
declare(strict_types=1);
/* Pengurusan akaun oleh pentadbir: kemas kini, nyahaktif, reset kata laluan dan reset 2FA. */

/** Peranan sekolah UrusTugas. 'head' dipaparkan sebagai Guru Besar (SK) atau Pengetua (SMK) — lihat role_label(). */
const ROLE_LABELS = ['admin'=>'Pentadbir','pk'=>'Penolong Kanan','head'=>'Guru Besar / Pengetua','teacher'=>'Guru'];
function role_label(string $role): string { return $role==='head'?head_title():(ROLE_LABELS[$role]??$role); }
/** Senarai pilihan peranan dengan label GB/Pengetua mengikut jenis sekolah. */
function role_options(): array { return array_combine(array_keys(ROLE_LABELS),array_map('role_label',array_keys(ROLE_LABELS))); }

function confirm_admin_password(): void {
    $me=query('SELECT id,password_hash FROM users WHERE id=? AND school_id='.sid(),[current_user()['id']])->fetch();
    $rk=rate_key('admin-confirm',(string)$me['id']);
    if(!rate_allowed($rk)) throw new DomainException('Terlalu banyak cubaan pengesahan. Cuba selepas 15 minit.');
    if(!password_verify(input('admin_password'),$me['password_hash'])){ rate_hit($rk); audit('admin_confirm_failed','users',(int)$me['id']); throw new DomainException('Kata laluan anda (pengesahan pentadbir) tidak sah.'); }
}
/** Profil guru bagi akaun: wajib & unik untuk peranan Guru, pilihan untuk peranan lain (cth. PK yang juga guru). */
function user_teacher_link(string $role,int $userId): ?int {
    $tid=(int)input('teacher_id');
    if(!$tid){ if($role==='teacher') throw new DomainException('Akaun guru mesti dipautkan kepada profil guru.'); return null; }
    if(!teacher_find($tid)) throw new DomainException('Profil guru tidak ditemui.');
    if(query('SELECT 1 FROM users WHERE school_id='.sid().' AND teacher_id=? AND id<>?',[$tid,$userId])->fetchColumn()) throw new DomainException('Profil guru telah dipautkan kepada akaun lain.');
    return $tid;
}
function active_admin_count(): int { return (int)query('SELECT COUNT(*) FROM users WHERE school_id='.sid().' AND role="admin" AND active=1')->fetchColumn(); }
function handle_user_admin(string $action): never {
    require_admin();
    $id=(int)input('user_id'); $target=query('SELECT * FROM users WHERE id=? AND school_id='.sid(),[$id])->fetch();
    if(!$target) throw new DomainException('Pengguna tidak dijumpai.');
    $self=$id===(int)current_user()['id'];
    confirm_admin_password();
    if($action==='user_update'){
        $name=trim(input('name')); $role=input('role'); $active=input('active')==='1'?1:0;
        if($name==='' || mb_strlen($name)>120) throw new DomainException('Nama tidak sah.');
        if(!isset(ROLE_LABELS[$role])) throw new DomainException('Peranan tidak sah.');
        if($self && ($role!=='admin' || !$active)) throw new DomainException('Anda tidak boleh menurunkan peranan atau menyahaktifkan akaun sendiri.');
        if($target['role']==='admin' && (int)$target['active']===1 && ($role!=='admin' || !$active) && active_admin_count()<=1) throw new DomainException('Sekurang-kurangnya seorang pentadbir aktif diperlukan.');
        if($active===1 && (int)$target['active']===0) free_guard('pengguna',1);
        $tid=user_teacher_link($role,$id); $changedAccess=$role!==$target['role'] || $active!==(int)$target['active'] || $tid!==($target['teacher_id']!==null?(int)$target['teacher_id']:null);
        query('UPDATE users SET name=?,role=?,active=?,teacher_id=?'.($changedAccess?',auth_version=auth_version+1':'').' WHERE id=? AND school_id='.sid(),[$name,$role,$active,$tid,$id]);
        audit($active?($changedAccess?'user_access_changed':'user_updated'):'user_deactivated','users',$id);
        flash($active?'Akaun dikemas kini.':'Akaun dinyahaktifkan. Sesi aktif pengguna ditamatkan.');
    } elseif($action==='user_ips'){
        [$list,$err]=ip_list_form(input('allowed_ips')); if($err) throw new DomainException($err);
        if($self && $list!=='' && !ip_in_list(client_ip(),ip_list($list))) throw new DomainException('Senarai mesti mengandungi IP anda sekarang ('.client_ip().') — elak terkunci keluar.');
        query('UPDATE users SET allowed_ips=?,auth_version=auth_version+1 WHERE id=? AND school_id='.sid(),[$list!==''?$list:null,$id]);
        audit('user_ips','users',$id); flash($list!==''?'Had IP akaun disimpan. Sesi aktif pengguna ditamatkan.':'Had IP akaun dibuang.');
    } elseif($action==='user_reset_password'){
        if($self) throw new DomainException('Gunakan halaman Profil & Keselamatan untuk menukar kata laluan sendiri.');
        $password=input('password'); if(!valid_password($password)) throw new DomainException('Kata laluan sementara mesti 12 hingga 128 aksara.');
        query('UPDATE users SET password_hash=?,must_change=1,auth_version=auth_version+1 WHERE id=? AND school_id='.sid(),[hash_password($password),$id]);
        audit('admin_password_reset','users',$id); flash('Kata laluan sementara ditetapkan. Pengguna wajib menukarnya selepas log masuk.');
    } elseif($action==='user_reset_2fa'){
        if($self) throw new DomainException('Reset 2FA akaun sendiri hanya melalui CLI pelayan.');
        db()->beginTransaction();
        query('UPDATE users SET totp_secret=NULL,last_totp_step=-1,auth_version=auth_version+1 WHERE id=? AND school_id='.sid(),[$id]);
        query('DELETE FROM recovery_codes WHERE school_id='.sid().' AND user_id=?',[$id]);
        audit('admin_2fa_reset','users',$id); db()->commit();
        flash('2FA ditetapkan semula. Pengguna perlu mendaftar semula aplikasi pengesah pada log masuk seterusnya.');
    } else throw new DomainException('Tindakan tidak sah.');
    redirect('/?page=users&user='.$id);
}

const AUDIT_LABELS = [
    'login'=>'Log masuk','logout'=>'Log keluar','login_failed'=>'Log masuk gagal','login_blocked'=>'Log masuk disekat (had cubaan)',
    '2fa_failed'=>'Kod 2FA gagal','2fa_enabled'=>'2FA diaktifkan','2fa_disabled'=>'2FA dimatikan','password_changed'=>'Tukar kata laluan','admin_confirm_failed'=>'Pengesahan pentadbir gagal',
    'create'=>'Cipta','update'=>'Kemas kini','delete'=>'Padam','save'=>'Simpan','export'=>'Eksport CSV','download_pdf'=>'Muat turun PDF',
    'user_updated'=>'Kemas kini pengguna','user_access_changed'=>'Tukar akses pengguna','user_deactivated'=>'Nyahaktif pengguna','user_ips'=>'Had IP akaun',
    'admin_password_reset'=>'Reset kata laluan (pentadbir)','admin_2fa_reset'=>'Reset 2FA (pentadbir)','settings_saved'=>'Simpan tetapan & tema',
    'theme_reset'=>'Reset tema','logo_removed'=>'Buang logo','install'=>'Pemasangan','upgrade'=>'Naik taraf',
    'register'=>'Daftar kendiri','register_duplicate'=>'Cubaan daftar e-mel sedia ada','register_approved'=>'Lulus pendaftaran','register_rejected'=>'Tolak pendaftaran','email_verified'=>'Sahkan e-mel',
    'password_reset_requested'=>'Minta reset kata laluan','password_reset'=>'Reset kata laluan (e-mel)','login_inactive'=>'Log masuk akaun tidak aktif','login_ip_denied'=>'Log masuk ditolak (IP)','session_ip_denied'=>'Sesi ditolak (IP)','bot_blocked'=>'Bot disekat',
    'reg_settings'=>'Tetapan pendaftaran & e-mel','mail_test'=>'Uji e-mel','avatar_saved'=>'Tukar gambar profil','avatar_removed'=>'Buang gambar profil','avatar_admin_removed'=>'Buang gambar profil (pentadbir)',
    'teacher_create'=>'Tambah guru','teacher_update'=>'Kemas kini guru','teacher_delete'=>'Padam guru','teacher_bulk'=>'Tambah guru pukal','teacher_sync'=>'Segerak guru (API)',
    'position_create'=>'Tambah jawatan','position_update'=>'Kemas kini jawatan','position_delete'=>'Padam jawatan','position_bulk'=>'Tambah jawatan pukal','position_rule'=>'Syarat jawatan',
    'assign'=>'Lantik guru','unassign'=>'Lepaskan lantikan','allocation_rules'=>'Syarat agihan automatik','allocation_apply'=>'Simpan agihan automatik',
    'workflow_submit'=>'Hantar untuk semakan','workflow_approve'=>'Luluskan agihan','workflow_finalize'=>'Muktamadkan agihan','workflow_return'=>'Kembalikan kepada draf',
    'school_profile'=>'Profil sekolah (surat)','letter_logo'=>'Logo surat','letter_logo_removed'=>'Buang logo surat','letter_header'=>'Gaya kepala surat','letter_format'=>'Format surat','flag_resolved'=>'Guru ditanda selesai dibaiki','flag_reopened'=>'Batal tanda selesai','subject_create'=>'Tambah mata pelajaran','subject_update'=>'Kemas kini mata pelajaran','subject_delete'=>'Padam mata pelajaran','subject_import'=>'Import senarai mata pelajaran','api_key_create'=>'Cipta kunci API','api_key_revoke'=>'Batal kunci API',
    'cli_create'=>'Cipta pentadbir (CLI)','cli_password'=>'Tukar kata laluan (CLI)','cli_2fa_reset'=>'Reset 2FA (CLI)',
];
function audit_label(string $a): string { return AUDIT_LABELS[$a]??$a; }
function audit_filters(): array {
    $f=['action'=>input('action','get'),'user'=>(int)input('user','get'),'from'=>input('from','get'),'to'=>input('to','get'),'security'=>input('security','get')==='1'];
    foreach(['from','to'] as $k){ $d=DateTimeImmutable::createFromFormat('!Y-m-d',$f[$k]); if(!$d || $d->format('Y-m-d')!==$f[$k]) $f[$k]=''; }
    if(!preg_match('/^[a-z0-9_]{1,60}$/D',$f['action'])) $f['action']='';
    return $f;
}
function audit_where(array $f): array {
    $w=['a.school_id='.sid()]; $p=[];
    if($f['action']!==''){ $w[]='a.action=?'; $p[]=$f['action']; }
    if($f['user']){ $w[]='(a.user_id=? OR (a.entity="users" AND a.entity_id=?))'; $p[]=$f['user']; $p[]=$f['user']; }
    if($f['from']!==''){ $w[]='a.created_at>=?'; $p[]=$f['from'].' 00:00:00'; }
    if($f['to']!==''){ $w[]='a.created_at<=?'; $p[]=$f['to'].' 23:59:59'; }
    if($f['security']) $w[]='a.action IN ("login_failed","login_blocked","2fa_failed","admin_confirm_failed","admin_password_reset","admin_2fa_reset","user_deactivated","user_access_changed","password_changed","login_ip_denied","session_ip_denied")';
    return [$w?' WHERE '.implode(' AND ',$w):'',$p];
}
