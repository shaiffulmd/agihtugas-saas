<?php
declare(strict_types=1);
/* Pusat Sokongan (corak delima-multi): pentadbir sekolah menghantar tiket dari panel sekolah (?page=sokongan),
 * pentadbir platform membalas dari Panel Platform (?p=sokongan). Setiap tiket ialah satu perbualan.
 * Status: baru (menunggu balasan) · dibalas · selesai. Tanda baca berasingan untuk platform & sekolah.
 * Had: 5 tiket baharu & 30 mesej sejam bagi setiap sekolah. Notis e-mel: tiket/mesej baharu → e-mel notis platform;
 * balasan platform → e-mel penghantar tiket. Semua teks dipaparkan sebagai teks biasa. */
const SK_KATEGORI=['akaun'=>'Akaun & log masuk','data'=>'Data guru / jawatan','langganan'=>'Langganan & bayaran','ralat'=>'Ralat / tidak berfungsi','cadangan'=>'Cadangan ciri','lain'=>'Lain-lain'];
const SK_STATUS=['baru'=>'Menunggu balasan','dibalas'=>'Dibalas','selesai'=>'Selesai'];
const SK_ISI_MAKS=5000;

function sk_text(string $s,int $max=SK_ISI_MAKS): string {
    $s=str_replace(["\r\n","\r"],"\n",$s); $s=(string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$s); $s=(string)preg_replace("/\n{4,}/","\n\n\n",trim($s));
    return mb_substr($s,0,$max);
}
function sk_ticket(int $id,?int $school=null): ?array {
    $r=$school!==null?query('SELECT t.*,s.code,s.name school FROM support_tickets t JOIN schools s ON s.id=t.school_id WHERE t.id=? AND t.school_id=?',[$id,$school])->fetch()
                     :query('SELECT t.*,s.code,s.name school FROM support_tickets t JOIN schools s ON s.id=t.school_id WHERE t.id=?',[$id])->fetch();
    return $r?:null;
}
function sk_messages(array $t): array { return query('SELECT * FROM support_messages WHERE school_id=? AND ticket_id=? ORDER BY id',[$t['school_id'],$t['id']])->fetchAll(); }
function sk_notify_platform(array $t,string $what): void {
    $to=pset('emel_notis'); if(!$to || platform_domain()==='') return; require_once __DIR__.'/mailer.php'; $e=null;
    $prev=school(); $mode=tenant_mode(); tenant_use(null);
    try { send_mail($to,'Pentadbir Platform','['.$t['code'].'] '.$what.': '.$t['subject'],"Sekolah: {$t['code']} · {$t['school']}\nDaripada: {$t['name']} <{$t['email']}>\n\n".tenant_scheme().'://'.platform_domain().'/platform/?p=sokongan&id='.(int)$t['id'],$e); }
    finally { tenant_use($prev,$mode); }
}
function sk_rate(): void {
    $k=rate_key('sokongan-tiket',(string)sid()); if(!rate_allowed($k,30)) throw new DomainException('Terlalu banyak mesej sokongan dalam sejam. Cuba lagi kemudian.'); rate_hit($k);
}

