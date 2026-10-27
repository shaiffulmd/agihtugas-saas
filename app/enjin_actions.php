<?php
declare(strict_types=1);
/* Tindakan POST enjin agihan tugas (dipanggil oleh handle_action()). Setiap tindakan:
 *  peranan disemak → transaksi → (data perancangan: mesti Draf) → perubahan ditapis sid() → audit → PRG. */

const ENGINE_ACTIONS=['guru_save','guru_delete','guru_bulk_preview','guru_bulk_apply','jawatan_save','jawatan_delete','jawatan_bulk_preview','jawatan_bulk_apply',
    'agih','agih_lepas','syarat_umum','syarat_jawatan','auto_preview','auto_apply','kelulusan','sekolah_profil','sekolah_logo','sekolah_logo_buang','sekolah_kepala','api_cipta','api_batal','indeks_tetapan'];

function post_int(string $key,int $min,int $max,string $label): int {
    $n=filter_var(input($key),FILTER_VALIDATE_INT); if($n===false || $n<$min || $n>$max) throw new EngineError($label.' di luar julat yang dibenarkan ('.$min.'–'.$max.').'); return $n;
}
function post_ids(string $key): array { $v=$_POST[$key]??[]; if(!is_array($v)) throw new EngineError('Senarai guru tidak sah.'); return array_values(array_map('intval',array_filter($v,'is_string'))); }

