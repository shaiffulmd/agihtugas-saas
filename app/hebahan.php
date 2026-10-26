<?php
declare(strict_types=1);
/* Hebahan guru: pentadbir menghantar pengumuman dalaman kepada semua guru atau guru tertentu.
 * Isi ditulis dengan editor WYSIWYG (textarea[data-rich]) dan dibersihkan oleh sanitize_rich() di pelayan.
 * Setiap hebahan ada gaya kad (warna & ikon). Bacaan direkod supaya pentadbir boleh melihat siapa sudah membaca. */

const HEBAHAN_GAYA=[
    'makluman' =>['Makluman','info','Makluman umum kepada guru'],
    'penting'  =>['Penting','alert','Perkara segera yang perlu perhatian'],
    'mesyuarat'=>['Mesyuarat','calendar','Panggilan mesyuarat atau taklimat'],
    'tugasan'  =>['Tugasan','clipboard','Tugasan atau tarikh akhir penghantaran'],
    'tahniah'  =>['Tahniah','award','Ucapan tahniah dan penghargaan'],
];
const HEBAHAN_MAX=20000;

function hebahan_ready(): bool { static $ok=null; return $ok??=table_exists('staff_notices'); }
/** Penerima hebahan: semua peranan sekolah selain pentadbir (PK, GB/Pengetua, guru). */
const HEBAHAN_ROLES=['pk','head','teacher'];
function hebahan_recipients(): array { return query('SELECT id,name,email FROM users WHERE school_id='.sid().' AND active=1 AND role IN ("pk","head","teacher") AND (approval="approved" OR approval IS NULL) ORDER BY name')->fetchAll(); }
function hebahan_gaya(string $g): string { return isset(HEBAHAN_GAYA[$g])?$g:'makluman'; }