/* ---------- Sekolah ---------- */
function handle_support(string $action): never {
    require_admin(); $u=current_user();
    if($action==='sk_new'){
        $subject=sk_text(input('subject'),160); $body=sk_text(input('body')); $cat=isset(SK_KATEGORI[input('category')])?input('category'):'lain';
        if(mb_strlen($subject)<5) throw new DomainException('Tajuk minimum 5 aksara.'); if(mb_strlen($body)<10) throw new DomainException('Huraian minimum 10 aksara.');
        $k=rate_key('sokongan-baru',(string)sid()); if(!rate_allowed($k,5)) throw new DomainException('Had 5 tiket baharu sejam bagi setiap sekolah.'); rate_hit($k); sk_rate();
        db()->beginTransaction();
        query('INSERT INTO support_tickets(school_id,user_id,name,email,subject,category) VALUES (?,?,?,?,?,?)',[sid(),$u['id'],$u['name'],$u['email'],$subject,$cat]); $id=(int)db()->lastInsertId();
        query('INSERT INTO support_messages(school_id,ticket_id,sender,author,body) VALUES (?,?,"sekolah",?,?)',[sid(),$id,$u['name'],$body]);
        db()->commit(); audit('support_ticket','support_tickets',$id); sk_notify_platform(sk_ticket($id,sid()),'Tiket baharu');
        flash('Tiket sokongan dihantar. Pentadbir platform akan membalas di sini.'); redirect('/?page=sokongan&id='.$id);
    }
    $t=sk_ticket((int)input('ticket_id'),sid()); if(!$t) error_page(404);
    if($action==='sk_reply'){
        $body=sk_text(input('body')); if(mb_strlen($body)<2) throw new DomainException('Mesej kosong.'); sk_rate();
        query('INSERT INTO support_messages(school_id,ticket_id,sender,author,body) VALUES (?,?,"sekolah",?,?)',[sid(),$t['id'],$u['name'],$body]);
        query('UPDATE support_tickets SET status="baru",platform_read=0,closed_at=NULL WHERE id=? AND school_id='.sid(),[$t['id']]);
        sk_notify_platform($t,'Balasan sekolah'); flash('Mesej dihantar.');
    } elseif($action==='sk_delete'){
        query('DELETE FROM support_messages WHERE ticket_id=? AND school_id='.sid(),[$t['id']]); query('DELETE FROM support_tickets WHERE id=? AND school_id='.sid(),[$t['id']]);
        audit('support_deleted','support_tickets',(int)$t['id']); flash('Tiket dipadam.'); redirect('/?page=sokongan');
    } elseif($action==='sk_close'){
        query('UPDATE support_tickets SET status="selesai",closed_at=NOW() WHERE id=? AND school_id='.sid(),[$t['id']]); flash('Tiket ditanda selesai.');
    } else error_page(400);
    redirect('/?page=sokongan&id='.(int)$t['id']);
}
function support_page(): void {
    require_admin(); $id=(int)input('id','get');
    if($id){ $t=sk_ticket($id,sid()); if(!$t){ http_response_code(404); notice('Tiket tidak dijumpai.','error'); return; }
        query('UPDATE support_tickets SET school_read=1 WHERE id=? AND school_id='.sid(),[$id]); support_thread($t,false); return; }
    $rows=query('SELECT t.*,(SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) n FROM support_tickets t WHERE t.school_id='.sid().' ORDER BY t.updated_at DESC LIMIT 200')->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">SOKONGAN</span><h1>Pusat Sokongan</h1><p class="muted">Hantar pertanyaan atau laporan masalah kepada pentadbir platform.</p></div></div>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Tajuk</th><th>Kategori</th><th>Status</th><th>Dikemas kini</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr class="<?= !$r['school_read']?'row-alert':'' ?>"><td><a class="text-link" href="/?page=sokongan&amp;id=<?= (int)$r['id'] ?>"><?= e($r['subject']) ?></a><?= !$r['school_read']?' <span class="badge green">Balasan baharu</span>':'' ?></td><td><?= e(SK_KATEGORI[$r['category']]??$r['category']) ?></td><td><?= e(SK_STATUS[$r['status']]??$r['status']) ?></td><td><?= e(date('d/m/Y H:i',strtotime($r['updated_at']))) ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="4" class="muted">Belum ada tiket.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel padded spaced"><h3>Tiket baharu</h3><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="sk_new">
<label class="field">Tajuk<input name="subject" maxlength="160" required></label><label class="field">Kategori<select name="category"><?php foreach(SK_KATEGORI as $k=>$l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
<label class="field full">Huraian<textarea name="body" rows="5" maxlength="<?= SK_ISI_MAKS ?>" required></textarea></label><div class="button-group"><button class="btn primary">Hantar tiket</button></div></form></section><?php
}
/** Paparan perbualan (dikongsi sekolah & platform). */
function support_thread(array $t,bool $platform): void {
    $msgs=sk_messages($t); $back=$platform?'/platform/?p=sokongan':'/?page=sokongan'; ?>
<div class="page-heading"><div><span class="eyebrow teal">TIKET #<?= (int)$t['id'] ?><?= $platform?' · '.e($t['code']):'' ?></span><h1><?= e($t['subject']) ?></h1><p class="muted"><?= e(SK_KATEGORI[$t['category']]??'') ?> · <?= e(SK_STATUS[$t['status']]??'') ?> · <?= e($t['name']) ?><?= $platform?' &lt;'.e($t['email']).'&gt; · '.e($t['school']):'' ?></p></div><div class="button-group"><a class="btn outline" href="<?= $back ?>">← Senarai</a></div></div>
<section class="panel padded spaced sk-thread"><?php foreach($msgs as $m): ?><article class="sk-msg sk-<?= e($m['sender']) ?>"><p class="muted"><strong><?= e($m['author']) ?></strong> (<?= $m['sender']==='platform'?'Platform':'Sekolah' ?>) · <?= e(date('d/m/Y H:i',strtotime($m['created_at']))) ?></p><p><?= nl2br(e($m['body'])) ?></p></article><?php endforeach; ?></section>
<section class="panel padded spaced"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>"><?php if($platform): ?><input type="hidden" name="school_id" value="<?= (int)$t['school_id'] ?>"><?php endif; ?>
<label class="field full">Balas<textarea name="body" rows="4" maxlength="<?= SK_ISI_MAKS ?>" required></textarea></label>
<div class="button-group"><button class="btn primary" name="action" value="<?= $platform?'p_sk_reply':'sk_reply' ?>">Hantar balasan</button><?php if($t['status']!=='selesai'): ?><button class="btn outline" name="action" value="<?= $platform?'p_sk_close':'sk_close' ?>" formnovalidate>Tanda selesai</button><?php endif; ?></div></form></section>
<form method="post" class="button-group spaced" data-confirm="Padam tiket #<?= (int)$t['id'] ?> dan semua mesejnya? Tindakan ini tidak boleh dibatalkan."><?= csrf_field() ?><input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>"><?php if($platform): ?><input type="hidden" name="school_id" value="<?= (int)$t['school_id'] ?>"><?php endif; ?><button class="btn danger" name="action" value="<?= $platform?'p_sk_delete':'sk_delete' ?>">Padam tiket</button></form><?php
}

/* ---------- Platform ---------- */
function handle_support_platform(string $action,array $me): never {
    if($action==='p_sk_keep'){
        $d=trim(input('hari')); if(!ctype_digit($d) || (int)$d>3650) throw new DomainException('Bilangan hari mesti 0–3650 (0 = tidak dipadam automatik).');
        pset_set(['sokongan_simpan_hari'=>(string)(int)$d]); platform_log('support_retention',null,(int)$d.' hari'); flash((int)$d?'Tiket tanpa mesej baharu selama '.(int)$d.' hari akan dipadam secara automatik (kerja harian).':'Pemadaman automatik tiket dimatikan.'); redirect('/platform/?p=sokongan');
    }
    if($action==='p_sk_purge'){
        $d=(int)input('hari'); if($d<1 || $d>3650) throw new DomainException('Bilangan hari mesti 1–3650.');
        $n=support_purge($d); platform_log('support_purged',null,$n.' tiket (> '.$d.' hari, manual)'); flash($n.' tiket tanpa mesej baharu lebih '.$d.' hari dipadam.'); redirect('/platform/?p=sokongan');
    }
    $t=sk_ticket((int)input('ticket_id')); if(!$t) error_page(404);
    if($action==='p_sk_delete'){
        query('DELETE FROM support_messages WHERE ticket_id=?',[$t['id']]); query('DELETE FROM support_tickets WHERE id=?',[$t['id']]);
        platform_log('support_deleted',(int)$t['school_id'],'#'.$t['id'].' '.$t['subject']); flash('Tiket #'.(int)$t['id'].' dipadam.'); redirect('/platform/?p=sokongan');
    }
    if($action==='p_sk_reply'){
        $body=sk_text(input('body')); if(mb_strlen($body)<2) throw new DomainException('Mesej kosong.');
        query('INSERT INTO support_messages(school_id,ticket_id,sender,author,body) VALUES (?,?,"platform",?,?)',[$t['school_id'],$t['id'],$me['name'],$body]);
        query('UPDATE support_tickets SET status="dibalas",school_read=0,platform_read=1,closed_at=NULL WHERE id=?',[$t['id']]);
        platform_log('support_reply',(int)$t['school_id'],'#'.$t['id']);
        require_once __DIR__.'/mailer.php'; $e=null;
        with_school(school_by_id((int)$t['school_id']),function() use($t,$body,&$e){ send_mail((string)$t['email'],(string)$t['name'],'Balasan sokongan: '.$t['subject'],"Pentadbir platform membalas tiket anda:\n\n$body\n\nLihat & balas:\n".tenant_base_url().'/?page=sokongan&id='.(int)$t['id'],$e); });
        flash('Balasan dihantar.');
    } elseif($action==='p_sk_close'){ query('UPDATE support_tickets SET status="selesai",closed_at=NOW() WHERE id=?',[$t['id']]); platform_log('support_closed',(int)$t['school_id'],'#'.$t['id']); flash('Tiket ditanda selesai.'); }
    else error_page(400);
    redirect('/platform/?p=sokongan&id='.(int)$t['id']);
}
function platform_page_support(): void {
    $id=(int)input('id','get');
    if($id){ $t=sk_ticket($id); if(!$t){ p_notice('Tiket tidak dijumpai.','error'); return; } query('UPDATE support_tickets SET platform_read=1 WHERE id=?',[$id]); support_thread($t,true); return; }
    $f=input('status','get'); $w=isset(SK_STATUS[$f])?' WHERE t.status=?':''; $rows=query('SELECT t.*,s.code FROM support_tickets t JOIN schools s ON s.id=t.school_id'.$w.' ORDER BY t.platform_read,t.updated_at DESC LIMIT 300',$w?[$f]:[])->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Sokongan</h1><p class="muted">Tiket daripada pentadbir sekolah.</p></div></div>
<nav class="takwim-filter"><a class="chip<?= $w===''?' on':'' ?>" href="/platform/?p=sokongan">Semua</a><?php foreach(SK_STATUS as $k=>$l): ?><a class="chip<?= $f===$k?' on':'' ?>" href="/platform/?p=sokongan&amp;status=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Sekolah</th><th>Tajuk</th><th>Kategori</th><th>Status</th><th>Dikemas kini</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr class="<?= !$r['platform_read']?'row-alert':'' ?>"><td><?= e($r['code']) ?></td><td><a class="text-link" href="/platform/?p=sokongan&amp;id=<?= (int)$r['id'] ?>"><?= e($r['subject']) ?></a><?= !$r['platform_read']?' <span class="badge warn">Baharu</span>':'' ?></td><td><?= e(SK_KATEGORI[$r['category']]??'') ?></td><td><?= e(SK_STATUS[$r['status']]??'') ?></td><td><?= e(date('d/m/Y H:i',strtotime($r['updated_at']))) ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5" class="muted">Tiada tiket.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel padded spaced"><h3>Simpanan tiket</h3><p class="muted">Tiket (termasuk semua mesejnya) dipadam secara automatik apabila tiada mesej baharu selama tempoh ini — dijalankan oleh kerja harian. <?= support_keep_days()?'Kini: <strong>'.support_keep_days().' hari</strong>.':'Kini: <strong>tidak dipadam automatik</strong>.' ?></p>
<div class="dashboard-grid pay-grid"><form method="post" class="form-grid flush"><?= csrf_field() ?><input type="hidden" name="action" value="p_sk_keep"><label class="field full">Padam automatik selepas (hari, 0 = tidak)<input name="hari" inputmode="numeric" value="<?= support_keep_days() ?>" required></label><div class="button-group full"><button class="btn primary">Simpan</button></div></form>
<form method="post" class="form-grid flush" data-confirm="Padam semua tiket tanpa mesej baharu melebihi tempoh ini sekarang? Tidak boleh dibatalkan."><?= csrf_field() ?><input type="hidden" name="action" value="p_sk_purge"><label class="field full">Padam sekarang tiket tanpa mesej baharu lebih daripada (hari)<input name="hari" inputmode="numeric" value="<?= support_keep_days()?:90 ?>" required></label><div class="button-group full"><button class="btn danger">Padam sekarang</button></div></form></div></section><?php
}
/** Hari simpanan tiket (0 = tidak dipadam automatik). Dikira dari mesej terakhir tiket. */
function support_keep_days(): int { return max(0,min(3650,(int)pset('sokongan_simpan_hari','90'))); }
/** Padam tiket (dan mesejnya) tanpa mesej baharu selama $days hari. Pulangkan bilangan tiket dipadam. */
function support_purge(int $days): int {
    if($days<1) return 0;
    $ids=array_map('intval',array_column(query('SELECT t.id FROM support_tickets t WHERE COALESCE((SELECT MAX(m.created_at) FROM support_messages m WHERE m.ticket_id=t.id),t.created_at)<DATE_SUB(NOW(),INTERVAL ? DAY)',[$days])->fetchAll(),'id'));
    if(!$ids) return 0;
    $in=implode(',',$ids); query('DELETE FROM support_messages WHERE ticket_id IN ('.$in.')'); query('DELETE FROM support_tickets WHERE id IN ('.$in.')');
    return count($ids);
}
/** Kerja harian (subscription_daily). */
function support_daily(): array { $n=support_purge(support_keep_days()); if($n) platform_log('support_purged',null,$n.' tiket (> '.support_keep_days().' hari, automatik)'); return ['sokongan_dipadam'=>$n]; }
function support_unread_platform(): int { try { return (int)query('SELECT COUNT(*) FROM support_tickets WHERE platform_read=0')->fetchColumn(); } catch(Throwable) { return 0; } }
