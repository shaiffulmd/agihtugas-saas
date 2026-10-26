<?php
declare(strict_types=1);
/* Paparan & tindakan bayaran: halaman Langganan sekolah (payment_panel), halaman kembali gerbang, resit,
 * dan panel platform (Bayaran, Kaedah Bayaran, Kod Diskaun, Tajaan, panel bayaran di halaman sekolah). */

/* ======================= Sekolah ======================= */
/** Tindakan pay_* di halaman Langganan (pentadbir sekolah sahaja). */
function handle_payment(string $action): never {
    require_admin(); $s=school(); $u=current_user();
    if(!pay_enabled() || $s['status']!=='aktif') throw new DomainException('Bayaran dalam sistem tidak tersedia. Hubungi pentadbir platform.');
    if($action==='pay_kod'){
        $rk=rate_key('discount',(string)sid()); if(!rate_allowed($rk,10)) throw new DomainException('Terlalu banyak cubaan kod diskaun. Cuba selepas 15 minit.');
        $r=discount_check(input('kod'),sid());
        if(!$r['ok']){ rate_hit($rk); throw new DomainException((string)$r['error']); }
        $_SESSION['diskaun']=$r['code']; audit('discount_applied','payments');
        flash('Kod '.$r['code'].' digunakan: potongan '.ringgit($r['cut']).'. Jumlah perlu dibayar '.ringgit($r['final']).'.'); redirect('/?page=langganan#bayar');
    }
    if($action==='pay_buang_kod'){ unset($_SESSION['diskaun']); redirect('/?page=langganan#bayar'); }
    if($action==='pay_online'){
        $g=input('g'); $payor=['name'=>mb_substr(trim(input('nama')),0,100),'email'=>strtolower(trim(input('emel'))),'phone'=>(string)preg_replace('/[^0-9+]/','',input('telefon')),'by'=>(string)$u['name']];
        if(!gw_active($g)) throw new DomainException('Sila pilih kaedah bayaran.');
        if($payor['name']==='') throw new DomainException('Nama pembayar diperlukan.');
        if(!filter_var($payor['email'],FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel pembayar tidak sah.');
        if(strlen($payor['phone'])<9 || strlen($payor['phone'])>15) throw new DomainException('No. telefon tidak sah.');
        $rk=rate_key('pay-online',(string)sid()); if(!rate_allowed($rk,10)) throw new DomainException('Terlalu banyak bil dicipta. Cuba selepas 15 minit.'); rate_hit($rk);
        $dk=discount_session();
        try { $r=pay_create_online($g,$s,$payor,$dk); } catch(RuntimeException $ex){ throw new DomainException($ex->getMessage()); }
        audit('payment_started','payments');
        if(isset($r['url'])){ header('Location: '.$r['url']); exit; }
        $_SESSION['sp_form']=$r; redirect('/?page=langganan&teruskan=1');
    }
    if($action==='pay_bukti'){
        $m=input('m'); $ref=mb_substr(trim(input('rujukan')),0,80);
        if(!in_array($m,['bank','qr'],true) || !manual_on($m)) throw new DomainException('Kaedah tidak sah.');
        if($ref==='') throw new DomainException('Masukkan no. rujukan transaksi / PO.');
        if((int)query('SELECT COUNT(*) FROM payments WHERE school_id=? AND method IN ("bank","qr") AND status="menunggu"',[sid()])->fetchColumn()>=3) throw new DomainException('Terdapat bukti bayaran yang masih menunggu pengesahan.');
        $file=proof_save($_FILES['bukti']??[]); $dk=discount_session(); $sen=$dk?$dk['final']:pay_price();
        query('INSERT INTO payments(school_id,school_code,method,amount_sen,years,status,ref,payer_name,payer_email,by_name,proof,discount_code,discount_sen) VALUES (?,?,?,?,1,"menunggu",?,?,?,?,?,?,?)',
            [sid(),$s['code'],$m,$sen,$ref,mb_substr((string)$u['name'],0,120),mb_substr((string)$u['email'],0,190),mb_substr((string)$u['name'],0,120),$file,$dk?$dk['code']:null,$dk?$dk['cut']:0]);
        audit('payment_proof','payments',(int)db()->lastInsertId()); unset($_SESSION['diskaun']);
        platform_log('payment_proof',sid(),pay_method_name($m).' '.ringgit($sen).' ('.$ref.')');
        if(filter_var(pset('emel_notis'),FILTER_VALIDATE_EMAIL)){ require_once __DIR__.'/mailer.php'; $e=null; send_mail(pset('emel_notis'),platform_name(),'[Platform] Bukti bayaran: '.$s['code'],"{$s['code']} — {$s['name']} menghantar bukti bayaran ".pay_method_name($m).' '.ringgit($sen)." ($ref).\n\nSahkan di Panel Platform → Bayaran → Menunggu:\n".pay_abs(platform_url('/platform/?p=bayaran&f=menunggu')),$e); }
        flash('Bukti bayaran dihantar. Langganan dilanjutkan selepas disahkan oleh pentadbir platform.'); redirect('/?page=langganan');
    }
    error_page(400);
}

/** Kembali dari gerbang: tanpa sesi (NO_SESSION). Token HMAC mengikat ID bayaran; status disahkan melalui API, bukan parameter URL. */
function pay_return_page(): never {
    $id=(int)input('byr','get'); $p=$id>0?payment_get($id):null;
    if(!$p || (int)$p['school_id']!==sid() || !hash_equals(pay_return_token($id),input('t','get'))) error_page(404);
    $rk=rate_key('pay-return',client_ip()); $new=null;
    if(rate_allowed($rk,30)){ rate_hit($rk); try { $new=pay_confirm($id); } catch(Throwable $ex){ error_log('bayar_kembali: '.$ex->getMessage()); } }
    $p=payment_get($id);
    [$msg,$cls]=match(true){
        $p['status']==='berjaya'=>['Terima kasih! Bayaran diterima (resit '.$p['receipt_no'].'). Langganan Pro aktif hingga '.date('d/m/Y',strtotime((string)($new?:$p['expires_to']))).'.','success'],
        $p['status']==='semak'=>['Bayaran diterima tetapi ditahan untuk semakan pentadbir platform'.((int)$p['sandbox']===1?' (MOD UJIAN — bukan wang sebenar)':'').'.','info'],
        $p['method']==='securepay'=>['Bayaran sedang disahkan oleh SecurePay. Semak semula halaman Langganan dalam beberapa minit.','info'],
        default=>['Bayaran belum disahkan. Jika anda telah membayar, status dikemas kini dalam beberapa minit; jika dibatalkan, cuba semula.','error'],
    };
    while(ob_get_level()>0) @ob_end_clean(); ob_start('tenant_rewrite_html');
    ?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Status bayaran</title><link rel="stylesheet" href="<?= e(asset('app.css')) ?>"><link rel="stylesheet" href="/?page=theme-css"></head><body>
<main class="main-content platform-dir"><div class="page-heading"><div><span class="eyebrow teal"><?= e(mb_strtoupper(school()['name'])) ?></span><h1>Status bayaran</h1><p class="muted">Rujukan <?= e($p['ref']?:'#'.$p['id']) ?> · <?= e(pay_method_name($p['method'])) ?> · <?= e(ringgit((int)$p['amount_sen'])) ?></p></div></div>
<div class="notice <?= $cls ?>" role="status"><?= e($msg) ?></div><p><a class="btn primary" href="/?page=langganan">Kembali ke Langganan →</a></p></main></body></html><?php exit;
}

/** Imej QR pembayaran (tetapan platform) untuk pentadbir sekolah. */
function pay_serve_qr(): never {
    require_admin(); $png=base64_decode(pset('qr_data'),true); if(!$png || !manual_on('qr')) error_page(404);
    while(ob_get_level()>0) @ob_end_clean();
    header('Content-Type: image/png'); header('Content-Length: '.strlen($png)); header('Cache-Control: private, max-age=86400'); echo $png; exit;
}

/** Resit boleh cetak (bayaran berjaya). $platform: dipanggil dari panel platform. */
function pay_receipt_page(array $p,bool $platform=false): never {
    if($p['status']!=='berjaya' || !$p['receipt_no']) error_page(404,'Resit hanya untuk bayaran berjaya.');
    $s=school_by_id((int)$p['school_id']);
    while(ob_get_level()>0) @ob_end_clean(); ob_start('tenant_rewrite_html');
    ?><!doctype html><html lang="ms"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Resit <?= e($p['receipt_no']) ?></title><link rel="stylesheet" href="<?= e(asset('app.css')) ?>"><script src="<?= e(asset('app.js')) ?>" defer></script></head><body class="receipt-body">
<main class="receipt"><header class="receipt-head"><div><strong><?= e(platform_name()) ?></strong><small>Resit rasmi langganan</small></div><div class="receipt-no"><small>No. resit</small><strong><?= e($p['receipt_no']) ?></strong></div></header>
<dl class="receipt-grid"><dt>Sekolah</dt><dd><?= e(($s['name']??'(dipadam)').' ('.$p['school_code'].')') ?></dd><dt>Dibayar oleh</dt><dd><?= e($p['payer_name']?:'—') ?></dd>
<dt>Tarikh</dt><dd><?= e(date('d/m/Y H:i',strtotime((string)($p['paid_at']?:$p['created_at'])))) ?></dd><dt>Kaedah</dt><dd><?= e(pay_method_name($p['method'])) ?></dd><dt>Rujukan</dt><dd><?= e($p['ref']?:'—') ?></dd></dl>
<table class="receipt-table"><thead><tr><th>Perkara</th><th class="num">Jumlah</th></tr></thead><tbody>
<tr><td><?= $p['method']==='tajaan'?'Langganan Pro (ditaja)':'Langganan Pro '.(int)$p['years'].' tahun' ?><?= $p['expires_to']?'<br><small class="muted">Sah hingga '.e(date('d/m/Y',strtotime((string)$p['expires_to']))).'</small>':'' ?></td><td class="num"><?= e(ringgit((int)$p['amount_sen']+(int)$p['discount_sen'])) ?></td></tr>
<?php if((int)$p['discount_sen']>0): ?><tr><td>Diskaun (<?= e((string)$p['discount_code']) ?>)</td><td class="num">−<?= e(ringgit((int)$p['discount_sen'])) ?></td></tr><?php endif; ?>
</tbody><tfoot><tr><th>Jumlah dibayar</th><th class="num"><?= e(ringgit((int)$p['amount_sen'])) ?></th></tr></tfoot></table>
<p class="muted receipt-foot">Resit janaan komputer — tidak memerlukan tandatangan.</p>
<div class="button-group no-print"><button type="button" class="btn primary print-btn">Cetak / simpan PDF</button><a class="btn outline" href="<?= $platform?'/platform/?p=bayaran':'/?page=langganan' ?>">← Kembali</a></div></main></body></html><?php exit;
}

/** Bahagian bayaran dalam halaman Langganan (dipanggil oleh langganan_page()). */
function payment_panel(): void {
    $s=school(); $plan=school_plan($s);
    $hist=query('SELECT * FROM payments WHERE school_id=? AND status<>"gagal" AND NOT (status="menunggu" AND method IN ("toyyibpay","billplz","chip","securepay") AND created_at<DATE_SUB(NOW(),INTERVAL 1 HOUR)) ORDER BY id DESC LIMIT 20',[sid()])->fetchAll();
    $form=$_SESSION['sp_form']??null; unset($_SESSION['sp_form']);
    if($form && input('teruskan','get')==='1'): ?>
<div class="panel padded spaced"><h3>Teruskan ke SecurePay</h3><p class="muted">Anda akan dibawa ke halaman SecurePay untuk membayar melalui FPX.</p>
<form method="post" action="<?= e($form['post']) ?>"><?php foreach($form['fields'] as $k=>$v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?><div class="button-group"><button class="btn primary">Bayar sekarang →</button><a class="btn outline" href="/?page=langganan">Batal</a></div></form></div>
<?php return; endif;
    $tj=sponsorship_current(sid()); $left=school_days_left($s);
    if($tj): ?><div class="notice success" role="status">Langganan Pro sekolah ini <strong>ditaja oleh <?= e($tj['sponsor']) ?></strong> dari <?= e(date('d/m/Y',strtotime($tj['start_on']))) ?> hingga <strong><?= e(date('d/m/Y',strtotime($tj['end_on']))) ?></strong>. Tiada bayaran diperlukan.<?= $left!==null && $left>30?' Pilihan pembaharuan dipaparkan 30 hari sebelum tajaan tamat.':'' ?></div><?php endif;
    if($tj && $left!==null && $left>30): // tiada pilihan bayaran semasa tajaan
    elseif(!pay_enabled()): ?><p class="notice info">Bayaran dalam sistem tidak digunakan. Tempoh langganan diurus oleh pentadbir platform<?= pset('emel_notis')?' (<a class="text-link" href="mailto:'.e(pset('emel_notis')).'">'.e(pset('emel_notis')).'</a>)':'' ?>.</p><?php
    elseif($s['status']!=='aktif'): ?><p class="notice error">Sekolah ini digantung. Hubungi pentadbir platform.</p><?php
    else:
        $dk=discount_session(); $price=pay_price(); $pay=$dk?$dk['final']:$price; $gws=gw_list(); $man=manual_list();
        $priceHtml=$dk?'<s class="muted">'.e(ringgit($price)).'</s> '.e(ringgit($pay)):e(ringgit($pay));
        $u=current_user(); ?>
<div id="bayar" class="pay-section"><h3>Bayar langganan Pro</h3><p class="muted">Pro dilanjutkan <strong>1 tahun</strong> <?= $plan==='pro'?'dari tarikh tamat semasa ('.e(date('d/m/Y',strtotime($s['expires_on']))).')':'bermula hari ini' ?> sebaik sahaja bayaran disahkan.</p>
<?= gw_sandbox_banner() ?>
<div class="pay-discount"><?php if($dk): ?><p><span class="badge green"><?= e($dk['code']) ?></span> Potongan <strong><?= e(discount_label($dk['d'])) ?></strong><?= $dk['d']['kind']==='peratus'?' ('.e(ringgit($dk['cut'])).')':'' ?> · Jumlah perlu dibayar <strong><?= e(ringgit($dk['final'])) ?></strong></p>
<form method="post" class="inline-form"><?= csrf_field() ?><button class="btn sm outline" name="action" value="pay_buang_kod">Buang kod</button></form>
<?php else: ?><form method="post" class="form-grid" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="pay_kod"><label class="field">Ada kod diskaun?<input name="kod" maxlength="30" autocapitalize="characters" placeholder="cth. DIDIK20" required></label><div class="button-group"><button class="btn outline">Guna kod</button></div></form><?php endif; ?></div>
<div class="dashboard-grid pay-grid">
<div class="pay-card"><h4>Bayar dalam talian</h4><?php if(!$gws): ?><p class="muted">Pembayaran dalam talian tidak tersedia. Sila guna kaedah lain.</p><?php else: ?>
<form method="post" class="stack" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="pay_online">
<fieldset class="pay-gw"><legend>Gerbang</legend><?php foreach($gws as $i=>$g): ?><label class="check"><input type="radio" name="g" value="<?= e($g) ?>"<?= $i===0?' checked':'' ?>> <span><strong><?= e(PAY_GATEWAYS[$g][0]) ?></strong> <small class="muted"><?= e(PAY_GATEWAYS[$g][1]) ?><?= gw_sandbox($g)?' · mod ujian':'' ?></small></span></label><?php endforeach; ?></fieldset>
<label class="field">Nama pembayar<input name="nama" maxlength="100" value="<?= e((string)($s['pic_name']?:$u['name'])) ?>" required></label>
<label class="field">E-mel (resit)<input name="emel" type="email" maxlength="190" value="<?= e((string)($s['pic_email']?:$u['email'])) ?>" required></label>
<label class="field">No. telefon<input name="telefon" inputmode="tel" maxlength="20" value="<?= e((string)$s['pic_phone']) ?>" required></label>
<button class="btn primary">Bayar <?= e(ringgit($pay)) ?> →</button></form><?php endif; ?></div>
<div class="pay-card"><h4>Kaedah lain</h4><?php if(!$man): ?><p class="muted">Hubungi pentadbir platform untuk invois atau pesanan belian (PO).</p><?php endif;
        foreach($man as $m): ?><details class="pay-manual"<?= count($man)===1?' open':'' ?>><summary><strong><?= e(PAY_MANUAL[$m][0]) ?></strong> <small class="muted"><?= e(PAY_MANUAL[$m][1]) ?></small></summary>
<?php if($m==='bank'): ?><p class="prewrap"><?= e(pset('info_bank')) ?></p><?php elseif($m==='qr'): ?><img class="pay-qr" src="/?page=bayar_qr&amp;v=<?= e(pset('qr_ver')) ?>" alt="Kod QR pembayaran" width="220" height="220"><?php if(pset('qr_info')!==''): ?><p class="prewrap"><?= e(pset('qr_info')) ?></p><?php endif; else: ?><p class="prewrap"><?= e(pset('tunai_info')?:'Bayar di pejabat. Resit rasmi diberikan dan langganan dilanjutkan oleh pentadbir platform.') ?></p><?php endif; ?>
<p class="muted">Jumlah: <strong><?= $priceHtml ?></strong><?= $dk?' · kod '.e($dk['code']):'' ?> · Rujukan: <strong><?= e($s['code']) ?></strong></p>
<?php if($m!=='tunai'): ?><form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="pay_bukti"><input type="hidden" name="m" value="<?= e($m) ?>">
<label class="field">No. rujukan transaksi / PO<input name="rujukan" maxlength="80" required></label><label class="field">Bukti bayaran (JPG, PNG, WEBP, PDF · maks 5 MB)<input name="bukti" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required></label><button class="btn outline">Hantar bukti bayaran</button></form><?php endif; ?></details><?php endforeach; ?></div>
</div></div>
<?php endif; ?>
<h3 class="spaced">Sejarah bayaran</h3><div class="table-wrap"><table><thead><tr><th>Tarikh</th><th>Kaedah</th><th>Jumlah</th><th>Rujukan</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($hist as $p): ?><tr><td><?= e(date('d/m/Y H:i',strtotime((string)($p['paid_at']?:$p['created_at'])))) ?></td><td><?= e(pay_method_name($p['method'])) ?></td><td><?= e(ringgit((int)$p['amount_sen'])) ?><?= $p['discount_code']?'<br><small class="muted">'.e($p['discount_code']).' −'.e(ringgit((int)$p['discount_sen'])).'</small>':'' ?></td><td><code><?= e($p['ref']?:'#'.$p['id']) ?></code></td><td><?= pay_status_badge($p['status']) ?><?= in_array($p['status'],['ditolak','dibatal'],true) && $p['note']?'<br><small class="muted">'.e($p['note']).'</small>':'' ?></td><td><?php if($p['status']==='berjaya' && $p['receipt_no']): ?><a class="text-link" href="/?page=langganan&amp;resit=<?= (int)$p['id'] ?>">Resit</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$hist): ?><tr><td colspan="6" class="muted">Tiada rekod bayaran lagi.</td></tr><?php endif; ?></tbody></table></div><?php
}

/* ======================= Platform ======================= */
function pay_pending_count(): int { return (int)query('SELECT COUNT(*) FROM payments WHERE status="semak" OR (status="menunggu" AND method IN ("bank","qr"))')->fetchColumn(); }

function handle_payments_platform(string $action,array $me): never {
    $by=(string)$me['name'];
    if($action==='p_pay_approve' || $action==='p_pay_reject'){
        $p=payment_get((int)input('id')); if(!$p || !in_array($p['status'],['menunggu','semak'],true)) throw new DomainException('Bayaran ini telah berubah status. Muat semula.');
        $back='/platform/?p=bayaran&f='.$p['status'];
        if($action==='p_pay_approve'){
            if($p['status']!=='semak' && (!in_array($p['method'],['bank','qr'],true) || !$p['proof'])) throw new DomainException('Hanya bukti pindahan bank / QR atau bayaran yang ditahan boleh diluluskan di sini. Bayaran gerbang disahkan melalui API.');
            $new=pay_confirm((int)$p['id'],fn()=>true,$by); if(!$new) throw new DomainException('Bayaran tidak dapat disahkan.');
            platform_log('payment_approved',(int)$p['school_id'],$p['school_code'].' '.ringgit((int)$p['amount_sen']).' ('.$p['ref'].') → '.$new);
            flash('Bayaran '.$p['school_code'].' disahkan. Pro aktif hingga '.date('d/m/Y',strtotime($new)).'.');
        } else {
            $why=mb_substr(trim(input('sebab')),0,300)?:'Bukti tidak sah';
            if(query('UPDATE payments SET status="ditolak",note=?,by_name=? WHERE id=? AND status IN ("menunggu","semak")',[$why,mb_substr($by,0,120),$p['id']])->rowCount()!==1) throw new DomainException('Bayaran ini telah berubah status. Muat semula.');
            platform_log('payment_rejected',(int)$p['school_id'],$p['school_code'].' ('.$p['ref'].'): '.$why);
            if($s=school_by_id((int)$p['school_id'])) try { school_mail($s,'Bukti bayaran tidak diterima',"Bukti bayaran ({$p['ref']}) untuk {$s['name']} tidak dapat disahkan.\nSebab: $why\n\nSila hantar semula di halaman Langganan:\n".school_url($s['code'],'/?page=langganan')); } catch(Throwable) {}
            flash('Bayaran ditolak.');
        }
        redirect($back);
    }
    if($action==='p_pay_manual'){
        $s=school_by_id((int)input('school_id')); if(!$s || $s['status']!=='aktif') throw new DomainException('Sekolah tidak aktif.');
        $amt=str_replace(',','',trim(input('jumlah'))); if(!preg_match('/^\d{1,6}(\.\d{1,2})?$/',$amt)) throw new DomainException('Jumlah tidak sah (cth. 120.00).');
        $ref=mb_substr(trim(input('rujukan')),0,80); if($ref==='') throw new DomainException('Masukkan no. resit / rujukan.');
        $p=pay_record_manual($s,input('kaedah'),(int)round((float)$amt*100),(int)input('tahun'),$ref,$by);
        platform_log('payment_manual',(int)$s['id'],pay_method_name($p['method']).' '.ringgit((int)$p['amount_sen']).' ('.$ref.') → '.$p['expires_to']);
        flash('Bayaran direkod (resit '.$p['receipt_no'].'). Pro aktif hingga '.date('d/m/Y',strtotime((string)$p['expires_to'])).'.'); redirect('/platform/?p=sekolah&id='.(int)$s['id']);
    }
    if($action==='p_pay_methods'){
        $errs=[];
        foreach(PAY_GATEWAYS as $g=>[$name]) if(input('gw_'.$g)==='1') foreach(GW_FIELDS[$g] as [$k,,$sec]) if(trim(input($k))==='' && ($sec?gw_secret($k):'')==='') $errs[]=$name.': lengkapkan semua medan untuk mengaktifkan.';
        if($errs) throw new DomainException(implode(' ',array_unique($errs)));
        $kv=['bayaran_aktif'=>input('bayaran_aktif')==='1'?'1':'0'];
        foreach(PAY_GATEWAYS as $g=>$_){
            $kv['gw_'.$g]=input('gw_'.$g)==='1'?'1':'0';
            foreach(GW_FIELDS[$g] as [$k,,$sec]){ $v=trim(input($k)); if($sec){ if($v!==''){ $kv[$k]=pencrypt($v); if($k==='chip_key') $kv+=['chip_pubkey'=>'','chip_pubkey_at'=>'0']; } } else $kv[$k]=mb_substr($v,0,120); }
            if(isset(GW_SANDBOX[$g])) $kv[GW_SANDBOX[$g][0]]=input(GW_SANDBOX[$g][0])==='1'?'1':'0';
        }
        foreach(PAY_MANUAL as $m=>$_) $kv['m_'.$m]=input('m_'.$m)==='1'?'1':'0';
        foreach(['info_bank','qr_info','tunai_info'] as $k) $kv[$k]=mb_substr(str_replace("\r\n","\n",trim(input($k))),0,1000);
        if($kv['m_bank']==='1' && $kv['info_bank']==='') throw new DomainException('Isi maklumat akaun bank untuk mengaktifkan pindahan bank.');
        if(!empty($_FILES['qr']['name'])){
            $f=$_FILES['qr']; if(($f['error']??1)!==UPLOAD_ERR_OK || $f['size']>2*1048576) throw new DomainException('Imej QR maks 2 MB.');
            if(!in_array((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']),['image/png','image/jpeg','image/webp'],true)) throw new DomainException('Imej QR mesti PNG, JPG atau WEBP.');
            $im=@imagecreatefromstring((string)file_get_contents($f['tmp_name'])); if(!$im) throw new DomainException('Imej QR tidak sah.');
            ob_start(); imagepng($im,null,9); $png=(string)ob_get_clean(); imagedestroy($im); // dikod semula: metadata dibuang
            $kv+=['qr_data'=>base64_encode($png),'qr_ver'=>substr(hash('sha256',$png),0,16)];
        }
        if($kv['m_qr']==='1' && pset('qr_ver')==='' && empty($kv['qr_ver'])) throw new DomainException('Muat naik imej QR untuk mengaktifkan DuitNow QR.');
        pset_set($kv); platform_log('payment_methods_saved',null,'gerbang aktif: '.(implode(', ',gw_list())?:'tiada'));
        $t=gw_sandbox_active(); if($t) platform_log('payment_sandbox_warning',null,implode(', ',$t));
        flash('Kaedah bayaran disimpan.'.($t?' Perhatian: '.implode(', ',array_map(fn($g)=>PAY_GATEWAYS[$g][0],$t)).' masih dalam MOD UJIAN.':'')); redirect('/platform/?p=kaedah');
    }
    if($action==='p_pay_qr_remove'){ pset_set(['qr_data'=>'','qr_ver'=>'','m_qr'=>'0']); platform_log('payment_qr_removed'); flash('Imej QR dibuang; DuitNow QR dimatikan.'); redirect('/platform/?p=kaedah'); }
    if($action==='p_disc_add'){
        $code=discount_normal(input('kod')); if(!preg_match('/^[A-Z0-9_-]{3,30}$/',$code)) throw new DomainException('Kod mesti 3–30 aksara (huruf, nombor, _ atau -).');
        $kind=input('jenis')==='tetap'?'tetap':'peratus'; $raw=str_replace(',','',trim(input('nilai')));
        $val=$kind==='peratus'?(int)$raw:(int)round((float)$raw*100);
        if(!preg_match('/^\d{1,6}(\.\d{1,2})?$/',$raw) || $val<1 || ($kind==='peratus' && $val>100)) throw new DomainException('Nilai diskaun tidak sah.');
        $end=trim(input('tamat')); if($end!=='' && (!($d=DateTimeImmutable::createFromFormat('!Y-m-d',$end)) || $d->format('Y-m-d')!==$end)) throw new DomainException('Tarikh tamat tidak sah.');
        $max=trim(input('had')); if($max!=='' && (!ctype_digit($max) || (int)$max<1)) throw new DomainException('Had penggunaan tidak sah.');
        if(query('SELECT id FROM discounts WHERE code=?',[$code])->fetchColumn()) throw new DomainException('Kod '.$code.' sudah wujud.');
        query('INSERT INTO discounts(code,kind,value,ends_on,max_uses,note) VALUES (?,?,?,?,?,?)',[$code,$kind,$val,$end?:null,$max!==''?(int)$max:null,mb_substr(trim(input('catatan')),0,200)]);
        platform_log('discount_created',null,$code.' '.($kind==='peratus'?$val.'%':ringgit($val))); flash('Kod diskaun '.$code.' dicipta.'); redirect('/platform/?p=diskaun');
    }
    if($action==='p_disc_toggle'){ query('UPDATE discounts SET active=1-active WHERE id=?',[(int)input('id')]); platform_log('discount_toggled',null,'#'.(int)input('id')); redirect('/platform/?p=diskaun'); }
    if($action==='p_disc_delete'){
        $d=query('SELECT * FROM discounts WHERE id=?',[(int)input('id')])->fetch(); if(!$d) throw new DomainException('Kod tidak dijumpai.');
        if((int)query('SELECT COUNT(*) FROM payments WHERE discount_code=?',[$d['code']])->fetchColumn()>0) throw new DomainException('Kod ini telah digunakan — nyahaktifkan sahaja (sejarah bayaran dikekalkan).');
        query('DELETE FROM discounts WHERE id=?',[$d['id']]); platform_log('discount_deleted',null,$d['code']); flash('Kod '.$d['code'].' dipadam.'); redirect('/platform/?p=diskaun');
    }
    if($action==='p_tj_sponsor'){
        $name=trim(input('nama')); $email=strtolower(trim(input('emel')));
        if($name==='' || mb_strlen($name)>150) throw new DomainException('Nama penaja perlu diisi.');
        if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mel penaja tidak sah.');
        query('INSERT INTO sponsors(name,email,phone,note) VALUES (?,?,?,?)',[$name,$email,mb_substr(trim(input('telefon')),0,30),mb_substr(trim(input('catatan')),0,500)]);
        platform_log('sponsor_created',null,$name); flash('Penaja '.$name.' ditambah.'); redirect('/platform/?p=tajaan');
    }
    if($action==='p_tj_give'){
        $sp=(int)input('penaja'); $mode=input('mod'); $val=match($mode){ 'tahun'=>input('tahun'),'lanjut'=>input('lanjut'),'tarikh'=>input('tarikh'),default=>'' };
        $amt=str_replace(',','',trim(input('nilai'))?:'0'); if(!preg_match('/^\d{1,7}(\.\d{1,2})?$/',$amt)) throw new DomainException('Nilai tajaan tidak sah.');
        $codes=array_values(array_unique(array_filter(array_map(fn($c)=>strtoupper(trim($c)),preg_split('/[\s,;]+/',input('kod_sekolah')))))); if(!$codes) throw new DomainException('Masukkan sekurang-kurangnya satu kod sekolah.');
        if(count($codes)>500) throw new DomainException('Maksimum 500 sekolah setiap kali.');
        $ok=0; $fail=[];
        foreach($codes as $c){ $s=school_find($c); if(!$s){ $fail[]=$c.' (tidak dijumpai)'; continue; }
            $r=sponsorship_give($sp,$s,$mode,$val,(int)round((float)$amt*100),mb_substr(trim(input('rujukan')),0,100),$by,input('emel_sekolah')==='1');
            if($r['ok']) $ok++; else $fail[]=$c.' ('.$r['why'].')'; }
        flash($ok.' sekolah ditaja.'.($fail?' Tidak diproses: '.implode('; ',array_slice($fail,0,10)).(count($fail)>10?' …':''):'')); redirect('/platform/?p=tajaan');
    }
    if($action==='p_tj_cancel'){ if(!sponsorship_cancel((int)input('id'),$by)) throw new DomainException('Tajaan tidak aktif.'); flash('Tajaan dibatalkan.'); redirect('/platform/?p=tajaan'); }
    error_page(400);
}

const PAY_FILTERS=['berjaya'=>'Berjaya','menunggu'=>'Menunggu','semak'=>'Perlu semak','ditolak'=>'Ditolak','gagal'=>'Gagal / luput','dibatal'=>'Dibatalkan'];
function platform_page_payments(): void {
    $f=input('f','get'); if(!isset(PAY_FILTERS[$f])) $f=pay_pending_count()?'menunggu':'berjaya';
    $sql='SELECT p.*,s.name school FROM payments p LEFT JOIN schools s ON s.id=p.school_id WHERE p.status=?';
    if($f==='menunggu') $sql.=' AND (p.method IN ("bank","qr") OR p.created_at>=DATE_SUB(NOW(),INTERVAL 3 DAY))';
    $rows=query($sql.' ORDER BY p.id DESC LIMIT 300',[$f])->fetchAll(); $sum=array_sum(array_map(fn($r)=>(int)$r['amount_sen'],$rows));
    $tot=query('SELECT COALESCE(SUM(amount_sen),0) a,COUNT(*) n FROM payments WHERE status="berjaya" AND YEAR(paid_at)=YEAR(CURDATE())')->fetch(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Bayaran</h1><p class="muted">Kutipan <?= date('Y') ?>: <strong><?= e(ringgit((int)$tot['a'])) ?></strong> (<?= (int)$tot['n'] ?> bayaran berjaya).</p></div><div class="button-group"><a class="btn outline" href="/platform/?p=kaedah">Kaedah Bayaran</a></div></div>
<?= gw_sandbox_banner(true) ?>
<nav class="takwim-filter" aria-label="Tapis bayaran"><?php foreach(PAY_FILTERS as $k=>$l): ?><a class="chip<?= $f===$k?' on':'' ?>" href="/platform/?p=bayaran&amp;f=<?= $k ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<section class="panel spaced"><div class="panel-heading"><div><p class="muted"><?= count($rows) ?> rekod · <?= e(ringgit($sum)) ?></p></div></div><div class="table-wrap"><table><thead><tr><th>Tarikh</th><th>Sekolah</th><th>Kaedah</th><th class="num">Jumlah</th><th>Rujukan</th><th><?= in_array($f,['menunggu','semak'],true)?'Tindakan':'Oleh' ?></th></tr></thead><tbody>
<?php foreach($rows as $r): $act=$f==='semak' || ($f==='menunggu' && in_array($r['method'],['bank','qr'],true)); ?><tr><td><?= e(date('d/m/Y H:i',strtotime((string)($r['paid_at']?:$r['created_at'])))) ?></td>
<td><?php if($r['school_id']): ?><a class="text-link" href="/platform/?p=sekolah&amp;id=<?= (int)$r['school_id'] ?>"><strong><?= e($r['school_code']) ?></strong></a> <small class="muted"><?= e((string)$r['school']) ?></small><?php else: ?><strong><?= e($r['school_code']) ?></strong> <small class="muted">(dipadam)</small><?php endif; ?></td>
<td><?= e(pay_method_name($r['method'])) ?></td><td class="num"><?= e(ringgit((int)$r['amount_sen'])) ?><?= $r['discount_code']?'<br><small class="muted">'.e($r['discount_code']).' −'.e(ringgit((int)$r['discount_sen'])).'</small>':'' ?></td>
<td><code><?= e($r['ref']?:'#'.$r['id']) ?></code><?= $r['note']?'<br><small class="muted">'.e($r['note']).'</small>':'' ?></td>
<td><?php if($act): ?><div class="button-group"><?php if($r['proof']): ?><a class="btn sm outline" href="/platform/?p=bayaran&amp;bukti=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">Bukti</a><?php endif; ?>
<form method="post" data-confirm="Sahkan bayaran <?= e($r['school_code']) ?> dan lanjutkan Pro 1 tahun?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm primary" name="action" value="p_pay_approve">Sahkan</button></form>
<form method="post" class="inline-form" data-confirm="Tolak bayaran ini?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="sebab" maxlength="300" placeholder="Sebab" aria-label="Sebab penolakan"><button class="btn sm danger" name="action" value="p_pay_reject">Tolak</button></form></div>
<?php elseif($f==='menunggu'): ?><small class="muted">Menunggu gerbang</small><?php else: ?><?= e($r['by_name']) ?><?= $r['status']==='berjaya' && $r['receipt_no']?' · <a class="text-link" href="/platform/?p=bayaran&amp;resit='.(int)$r['id'].'">'.e($r['receipt_no']).'</a>':'' ?><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="6" class="muted">Tiada rekod.</td></tr><?php endif; ?></tbody></table></div></section><?php
}

function platform_page_paymethods(): void {
    $chk=fn(string $k)=>pset($k,'0')==='1'?' checked':''; ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Kaedah Bayaran</h1><p class="muted">Harga Pro <?= e(ringgit(pay_price())) ?> setahun (ubah di Tetapan Platform). Kunci rahsia disimpan bersulit; biarkan kosong untuk mengekalkan nilai tersimpan.</p></div></div>
<?= gw_sandbox_banner(true) ?>
<form method="post" enctype="multipart/form-data" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="p_pay_methods">
<section class="panel padded spaced"><label class="check full"><input type="checkbox" name="bayaran_aktif" value="1"<?= pay_enabled()?' checked':'' ?>> <span><strong>Bayaran langganan diaktifkan</strong> — matikan jika langganan dibiayai PPD / percuma; sekolah tidak nampak pilihan bayaran dan tarikh tamat diurus di halaman sekolah.</span></label></section>
<h3 class="spaced">Gerbang dalam talian</h3><div class="dashboard-grid pay-grid">
<?php foreach(PAY_GATEWAYS as $g=>[$name,$desc]): ?><section class="panel padded"><label class="check full"><input type="checkbox" name="gw_<?= $g ?>" value="1"<?= $chk('gw_'.$g) ?>> <span><strong><?= e($name) ?></strong> <small class="muted"><?= e($desc) ?></small> <?= gw_ready($g)?'<span class="badge green">Dikonfigurasi</span>':'<span class="badge">Belum</span>' ?></span></label>
<div class="form-grid"><?php foreach(GW_FIELDS[$g] as [$k,$label,$sec]): ?><label class="field full"><?= e($label) ?><input name="<?= $k ?>" <?= $sec?'type="password" autocomplete="new-password" placeholder="'.(gw_secret($k)!==''?'•••••••• (disimpan)':'').'"':'value="'.e(pset($k)).'"' ?> maxlength="200"></label><?php endforeach; ?>
<?php if(isset(GW_SANDBOX[$g])): ?><label class="check full"><input type="checkbox" name="<?= GW_SANDBOX[$g][0] ?>" value="1"<?= $chk(GW_SANDBOX[$g][0]) ?>> Mod ujian (<?= e(GW_SANDBOX[$g][1]) ?>)</label><?php else: ?><p class="muted full">Mod ujian CHIP: guna kunci ujian (brand ujian) — tiada hos sandbox berasingan.</p><?php endif; ?>
<p class="muted full">URL callback: <code><?= e(pay_callback_url($g)) ?></code></p></div></section><?php endforeach; ?></div>
<h3 class="spaced">Kaedah manual</h3><section class="panel padded"><div class="form-grid">
<label class="check full"><input type="checkbox" name="m_bank" value="1"<?= $chk('m_bank') ?>> <strong>Pindahan bank / PO</strong> — sekolah muat naik slip, anda sahkan di Bayaran</label>
<label class="field full">Maklumat akaun bank<textarea name="info_bank" rows="3" maxlength="1000" placeholder="Nama bank, no. akaun, nama pemegang akaun"><?= e(pset('info_bank')) ?></textarea></label>
<label class="check full"><input type="checkbox" name="m_qr" value="1"<?= $chk('m_qr') ?>> <strong>DuitNow QR</strong> — imbas QR, kemudian muat naik resit</label>
<label class="field">Imej QR (PNG/JPG/WEBP, maks 2 MB)<input type="file" name="qr" accept="image/png,image/jpeg,image/webp"></label>
<label class="field">Arahan QR<textarea name="qr_info" rows="2" maxlength="1000"><?= e(pset('qr_info')) ?></textarea></label>
<label class="check full"><input type="checkbox" name="m_tunai" value="1"<?= $chk('m_tunai') ?>> <strong>Tunai</strong> — direkod oleh anda di halaman sekolah</label>
<label class="field full">Arahan tunai<textarea name="tunai_info" rows="2" maxlength="1000"><?= e(pset('tunai_info')) ?></textarea></label></div>
<div class="button-group"><button class="btn primary">Simpan kaedah bayaran</button></div></section></form>
<?php if(pset('qr_ver')!==''): ?><section class="panel padded spaced"><p class="muted">Imej QR tersimpan (versi <code><?= e(pset('qr_ver')) ?></code>).</p><form method="post" data-confirm="Buang imej QR dan matikan DuitNow QR?"><?= csrf_field() ?><button class="btn outline" name="action" value="p_pay_qr_remove">Buang imej QR</button></form></section><?php endif;
}

function platform_page_discounts(): void {
    $rows=query('SELECT d.*,(SELECT COUNT(*) FROM payments p WHERE p.discount_code=d.code AND p.status="berjaya") used,(SELECT COALESCE(SUM(discount_sen),0) FROM payments p WHERE p.discount_code=d.code AND p.status="berjaya") cut FROM discounts d ORDER BY d.id DESC')->fetchAll(); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Kod Diskaun</h1><p class="muted">Setiap sekolah boleh menggunakan satu kod sekali sahaja. Harga selepas diskaun minimum <?= e(ringgit(DISCOUNT_MIN_SEN)) ?>.</p></div></div>
<section class="panel padded spaced"><h3>Kod baharu</h3><form method="post" class="form-grid" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="p_disc_add">
<label class="field">Kod<input name="kod" maxlength="30" required placeholder="DIDIK20" autocapitalize="characters"></label>
<label class="field">Jenis<select name="jenis"><option value="peratus">Peratus (%)</option><option value="tetap">Amaun tetap (RM)</option></select></label>
<label class="field">Nilai<input name="nilai" inputmode="decimal" required placeholder="20"></label>
<label class="field">Tamat (pilihan)<input name="tamat" type="date"></label>
<label class="field">Had penggunaan keseluruhan (pilihan)<input name="had" inputmode="numeric" placeholder="tiada had"></label>
<label class="field">Catatan<input name="catatan" maxlength="200"></label><div class="button-group"><button class="btn primary">Cipta kod</button></div></form></section>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Kod</th><th>Diskaun</th><th>Tamat</th><th>Digunakan</th><th>Jumlah potongan</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($rows as $d): ?><tr><td><strong><?= e($d['code']) ?></strong><?= $d['note']?'<br><small class="muted">'.e($d['note']).'</small>':'' ?></td><td><?= e(discount_label($d)) ?></td><td><?= $d['ends_on']?e(date('d/m/Y',strtotime($d['ends_on']))):'—' ?></td><td><?= (int)$d['used'] ?><?= $d['max_uses']!==null?' / '.(int)$d['max_uses']:'' ?></td><td><?= e(ringgit((int)$d['cut'])) ?></td>
<td><?= (int)$d['active']?($d['ends_on'] && $d['ends_on']<date('Y-m-d')?'<span class="badge warn">Tamat</span>':'<span class="badge green">Aktif</span>'):'<span class="badge">Tidak aktif</span>' ?></td>
<td><div class="button-group"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn sm outline" name="action" value="p_disc_toggle"><?= (int)$d['active']?'Nyahaktif':'Aktifkan' ?></button></form><?php if(!(int)$d['used']): ?><form method="post" data-confirm="Padam kod <?= e($d['code']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn sm danger" name="action" value="p_disc_delete">Padam</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="7" class="muted">Tiada kod diskaun.</td></tr><?php endif; ?></tbody></table></div></section><?php
}

function platform_page_sponsors(): void {
    $sponsors=query('SELECT p.*,(SELECT COUNT(*) FROM sponsorships t WHERE t.sponsor_id=p.id AND t.status="aktif" AND t.end_on>=CURDATE()) active FROM sponsors p ORDER BY p.name')->fetchAll();
    $rows=query('SELECT t.*,p.name sponsor,s.code,s.name school,s.expires_on FROM sponsorships t JOIN sponsors p ON p.id=t.sponsor_id JOIN schools s ON s.id=t.school_id ORDER BY t.id DESC LIMIT 300')->fetchAll(); $y=(int)date('Y'); ?>
<div class="page-heading"><div><span class="eyebrow teal">PLATFORM</span><h1>Tajaan</h1><p class="muted">Penaja (PPD, syarikat, PIBG …) membiayai langganan Pro sekolah — percuma kepada sekolah. Penaja diingatkan 30 hari sebelum tajaan tamat.</p></div></div>
<div class="dashboard-grid pay-grid"><section class="panel padded"><h3>Taja sekolah</h3><?php if(!$sponsors): ?><p class="muted">Tambah penaja dahulu.</p><?php else: ?><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_tj_give">
<label class="field full">Penaja<select name="penaja"><?php foreach($sponsors as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
<label class="field full">Kod sekolah (satu atau banyak, dipisah ruang / koma)<textarea name="kod_sekolah" rows="3" required placeholder="CEA1234, CEA1235"></textarea></label>
<label class="field">Tempoh<select name="mod"><option value="tahun">Hingga 31 Disember tahun</option><option value="lanjut">Lanjut n tahun</option><option value="tarikh">Hingga tarikh</option></select></label>
<label class="field">Tahun<select name="tahun"><?php for($i=$y;$i<=$y+5;$i++): ?><option><?= $i ?></option><?php endfor; ?></select></label>
<label class="field">n tahun<select name="lanjut"><?php for($i=1;$i<=5;$i++): ?><option><?= $i ?></option><?php endfor; ?></select></label>
<label class="field">Tarikh<input type="date" name="tarikh"></label>
<label class="field">Nilai setiap sekolah (RM, rekod)<input name="nilai" inputmode="decimal" placeholder="0.00"></label>
<label class="field">Rujukan (no. surat / PO)<input name="rujukan" maxlength="100"></label>
<label class="check full"><input type="checkbox" name="emel_sekolah" value="1" checked> Maklumkan sekolah melalui e-mel</label>
<div class="button-group"><button class="btn primary">Taja</button></div></form><?php endif; ?></section>
<section class="panel padded"><h3>Penaja baharu</h3><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_tj_sponsor">
<label class="field full">Nama<input name="nama" maxlength="150" required></label><label class="field">E-mel<input name="emel" type="email" maxlength="190"></label><label class="field">Telefon<input name="telefon" maxlength="30"></label><label class="field full">Catatan<input name="catatan" maxlength="500"></label><div class="button-group"><button class="btn outline">Tambah penaja</button></div></form>
<?php if($sponsors): ?><ul class="plain-list"><?php foreach($sponsors as $p): ?><li><strong><?= e($p['name']) ?></strong> <small class="muted"><?= e($p['email']) ?> · <?= (int)$p['active'] ?> tajaan aktif</small></li><?php endforeach; ?></ul><?php endif; ?></section></div>
<section class="panel spaced"><div class="table-wrap"><table><thead><tr><th>Sekolah</th><th>Penaja</th><th>Tempoh</th><th>Nilai</th><th>Rujukan</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($rows as $t): ?><tr><td><a class="text-link" href="/platform/?p=sekolah&amp;id=<?= (int)$t['school_id'] ?>"><strong><?= e($t['code']) ?></strong></a> <small class="muted"><?= e($t['school']) ?></small></td><td><?= e($t['sponsor']) ?></td><td><?= e(date('d/m/Y',strtotime($t['start_on']))) ?> – <?= e(date('d/m/Y',strtotime($t['end_on']))) ?></td><td><?= e(ringgit((int)$t['value_sen'])) ?></td><td><?= e($t['ref']) ?></td>
<td><?= $t['status']==='batal'?'<span class="badge">Dibatalkan</span>':($t['end_on']<date('Y-m-d')?'<span class="badge">Tamat</span>':'<span class="badge green">Aktif</span>') ?></td>
<td><?php if($t['status']==='aktif' && $t['end_on']>=date('Y-m-d')): ?><form method="post" data-confirm="Batalkan tajaan <?= e($t['code']) ?>? Tarikh tamat dipulihkan jika belum berubah."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn sm outline" name="action" value="p_tj_cancel">Batal</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="7" class="muted">Tiada tajaan.</td></tr><?php endif; ?></tbody></table></div></section><?php
}

/** Panel bayaran di halaman sekolah (platform): rekod bayaran manual + sejarah. */
function platform_payment_panel(array $s): void {
    $rows=query('SELECT * FROM payments WHERE school_id=? ORDER BY id DESC LIMIT 10',[$s['id']])->fetchAll(); $tj=sponsorship_current((int)$s['id']); ?>
<?php if($tj): ?><p class="notice success">Ditaja oleh <strong><?= e($tj['sponsor']) ?></strong> hingga <?= e(date('d/m/Y',strtotime($tj['end_on']))) ?>.</p><?php endif; ?>
<?php if($s['status']==='aktif'): ?><details class="sch-pay"><summary>Rekod bayaran manual</summary><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="p_pay_manual"><input type="hidden" name="school_id" value="<?= (int)$s['id'] ?>">
<label class="field">Kaedah<select name="kaedah"><option value="tunai">Tunai</option><option value="bank">Pindahan bank / PO</option><option value="qr">DuitNow QR</option><option value="manual">Lain-lain</option></select></label>
<label class="field">Jumlah (RM)<input name="jumlah" inputmode="decimal" value="<?= e(number_format(pay_price()/100,2,'.','')) ?>" required></label>
<label class="field">Tempoh<select name="tahun"><?php for($i=1;$i<=5;$i++): ?><option value="<?= $i ?>"><?= $i ?> tahun</option><?php endfor; ?></select></label>
<label class="field">No. resit / rujukan<input name="rujukan" maxlength="80" required></label><div class="button-group"><button class="btn primary" data-confirm-simple="Rekod bayaran dan lanjutkan Pro?">Rekod bayaran</button></div></form></details><?php endif; ?>
<?php if($rows): ?><h4 class="sch-h4">Sejarah bayaran</h4><div class="table-wrap"><table><thead><tr><th>Tarikh</th><th>Kaedah</th><th>Jumlah</th><th>Rujukan</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e(date('d/m/Y',strtotime((string)($r['paid_at']?:$r['created_at'])))) ?></td><td><?= e(pay_method_name($r['method'])) ?></td><td><?= e(ringgit((int)$r['amount_sen'])) ?></td><td><code><?= e($r['ref']?:'#'.$r['id']) ?></code></td><td><?= pay_status_badge($r['status']) ?><?= $r['status']==='berjaya' && $r['receipt_no']?' <a class="text-link" href="/platform/?p=bayaran&amp;resit='.(int)$r['id'].'">'.e($r['receipt_no']).'</a>':'' ?></td></tr><?php endforeach; ?></tbody></table></div><p><a class="text-link" href="/platform/?p=bayaran">Semua bayaran →</a></p><?php endif;
}