/** Syarat SQL hebahan yang boleh dilihat oleh guru semasa (alias n). */
function hebahan_visible_sql(): string {
    $uid=(int)(current_user()['id']??0);
    return 'n.school_id='.sid().' AND n.status="terbit" AND (n.expire_at IS NULL OR n.expire_at>=CURDATE()) AND (n.audience="semua" OR EXISTS(SELECT 1 FROM staff_notice_targets t WHERE t.notice_id=n.id AND t.user_id='.$uid.'))';
}
/** Hebahan untuk guru semasa (terbaharu dahulu, disematkan di atas). */
function hebahan_for_me(bool $unreadOnly=false,int $limit=50): array {
    if(!hebahan_ready() || !in_array(current_user()['role']??'',HEBAHAN_ROLES,true)) return [];
    $uid=(int)current_user()['id'];
    return query('SELECT n.*,p.name author,r.read_at FROM staff_notices n LEFT JOIN users p ON p.id=n.created_by LEFT JOIN staff_notice_reads r ON r.notice_id=n.id AND r.user_id=?
        WHERE '.hebahan_visible_sql().($unreadOnly?' AND r.read_at IS NULL':'').' ORDER BY n.pinned DESC,n.created_at DESC,n.id DESC LIMIT '.max(1,$limit),[$uid])->fetchAll();
}
function hebahan_unread_count(): int {
    if(!hebahan_ready() || !in_array(current_user()['role']??'',HEBAHAN_ROLES,true)) return 0;
    $uid=(int)current_user()['id'];
    return (int)query('SELECT COUNT(*) FROM staff_notices n WHERE '.hebahan_visible_sql().' AND NOT EXISTS(SELECT 1 FROM staff_notice_reads r WHERE r.notice_id=n.id AND r.user_id=?)',[$uid])->fetchColumn();
}
/** Bilangan penerima & yang sudah membaca bagi satu hebahan. */
function hebahan_reach(array $n): array {
    $total=$n['audience']==='semua'?count(hebahan_recipients()):(int)query('SELECT COUNT(*) FROM staff_notice_targets t JOIN users u ON u.id=t.user_id WHERE t.school_id='.sid().' AND t.notice_id=? AND u.active=1',[$n['id']])->fetchColumn();
    $read=(int)query('SELECT COUNT(*) FROM staff_notice_reads r JOIN users u ON u.id=r.user_id AND u.role IN ("pk","head","teacher") WHERE r.school_id='.sid().' AND r.notice_id=?'.($n['audience']==='semua'?'':' AND r.user_id IN (SELECT user_id FROM staff_notice_targets WHERE notice_id=r.notice_id)'),[$n['id']])->fetchColumn();
    return [$read,$total];
}

function handle_hebahan(string $action): never {
    if(!hebahan_ready()) throw new DomainException('Modul hebahan belum dipasang. Jalankan naik taraf pangkalan data (php bin/upgrade.php).');
    $u=current_user();
    if($action==='hebahan_read'){
        $id=(int)input('id');
        $ok=in_array($u['role'],HEBAHAN_ROLES,true) && query('SELECT 1 FROM staff_notices n WHERE n.id=? AND '.hebahan_visible_sql(),[$id])->fetchColumn();
        if($ok) query('INSERT IGNORE INTO staff_notice_reads(school_id,notice_id,user_id) VALUES (?,?,?)',[sid(),$id,$u['id']]);
        $back=input('back'); $back=preg_match('~^/\?page=[a-z_]+[A-Za-z0-9_=&%.-]*$~',$back)?$back:'/?page=hebahan#h'.$id; redirect($back);
    }
    require_admin();
    if($action==='hebahan_delete'){
        $id=(int)input('id'); query('DELETE FROM staff_notices WHERE id=? AND school_id='.sid(),[$id]); audit('hebahan_deleted','staff_notices',$id);
        flash('Hebahan dipadam.'); redirect('/?page=hebahan');
    }
    if($action!=='hebahan_save') error_page(400);
    $id=(int)input('id');
    $title=trim(input('title')); if($title==='' || mb_strlen($title)>160) throw new DomainException('Tajuk perlu diisi (maksimum 160 aksara).');
    $body=sanitize_rich(input('body')); if(rich_length($body)===0) throw new DomainException('Isi hebahan perlu diisi.');
    if(mb_strlen($body)>HEBAHAN_MAX) throw new DomainException('Isi hebahan terlalu panjang.');
    $style=hebahan_gaya(input('style'));
    $aud=input('audience')==='pilih'?'pilih':'semua';
    $valid=array_column(hebahan_recipients(),'id');
    $targets=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['targets']??[])),fn($x)=>in_array($x,$valid,true))));
    if($aud==='pilih' && !$targets) throw new DomainException('Pilih sekurang-kurangnya seorang guru penerima.');
    $exp=trim(input('expire_at')); if($exp!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$exp)) throw new DomainException('Tarikh tamat tidak sah.');
    $status=input('status')==='draf'?'draf':'terbit'; $pin=input('pinned')==='1'?1:0;
    db()->beginTransaction();
    if($id){
        if(!query('SELECT 1 FROM staff_notices WHERE id=? AND school_id='.sid(),[$id])->fetchColumn()){ db()->rollBack(); error_page(404,'Hebahan tidak dijumpai.'); }
        query('UPDATE staff_notices SET title=?,body=?,style=?,audience=?,pinned=?,status=?,expire_at=? WHERE id=? AND school_id='.sid(),[$title,$body,$style,$aud,$pin,$status,$exp?:null,$id]);
        query('DELETE FROM staff_notice_targets WHERE school_id='.sid().' AND notice_id=?',[$id]);
    } else {
        query('INSERT INTO staff_notices(school_id,title,body,style,audience,pinned,status,expire_at,created_by) VALUES (?,?,?,?,?,?,?,?,?)',[sid(),$title,$body,$style,$aud,$pin,$status,$exp?:null,$u['id']]);
        $id=(int)db()->lastInsertId();
    }
    if($aud==='pilih') foreach($targets as $t) query('INSERT INTO staff_notice_targets(school_id,notice_id,user_id) VALUES (?,?,?)',[sid(),$id,$t]);
    db()->commit(); audit('hebahan_saved','staff_notices',$id);
    flash($status==='draf'?'Hebahan disimpan sebagai draf.':'Hebahan diterbitkan kepada '.($aud==='semua'?'semua guru':count($targets).' guru').'.');
    redirect('/?page=hebahan');
}