function handle_engine_action(string $action): never {
    $me=current_user();
    $adminOnly=in_array($action,['sekolah_profil','sekolah_logo','sekolah_logo_buang','sekolah_kepala','api_cipta','api_batal'],true);
    if($action==='kelulusan'){ if(!in_array($me['role'],['admin','pk','head'],true)) error_page(403); }
    elseif($adminOnly) require_admin();
    else require_planner();
    // Ciri Pro (pakej Free): disemak sebelum transaksi.
    $feature=['auto_preview'=>'auto','auto_apply'=>'auto','syarat_umum'=>'auto','syarat_jawatan'=>'auto','api_cipta'=>'api',
        'guru_bulk_preview'=>'pukal','guru_bulk_apply'=>'pukal','jawatan_bulk_preview'=>'pukal','jawatan_bulk_apply'=>'pukal'][$action]??null;
    if($feature!==null) require_feature($feature);
    $back=['guru_save'=>'guru','guru_delete'=>'guru','guru_bulk_preview'=>'guru','guru_bulk_apply'=>'guru','jawatan_save'=>'jawatan','jawatan_delete'=>'jawatan','jawatan_bulk_preview'=>'pukal','jawatan_bulk_apply'=>'pukal',
        'agih'=>'agihan','agih_lepas'=>'agihan','indeks_tetapan'=>'analisis','syarat_umum'=>'syarat','syarat_jawatan'=>'syarat','auto_preview'=>'auto','auto_apply'=>'auto','kelulusan'=>'kelulusan','api_cipta'=>'api','api_batal'=>'api'][$action]??'sekolah';
    db()->beginTransaction();
    try {
        // Data yang masuk ke snapshot revisi dikunci selain Draf (API, kunci API & tetapan indeks tidak termasuk).
        if(!in_array($action,['kelulusan','api_cipta','api_batal','indeks_tetapan'],true)) workflow_require_draft();
        $id=(int)input('id'); $msg=''; $anchor='';
        switch($action){
            case 'guru_save':
                $tid=teacher_save($id,['name'=>input('name'),'email'=>input('email'),'specialty'=>input('specialty'),'capacity'=>post_int('capacity',1,100,'Kapasiti'),
                    'teaching_periods'=>input('teaching_periods')===''?0:post_int('teaching_periods',0,60,'Masa mengajar'),'exam_class'=>input('exam_class')==='1','status'=>input('status')?:'aktif','index_exempt'=>input('index_exempt')==='1']);
                audit($id?'teacher_update':'teacher_create','teachers',$tid,mb_substr(input('name'),0,120)); $msg='Maklumat guru disimpan.'; break;
            case 'guru_delete': $t=teacher_find($id); teacher_delete($id); audit('teacher_delete','teachers',$id,(string)($t['name']??'')); $msg='Rekod guru dipadam.'; break;
            case 'guru_bulk_preview':
                $rows=bulk_teachers_parse(bulk_source()); $_SESSION['guru_pukal']=['token'=>bin2hex(random_bytes(24)),'rows'=>$rows,'sid'=>sid()];
                db()->commit(); redirect('/?page=guru&pukal=1#pukal-hasil');
            case 'guru_bulk_apply':
                $d=$_SESSION['guru_pukal']??null; if(!$d || $d['sid']!==sid() || !hash_equals($d['token'],input('token'))) throw new EngineError('Pratonton pukal tidak sah. Jana semula.',409);
                $rows=bulk_teachers_validate($d['rows']); // disemak semula: data mungkin berubah sejak pratonton
                free_guard('guru',count($rows)); foreach($rows as $r) query('INSERT INTO teachers (school_id,name,email,specialty,capacity,teaching_periods) VALUES ('.sid().',?,?,?,?,?)',[$r['name'],$r['email'],$r['specialty'],$r['capacity'],$r['teaching_periods']??0]);
                unset($_SESSION['guru_pukal']); audit('teacher_bulk','teachers',null,count($rows).' guru'); $msg=count($rows).' guru ditambah secara pukal.'; break;
            case 'jawatan_save':
                $pid=position_save($id,['name'=>input('name'),'category'=>input('category'),'weight'=>post_int('weight',1,10,'Pemberat'),'level'=>input('level')?:'sokongan','term'=>input('term')?:'tahunan']);
                audit($id?'position_update':'position_create','positions',$pid,mb_substr(input('name'),0,120)); $msg='Jawatan dan pemberat disimpan.'; break;
            case 'jawatan_delete': $p=position_find($id); position_delete($id); audit('position_delete','positions',$id,(string)($p['name']??'')); $msg='Rekod jawatan dipadam.'; break;
            case 'jawatan_bulk_preview':
                $rows=bulk_positions_parse(bulk_source()); $_SESSION['jawatan_pukal']=['token'=>bin2hex(random_bytes(24)),'rows'=>$rows,'sid'=>sid()];
                db()->commit(); redirect('/?page=pukal#pukal-hasil');
            case 'jawatan_bulk_apply':
                $d=$_SESSION['jawatan_pukal']??null; if(!$d || $d['sid']!==sid() || !hash_equals($d['token'],input('token'))) throw new AllocationError('Pratonton pukal tidak sah. Jana semula.',409);
                $ids=bulk_positions_insert(bulk_positions_validate(array_map(fn($r)=>$r+['count'=>1],$d['rows']))); unset($_SESSION['jawatan_pukal']);
                audit('position_bulk','positions',null,count($ids).' jawatan'); $msg=count($ids).' jawatan ditambah secara pukal.'; break;
            case 'agih':
                $pid=post_int('position_id',1,PHP_INT_MAX,'Jawatan'); $tid=post_int('teacher_id',1,PHP_INT_MAX,'Guru');
                $aid=assign_position($pid,$tid); audit('assign','assignments',$aid,'jawatan #'.$pid.' → guru #'.$tid); $msg='Agihan tugas dikemas kini.'; break;
            case 'agih_lepas': $a=unassign($id); audit('unassign','assignments',$id,(string)$a['position_name']); $msg='Lantikan dilepaskan. Jawatan kini kosong.'; break;
            case 'indeks_tetapan':
                index_settings_save(['teach_share'=>input('teach_share'),'high'=>input('high'),'low'=>input('low'),'exam_factor'=>input('exam_factor'),'max_tasks'=>input('max_tasks')]);
                audit('load_index_settings','settings'); $msg='Tetapan indeks beban disimpan. Indeks dikira semula.'; break;
            case 'syarat_umum':
                $limits=[]; foreach(CATEGORIES as $i=>$cat) $limits[$cat]=post_int('category_'.$i,0,50,'Had bidang '.$cat);
                allocation_save_rules(['respect_capacity'=>input('respect_capacity')==='1','max_tasks'=>post_int('max_tasks',1,50,'Maksimum tugas'),'max_heavy'=>post_int('max_heavy',0,50,'Maksimum tugas berat'),'heavy_threshold'=>post_int('heavy_threshold',1,10,'Ambang tugas berat'),'category_limits'=>$limits,'excluded_teachers'=>post_ids('excluded_teachers')]);
                audit('allocation_rules','allocation_config',null); $msg='Syarat agihan automatik disimpan.'; break;
            case 'syarat_jawatan':
                $pid=post_int('position_id',1,PHP_INT_MAX,'Jawatan');
                allocation_save_position_rule($pid,['allowed_teachers'=>post_ids('allowed_teachers'),'fixed_teacher_id'=>input('fixed_teacher_id')===''?null:post_int('fixed_teacher_id',1,PHP_INT_MAX,'Penyandang tetap'),'required_specialty'=>input('required_specialty')]);
                audit('position_rule','positions',$pid); $msg='Syarat khusus jawatan disimpan.'; $anchor='&jawatan='.$pid.'#syarat-jawatan'; break;
            case 'auto_preview':
                $plan=allocation_preview(input('mode')); db()->commit(); $_SESSION['auto_plan']=['sid'=>sid(),'plan'=>$plan]; redirect('/?page=auto#auto-hasil');
            case 'auto_apply':
                $r=allocation_apply(input('plan_id')); unset($_SESSION['auto_plan']); audit('allocation_apply','allocation_plans',null,$r['changed'].' perubahan, '.$r['unfilled'].' kosong');
                $msg='Agihan automatik disimpan: '.$r['changed'].' perubahan, '.$r['unfilled'].' jawatan belum diisi.'; break;
            case 'kelulusan':
                workflow_transition(input('transition'),post_int('version',1,PHP_INT_MAX,'Versi'),$me,input('reason'),array_map('intval',array_filter((array)($_POST['flag']??[]),'is_scalar'))); $msg='Status semakan dan kelulusan dikemas kini.'; break;
            case 'sekolah_profil':
                $p=trim(input('principal')); $ref=trim(input('letter_reference'));
                if($p==='' || mb_strlen($p)>160 || $ref==='' || mb_strlen($ref)>160) throw new EngineError('Nama '.head_title().' dan rujukan surat diperlukan (maksimum 160 aksara).');
                engine_setting_set(['principal'=>$p,'session_year'=>(string)post_int('session_year',2020,2100,'Tahun sesi'),'letter_reference'=>$ref]);
                audit('school_profile','settings'); $msg='Profil sekolah untuk surat disimpan.'; break;
            case 'sekolah_logo': engine_setting_set(['school_logo_id'=>branding_upload($_FILES['school_logo']??null)]); audit('letter_logo','settings'); $msg='Logo sekolah untuk surat disimpan.'; break;
            case 'sekolah_logo_buang': engine_setting_set(['school_logo_id'=>'']); audit('letter_logo_removed','settings'); $msg='Logo dibuang daripada draf. Surat muktamad terdahulu tidak berubah.'; break;
            case 'sekolah_kepala':
                $l=input('letter_header'); if(!isset(LETTER_HEADERS[$l])) throw new EngineError('Gaya kepala surat tidak sah.');
                engine_setting_set(['letter_header'=>$l]); audit('letter_header','settings',null,$l); $msg='Gaya kepala surat disimpan.'; break;
            case 'api_cipta':
                [$kid,$token]=api_key_create(input('name'),input('scope')); audit('api_key_create','api_keys',$kid,input('scope'));
                $_SESSION['api_token_baru']=['sid'=>sid(),'token'=>$token]; $msg='Kunci API dicipta. Salin sekarang — ia tidak akan dipaparkan lagi.'; break;
            case 'api_batal':
                if(!query('UPDATE api_keys SET revoked_at=COALESCE(revoked_at,NOW()) WHERE id=? AND school_id='.sid(),[$id])->rowCount() && !query('SELECT 1 FROM api_keys WHERE id=? AND school_id='.sid(),[$id])->fetchColumn()) throw new EngineError('Kunci API tidak ditemui.',404,'not_found');
                audit('api_key_revoke','api_keys',$id); $msg='Kunci API dibatalkan.'; break;
            default: throw new EngineError('Tindakan tidak dikenali.');
        }
        db()->commit();
    } catch(Throwable $ex){ if(db()->inTransaction()) db()->rollBack(); throw $ex; }
    flash($msg); redirect('/?page='.$back.$anchor);
}