/** Kad hebahan (dashboard & senarai guru). $full = papar isi penuh. */
function hebahan_card(array $n,bool $full=false,string $back='hebahan'): void {
    $g=hebahan_gaya((string)$n['style']); [$label,$ic]=HEBAHAN_GAYA[$g]; $unread=empty($n['read_at']);
    $d=new DateTimeImmutable((string)$n['created_at']);
    ?><article class="hb-card hb-<?= $g ?><?= $unread?' is-unread':'' ?>" id="h<?= (int)$n['id'] ?>">
      <header class="hb-head"><span class="hb-ic"><?= icon($ic) ?></span><div class="hb-meta"><span class="hb-tag"><?= e($label) ?><?= $n['pinned']?' · Disematkan':'' ?></span><h4><?= e($n['title']) ?></h4><small><?= e(($n['author']??'Pentadbir').' · '.$d->format('j').' '.TAKWIM_BULAN[(int)$d->format('n')].' '.$d->format('Y, g:i a')) ?></small></div><?php if($unread): ?><span class="hb-new">Baharu</span><?php endif; ?></header>
      <div class="hb-body rich-body<?= $full?'':' is-clamp' ?>"><?= rich_html((string)$n['body']) ?></div>
      <footer class="hb-foot"><?php if(!$full): ?><a class="text-link" href="/?page=hebahan#h<?= (int)$n['id'] ?>">Baca penuh →</a><?php else: ?><span></span><?php endif; ?>
      <?php if($unread): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="hebahan_read"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn sm outline"><?= icon('check') ?>Tandai dibaca</button></form><?php else: ?><span class="hb-done"><?= icon('check') ?>Dibaca</span><?php endif; ?></footer>
    </article><?php
}
/** Pop-up hebahan belum dibaca: menutup skrin sehingga guru mengesahkan telah membaca (satu demi satu).
 *  Tiada butang tutup; satu-satunya tindakan ialah "Saya telah membaca". Semakan semula di menu Hebahan. */
function hebahan_popup(): void {
    $rows=hebahan_for_me(true,1); if(!$rows) return; $n=$rows[0]; $total=hebahan_unread_count();
    $g=hebahan_gaya((string)$n['style']); [$label,$ic]=HEBAHAN_GAYA[$g]; $d=new DateTimeImmutable((string)$n['created_at']);
    $back='/?'.http_build_query(array_intersect_key($_GET,array_flip(['page','id','edit','activity','jenis','tapis','q']))); if($back==='/?') $back='/?page=dashboard';
    ?><div class="hb-modal no-print" role="dialog" aria-modal="true" aria-labelledby="hb-modal-t"><div class="hb-modal-box hb-card hb-<?= $g ?> is-unread">
      <header class="hb-head"><span class="hb-ic"><?= icon($ic) ?></span><div class="hb-meta"><span class="hb-tag"><?= e($label) ?> · Hebahan pentadbir<?= $total>1?' · 1 daripada '.$total:'' ?></span><h4 id="hb-modal-t"><?= e($n['title']) ?></h4><small><?= e(($n['author']??'Pentadbir').' · '.$d->format('j').' '.TAKWIM_BULAN[(int)$d->format('n')].' '.$d->format('Y, g:i a')) ?></small></div></header>
      <div class="hb-body rich-body hb-modal-body"><?= rich_html((string)$n['body']) ?></div>
      <footer class="hb-foot"><small class="muted">Boleh disemak semula di menu <strong>Hebahan</strong>.</small><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="hebahan_read"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="btn primary" autofocus><?= icon('check') ?>Saya telah membaca<?= $total>1?' · seterusnya':'' ?></button></form></footer>
    </div></div><?php
}
/** Panel dashboard guru: hebahan belum dibaca (maksimum 3). */
function hebahan_dashboard_panel(): void {
    $rows=hebahan_for_me(true,3); if(!$rows) return; $n=hebahan_unread_count();
    ?><section class="panel padded spaced hb-panel no-print"><div class="aq-head"><div><h3>Hebahan untuk anda</h3><p class="muted"><?= $n ?> hebahan belum dibaca daripada pentadbir.</p></div><a class="text-link" href="/?page=hebahan">Semua hebahan →</a></div>
    <div class="hb-grid"><?php foreach($rows as $r) hebahan_card($r,false,'dashboard'); ?></div></section><?php
}

function hebahan_page(): void {
    $u=current_user(); $admin=$u['role']==='admin';
    if(!hebahan_ready()){ notice('Modul hebahan belum dipasang. Pentadbir perlu menjalankan naik taraf pangkalan data (php bin/upgrade.php atau buka /install.php).','error'); return; }
    if(!$admin){
        $rows=hebahan_for_me(false,100);
        ?><div class="page-heading"><div><span class="eyebrow teal">MAKLUMAN DALAMAN</span><h1>Hebahan</h1><p class="muted">Pengumuman daripada pentadbir untuk guru.</p></div></div>
        <?php if(!$rows): ?><section class="panel"><div class="empty compact"><span><?= icon('info') ?></span><p>Tiada hebahan buat masa ini.</p></div></section><?php else: ?><div class="hb-list"><?php foreach($rows as $r) hebahan_card($r,true); ?></div><?php endif;
        return;
    }
    $editId=input('edit','get'); $view=(int)input('lihat','get'); $guru=hebahan_recipients();
    ?><div class="page-heading"><div><span class="eyebrow teal">MAKLUMAN DALAMAN</span><h1>Hebahan Guru</h1><p class="muted">Hantar pengumuman kepada semua guru atau guru tertentu. Dipaparkan di Dashboard guru sehingga dibaca.</p></div><?php if($editId===''): ?><a class="btn primary" href="/?page=hebahan&amp;edit=0"><?= icon('plus') ?>Hebahan baharu</a><?php endif; ?></div><?php
    if($editId!==''){
        $n=(int)$editId?query('SELECT * FROM staff_notices WHERE id=? AND school_id='.sid(),[(int)$editId])->fetch():null;
        if((int)$editId && !$n){ notice('Hebahan tidak dijumpai.','error'); return; }
        $n=$n?:['id'=>0,'title'=>'','body'=>'','style'=>'makluman','audience'=>'semua','pinned'=>0,'status'=>'terbit','expire_at'=>null];
        $sel=$n['id']?array_map('intval',query('SELECT user_id FROM staff_notice_targets WHERE school_id='.sid().' AND notice_id=?',[$n['id']])->fetchAll(PDO::FETCH_COLUMN)):[];
        ?><section class="panel editor-panel"><div class="panel-heading"><h3><?= $n['id']?'Edit hebahan':'Hebahan baharu' ?></h3><a class="text-link" href="/?page=hebahan">Tutup ×</a></div>
        <form method="post" class="form-grid hb-form"><?= csrf_field() ?><input type="hidden" name="action" value="hebahan_save"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
        <label class="field full">Tajuk<input name="title" maxlength="160" required value="<?= e($n['title']) ?>" placeholder="cth. Senarai tugas 2027 untuk semakan"></label>
        <fieldset class="field full hb-fs"><legend>Kad pengumuman</legend><div class="hb-styles"><?php foreach(HEBAHAN_GAYA as $k=>[$l,$ic,$d]): ?><label class="hb-style hb-<?= $k ?>"><input type="radio" name="style" value="<?= $k ?>"<?= hebahan_gaya((string)$n['style'])===$k?' checked':'' ?>><span class="hb-ic"><?= icon($ic) ?></span><span><strong><?= e($l) ?></strong><small><?= e($d) ?></small></span></label><?php endforeach; ?></div></fieldset>
        <div class="field full"><span id="hb-body-l">Isi hebahan</span><textarea name="body" data-rich data-max="<?= HEBAHAN_MAX ?>" rows="10" aria-labelledby="hb-body-l"><?= e($n['body']) ?></textarea><small class="muted">Gunakan bar alat untuk tebal, senarai, subtajuk dan pautan.</small></div>
        <fieldset class="field full hb-fs"><legend>Penerima</legend>
          <div class="hb-aud"><label class="check"><input type="radio" name="audience" value="semua"<?= $n['audience']==='semua'?' checked':'' ?> data-hb-aud> Semua guru (<?= count($guru) ?>)</label><label class="check"><input type="radio" name="audience" value="pilih"<?= $n['audience']==='pilih'?' checked':'' ?> data-hb-aud> Guru tertentu</label></div>
          <div class="hb-pick<?= $n['audience']==='pilih'?'':' is-hidden' ?>" data-hb-pick><input type="search" placeholder="Cari nama atau e-mel guru…" data-hb-filter aria-label="Cari guru"><div class="hb-pick-acts"><button type="button" class="btn sm outline" data-hb-all="1">Pilih semua</button><button type="button" class="btn sm outline" data-hb-all="0">Kosongkan</button><span class="muted" data-hb-count></span></div>
            <div class="hb-people"><?php foreach($guru as $g): ?><label class="check" data-hb-name="<?= e(mb_strtolower($g['name'].' '.$g['email'])) ?>"><input type="checkbox" name="targets[]" value="<?= (int)$g['id'] ?>"<?= in_array((int)$g['id'],$sel,true)?' checked':'' ?>> <span><?= e($g['name']) ?><small><?= e($g['email']) ?></small></span></label><?php endforeach; ?><?php if(!$guru): ?><p class="muted">Tiada akaun guru aktif.</p><?php endif; ?></div></div>
        </fieldset>
        <label class="field">Tarikh tamat paparan (pilihan)<input type="date" name="expire_at" value="<?= e((string)$n['expire_at']) ?>"></label>
        <label class="field">Status<select name="status"><option value="terbit"<?= $n['status']==='terbit'?' selected':'' ?>>Terbit sekarang</option><option value="draf"<?= $n['status']==='draf'?' selected':'' ?>>Draf (belum dihantar)</option></select></label>
        <label class="check full"><input type="checkbox" name="pinned" value="1"<?= $n['pinned']?' checked':'' ?>> Sematkan di atas senarai</label>
        <div class="form-actions full"><button class="btn primary"><?= icon('check') ?>Simpan hebahan</button></div></form></section><?php
        return;
    }
    if($view){
        $n=query('SELECT * FROM staff_notices WHERE id=? AND school_id='.sid(),[$view])->fetch(); if(!$n){ notice('Hebahan tidak dijumpai.','error'); return; }
        $people=$n['audience']==='semua'?$guru:query('SELECT u.id,u.name,u.email FROM staff_notice_targets t JOIN users u ON u.id=t.user_id WHERE t.school_id='.sid().' AND t.notice_id=? AND u.active=1 ORDER BY u.name',[$view])->fetchAll();
        $reads=array_column(query('SELECT user_id,read_at FROM staff_notice_reads WHERE school_id='.sid().' AND notice_id=?',[$view])->fetchAll(),'read_at','user_id');
        [$r,$t]=hebahan_reach($n); $n['author']=current_user()['name']; $n['read_at']='x';
        ?><section class="panel padded"><div class="aq-head"><div><h3>Status bacaan</h3><p class="muted"><strong><?= $r ?>/<?= $t ?></strong> guru sudah membaca.</p></div><a class="text-link" href="/?page=hebahan">← Senarai</a></div></section>
        <div class="hb-list spaced"><?php hebahan_card($n,true); ?></div>
        <section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Guru</th><th>Status</th></tr></thead><tbody><?php foreach($people as $p): $ra=$reads[$p['id']]??null; ?><tr><td><strong><?= e($p['name']) ?></strong><br><small class="muted"><?= e($p['email']) ?></small></td><td><?= $ra?'<span class="at-st ok">Dibaca · '.e(date('j/n g:i a',strtotime((string)$ra))).'</span>':'<span class="at-st none">Belum dibaca</span>' ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php
        return;
    }
    $rows=query('SELECT n.*,p.name author FROM staff_notices n LEFT JOIN users p ON p.id=n.created_by WHERE n.school_id='.sid().' ORDER BY n.pinned DESC,n.created_at DESC LIMIT 200')->fetchAll();
    ?><section class="panel"><div class="panel-heading"><h3>Senarai hebahan <span class="count"><?= count($rows) ?></span></h3></div><?php if(!$rows): ?><div class="empty compact"><span><?= icon('info') ?></span><p>Belum ada hebahan. Tekan "Hebahan baharu" untuk menghantar yang pertama.</p></div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Hebahan</th><th>Penerima</th><th>Dibaca</th><th>Status</th><th>Tindakan</th></tr></thead><tbody><?php foreach($rows as $n): [$l,$ic]=HEBAHAN_GAYA[hebahan_gaya((string)$n['style'])]; [$r,$t]=hebahan_reach($n); $exp=$n['expire_at'] && $n['expire_at']<date('Y-m-d'); ?>
      <tr><td><span class="hb-chip hb-<?= hebahan_gaya((string)$n['style']) ?>"><?= icon($ic) ?><?= e($l) ?></span> <strong><?= e($n['title']) ?></strong><br><small class="muted"><?= e(($n['author']??'—').' · '.date('j/n/Y',strtotime((string)$n['created_at']))) ?></small></td>
      <td><?= $n['audience']==='semua'?'Semua guru':$t.' guru' ?></td><td><a href="/?page=hebahan&amp;lihat=<?= (int)$n['id'] ?>"><strong><?= $r ?>/<?= $t ?></strong></a><progress max="<?= max($t,1) ?>" value="<?= $r ?>"></progress></td>
      <td><span class="at-st <?= $n['status']==='draf'?'none':($exp?'part':'ok') ?>"><?= $n['status']==='draf'?'Draf':($exp?'Tamat':'Terbit') ?></span><?= $n['pinned']?' <small class="muted">· disematkan</small>':'' ?></td>
      <td><div class="hb-acts"><a class="btn sm outline" href="/?page=hebahan&amp;lihat=<?= (int)$n['id'] ?>">Lihat</a> <a class="btn sm outline" href="/?page=hebahan&amp;edit=<?= (int)$n['id'] ?>">Edit</a> <form method="post" class="delete-form" data-confirm="Padam hebahan ini? Rekod bacaan juga dipadam."><?= csrf_field() ?><input type="hidden" name="action" value="hebahan_delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="delete-btn">Padam</button></form></div></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php
}
