<?php
declare(strict_types=1);
/*
 * Bayaran langganan Pro (corak delima-multi).
 *  Gerbang dalam talian : ToyyibPay, Billplz, CHIP, SecurePay — suis gw_<nama> + kunci API (rahsia disulitkan dengan kunci platform).
 *  Manual               : pindahan bank / PO dan DuitNow QR (sekolah muat naik bukti → platform sahkan), tunai (direkod platform).
 *  Suis utama           : bayaran_aktif ('0' = tiada bayaran dalam sistem; tarikh tamat diurus platform sahaja).
 * Pengesahan dalam talian SENTIASA melalui tandatangan callback + API gerbang (SecurePay: tandatangan HMAC sahaja),
 * tidak sekali-kali berdasarkan parameter URL kembali. Mod ujian (sandbox) tidak melanjutkan langganan secara automatik.
 * Bayaran berjaya: Pro dilanjutkan 1 tahun dari tarikh tamat semasa (atau hari ini jika sudah tamat), resit bernombor, e-mel.
 * Kod diskaun: sekali bagi setiap sekolah (pilihan had penggunaan keseluruhan). Tajaan: penaja membiayai Pro sekolah (RM0 kepada sekolah).
 */

const PAY_GATEWAYS=[
    'toyyibpay'=>['ToyyibPay','FPX'],
    'billplz'=>['Billplz','FPX, kad, e-dompet'],
    'chip'=>['CHIP','FPX, kad, e-dompet, DuitNow QR'],
    'securepay'=>['SecurePay','FPX'],
];
const PAY_MANUAL=[
    'bank'=>['Pindahan bank / PO','Muat naik slip / resit selepas membayar'],
    'qr'=>['DuitNow QR','Imbas QR, kemudian muat naik resit'],
    'tunai'=>['Tunai','Bayar di kaunter — direkod oleh pentadbir platform'],
];
/** Medan tetapan setiap gerbang: [kunci, label, rahsia?]. Rahsia disimpan bersulit (pencrypt). */
const GW_FIELDS=[
    'toyyibpay'=>[['tp_secret','User Secret Key',true],['tp_category','Category Code',false]],
    'billplz'=>[['bp_key','API Secret Key',true],['bp_collection','Collection ID',false],['bp_xsig','X Signature Key',true]],
    'chip'=>[['chip_key','Secret Key (API key)',true],['chip_brand','Brand ID',false]],
    'securepay'=>[['sp_uid','UID',false],['sp_token','Auth Token',true],['sp_checksum','Checksum Token',true]],
];
const GW_SANDBOX=['toyyibpay'=>['tp_sandbox','dev.toyyibpay.com'],'billplz'=>['bp_sandbox','billplz-sandbox.com'],'securepay'=>['sp_sandbox','sandbox.securepay.my']];
const PAY_STATUS=['berjaya'=>['Berjaya','green'],'menunggu'=>['Menunggu','warn'],'semak'=>['Perlu semak','warn'],'ditolak'=>['Ditolak','warn'],'gagal'=>['Gagal / luput',''],'dibatal'=>['Dibatalkan','']];
const DISCOUNT_MIN_SEN=100; // had minimum gerbang (RM1.00)

function ringgit(int $sen): string { return 'RM'.number_format($sen/100,2); }
function pay_price(): int { return max(100,(int)pset('harga_pro_sen')); }
function pay_enabled(): bool { return pset('bayaran_aktif','1')==='1'; }
function gw_secret(string $k): string { return pdecrypt(pset($k)); }
function gw_value(string $k,bool $secret): string { return $secret?gw_secret($k):trim(pset($k)); }
function gw_ready(string $g): bool { foreach(GW_FIELDS[$g]??[[null]] as $f){ if($f[0]===null || gw_value($f[0],$f[2])==='') return false; } return true; }
/** Lalai MATI: mengisi kunci API sahaja tidak mengaktifkan gerbang. */
function gw_on(string $g): bool { return pset('gw_'.$g,'0')==='1'; }
function gw_active(string $g): bool { return isset(PAY_GATEWAYS[$g]) && gw_on($g) && gw_ready($g); }
function gw_list(): array { return pay_enabled()?array_values(array_filter(array_keys(PAY_GATEWAYS),'gw_active')):[]; }
function manual_on(string $m): bool { return pay_enabled() && isset(PAY_MANUAL[$m]) && pset('m_'.$m,'0')==='1' && ($m!=='qr' || pset('qr_ver')!==''); }
function manual_list(): array { return array_values(array_filter(array_keys(PAY_MANUAL),'manual_on')); }
/** Mod ujian: lalai PRODUKSI — sandbox mesti dipilih secara eksplisit. CHIP menggunakan kunci ujian pada hos yang sama. */
function gw_sandbox(string $g): bool { return isset(GW_SANDBOX[$g]) && pset(GW_SANDBOX[$g][0],'0')==='1'; }
function gw_sandbox_active(): array { return array_values(array_filter(gw_list(),'gw_sandbox')); }
function gw_sandbox_banner(bool $platform=false): string {
    $gs=gw_sandbox_active(); if(!$gs) return '';
    $n=e(implode(', ',array_map(fn($g)=>PAY_GATEWAYS[$g][0],$gs)));
    return '<div class="notice error" role="status"><span><strong>MOD UJIAN (SANDBOX):</strong> '.$n.($platform?'. Bayaran melalui gerbang ini bukan wang sebenar — ia TIDAK melanjutkan langganan tetapi ditahan di Bayaran → Perlu semak. Matikan mod ujian di Kaedah Bayaran sebelum menerima bayaran sebenar.':' sedang diuji. Bayaran melalui gerbang ini bukan wang sebenar dan tidak akan mengaktifkan langganan.').'</span></div>';
}
function pay_method_name(string $k): string { return PAY_GATEWAYS[$k][0]??PAY_MANUAL[$k][0]??(['manual'=>'Manual (platform)','tajaan'=>'Tajaan'][$k]??$k); }
function pay_status_badge(string $st): string { [$l,$c]=PAY_STATUS[$st]??[$st,'']; return '<span class="badge '.$c.'">'.e($l).'</span>'; }
function payment_get(int $id): ?array { return query('SELECT * FROM payments WHERE id=?',[$id])->fetch()?:null; }

/* ---------- HTTP ---------- */
/** Hos asas gerbang. PAYMENT_TEST_BASE (.env, ujian sahaja) hanya diterima untuk hos tempatan. */
function gw_base(string $default): string {
    $t=env('PAYMENT_TEST_BASE'); if($t!=='' && preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?$~',rtrim($t,'/'))) return rtrim($t,'/');
    return $default;
}
function gw_http(string $method,string $url,array $opt=[]): array {
    $ch=curl_init($url); $h=$opt['headers']??[];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS|CURLPROTO_HTTP,CURLOPT_FOLLOWLOCATION=>false]);
    if(isset($opt['json'])){ $h[]='Content-Type: application/json'; curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($opt['json'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
    elseif(isset($opt['form'])) curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($opt['form']));
    if(isset($opt['basic'])) curl_setopt($ch,CURLOPT_USERPWD,$opt['basic']);
    curl_setopt($ch,CURLOPT_HTTPHEADER,$h);
    $res=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch);
    if($res===false) throw new RuntimeException('Sambungan ke gerbang gagal: '.$err);
    $j=json_decode((string)$res,true);
    return [$code,is_array($j)?$j:null,(string)$res];
}
function gw_error(?array $j,string $raw,int $c): string {
    $m=$j['error']['message']??$j['message']??$j['detail']??(is_array($j['__all__']??null)?implode(' ',$j['__all__']):null);
    return 'HTTP '.$c.' '.mb_substr(is_string($m)?$m:trim(strip_tags($raw)),0,200);
}
/** Hos API ikut mod bil: $sb null = tetapan semasa (bil baharu); bil sedia ada guna mod semasa ia dicipta (payments.sandbox). */
function tp_base(?bool $sb=null): string { return gw_base(($sb??gw_sandbox('toyyibpay'))?'https://dev.toyyibpay.com':'https://toyyibpay.com'); }
function bp_base(?bool $sb=null): string { return gw_base(($sb??gw_sandbox('billplz'))?'https://www.billplz-sandbox.com':'https://www.billplz.com'); }
function chip_base(): string { return gw_base('https://gate.chip-in.asia'); }
function sp_base(): string { return gw_base(gw_sandbox('securepay')?'https://sandbox.securepay.my':'https://securepay.my'); }
/** Hos gerbang untuk CSP form-action (redirect selepas POST & borang SecurePay). */
function gw_csp_hosts(): string {
    $h='https://toyyibpay.com https://dev.toyyibpay.com https://www.billplz.com https://www.billplz-sandbox.com https://gate.chip-in.asia https://securepay.my https://sandbox.securepay.my';
    $t=gw_base(''); return $t!==''?$h.' '.$t:$h;
}
/** ToyyibPay: billName/billDescription hanya huruf, nombor, ruang dan '_'. */
function tp_text(string $s,int $max): string { return mb_substr(trim((string)preg_replace('/\s+/',' ',(string)preg_replace('/[^A-Za-z0-9 _]/',' ',$s))),0,$max); }

/* ---------- Pautan kembali (tanpa sesi: kuki SameSite=Strict tidak dihantar dari tapak gerbang) ---------- */
function pay_return_token(int $id): string { return substr(hash_hmac('sha256','pay-return:'.$id,platform_key()),0,32); }
/** URL mutlak (gerbang memerlukan skema + hos). */
function pay_abs(string $u): string { return str_starts_with($u,'/')?tenant_scheme().'://'.(platform_domain()?:request_host()).$u:$u; }
function pay_return_url(array $s,int $id): string { return pay_abs(school_url($s['code'],'/?page=bayar_kembali&byr='.$id.'&t='.pay_return_token($id))); }
function pay_callback_url(string $g): string { return pay_abs('/bayar-callback?g='.$g); }

/* ---------- Cipta bayaran dalam talian ---------- */
/**
 * Pulangkan ['url'=>…] untuk redirect, atau ['post'=>url,'fields'=>[…]] untuk borang POST (SecurePay).
 * $dk = hasil discount_check() (pilihan). @throws RuntimeException
 */
function pay_create_online(string $g,array $s,array $payor,?array $dk=null): array {
    if(!gw_active($g)) throw new RuntimeException('Kaedah bayaran ini tidak tersedia.');
    $sen=$dk && $dk['ok']?(int)$dk['final']:pay_price();
    query('INSERT INTO payments(school_id,school_code,method,amount_sen,years,sandbox,status,payer_name,payer_email,payer_phone,by_name,discount_code,discount_sen) VALUES (?,?,?,?,1,?,"menunggu",?,?,?,?,?,?)',
        [$s['id'],$s['code'],$g,$sen,gw_sandbox($g)?1:0,mb_substr($payor['name'],0,120),mb_substr($payor['email'],0,190),mb_substr($payor['phone'],0,30),mb_substr($payor['by']??$payor['name'],0,120),$dk && $dk['ok']?$dk['code']:null,$dk && $dk['ok']?(int)$dk['cut']:0]);
    $id=(int)db()->lastInsertId(); $ref='KK'.$s['code'].'-'.$id;
    $back=pay_return_url($s,$id); $cb=pay_callback_url($g);
    $desc='Langganan Pro 1 tahun '.platform_name().' '.$s['code'];
    try {
        switch($g){
            case 'toyyibpay':
                [$c,$j,$raw]=gw_http('POST',tp_base().'/index.php/api/createBill',['form'=>[
                    'userSecretKey'=>gw_secret('tp_secret'),'categoryCode'=>pset('tp_category'),'billName'=>tp_text('UrusTugas '.$s['code'],30),'billDescription'=>tp_text($desc,100),
                    'billPriceSetting'=>1,'billPayorInfo'=>1,'billAmount'=>$sen,'billReturnUrl'=>$back,'billCallbackUrl'=>$cb,'billExternalReferenceNo'=>$ref,
                    'billTo'=>mb_substr($payor['name'],0,100),'billEmail'=>$payor['email'],'billPhone'=>$payor['phone'],'billPaymentChannel'=>0,'billChargeToCustomer'=>1,'billExpiryDays'=>3]]);
                $code=(string)($j[0]['BillCode']??'');
                if($c>=300 || !preg_match('/^[A-Za-z0-9]{3,40}$/',$code)) throw new RuntimeException('ToyyibPay: '.gw_error($j,$raw,$c));
                pay_set_ext($id,$code,$ref); return ['url'=>tp_base().'/'.$code];
            case 'billplz':
                [$c,$j,$raw]=gw_http('POST',bp_base().'/api/v3/bills',['basic'=>gw_secret('bp_key').':','form'=>[
                    'collection_id'=>pset('bp_collection'),'email'=>$payor['email'],'mobile'=>$payor['phone'],'name'=>mb_substr($payor['name'],0,255),'amount'=>$sen,
                    'callback_url'=>$cb,'redirect_url'=>$back,'description'=>mb_substr($desc,0,200),'reference_1_label'=>'Rujukan','reference_1'=>$ref]]);
                if($c>=300 || empty($j['id']) || empty($j['url'])) throw new RuntimeException('Billplz: '.gw_error($j,$raw,$c));
                pay_set_ext($id,(string)$j['id'],$ref); return ['url'=>(string)$j['url']];
            case 'chip':
                [$c,$j,$raw]=gw_http('POST',chip_base().'/api/v1/purchases/',['headers'=>['Authorization: Bearer '.gw_secret('chip_key')],'json'=>[
                    'brand_id'=>pset('chip_brand'),'reference'=>$ref,'client'=>['email'=>$payor['email'],'full_name'=>mb_substr($payor['name'],0,128),'phone'=>$payor['phone']],
                    'purchase'=>['currency'=>'MYR','products'=>[['name'=>mb_substr($desc,0,256),'price'=>$sen,'quantity'=>1]]],
                    'success_redirect'=>$back,'failure_redirect'=>$back,'cancel_redirect'=>$back,'success_callback'=>$cb]]);
                if($c>=300 || empty($j['id']) || empty($j['checkout_url'])) throw new RuntimeException('CHIP: '.gw_error($j,$raw,$c));
                pay_set_ext($id,(string)$j['id'],$ref); return ['url'=>(string)$j['checkout_url']];
            case 'securepay':
                $f=['buyer_email'=>$payor['email'],'buyer_name'=>mb_substr($payor['name'],0,100),'buyer_phone'=>$payor['phone'],'callback_url'=>$cb,'order_number'=>$ref,
                    'product_description'=>$desc,'redirect_url'=>$back,'transaction_amount'=>number_format($sen/100,2,'.',''),'uid'=>pset('sp_uid')];
                $f['checksum']=hash_hmac('sha256',implode('|',array_values($f)),gw_secret('sp_checksum')); // medan disusun ikut abjad, seperti dokumentasi SecurePay
                $f['token']=gw_secret('sp_token');
                pay_set_ext($id,$ref,$ref); return ['post'=>sp_base().'/api/v1/payments','fields'=>$f];
        }
    } catch(Throwable $ex) {
        query('UPDATE payments SET status="gagal",note=? WHERE id=?',[mb_substr($ex->getMessage(),0,300),$id]);
        throw new RuntimeException('Tidak dapat memulakan bayaran: '.$ex->getMessage());
    }
    throw new RuntimeException('Gerbang tidak dikenali.');
}
function pay_set_ext(int $id,string $ext,string $ref): void { query('UPDATE payments SET ext_id=?,ref=? WHERE id=?',[mb_substr($ext,0,60),$ref,$id]); }

/* ---------- Semak status melalui API gerbang ---------- */
/** true = dibayar, false = belum, null = tiada API semakan (SecurePay — tunggu callback bertandatangan). */
function gw_check(array $p): ?bool {
    $sen=(int)$p['amount_sen']; $ext=(string)$p['ext_id']; $sb=(int)$p['sandbox']===1; if($ext==='') return false;
    switch($p['method']){
        case 'toyyibpay':
            [$c,$j]=gw_http('POST',tp_base($sb).'/index.php/api/getBillTransactions',['form'=>['billCode'=>$ext,'billpaymentStatus'=>1]]);
            foreach(($c===200 && is_array($j))?$j:[] as $t){ $amt=(int)round((float)str_replace(',','',(string)($t['billpaymentAmount']??'0'))*100); if((string)($t['billpaymentStatus']??'')==='1' && $amt>=$sen) return true; }
            return false;
        case 'billplz':
            [$c,$j]=gw_http('GET',bp_base($sb).'/api/v3/bills/'.rawurlencode($ext),['basic'=>gw_secret('bp_key').':']);
            return $c===200 && ($j['paid']??false)===true && (int)($j['paid_amount']??$j['amount']??0)>=$sen;
        case 'chip':
            [$c,$j]=gw_http('GET',chip_base().'/api/v1/purchases/'.rawurlencode($ext).'/',['headers'=>['Authorization: Bearer '.gw_secret('chip_key')]]);
            return $c===200 && ($j['status']??'')==='paid' && (int)($j['purchase']['total']??0)>=$sen;
        case 'securepay': return null;
    }
    return false;
}

/* ---------- Lanjutan langganan ---------- */
/** Lanjutkan Pro $years tahun dari tarikh tamat semasa (atau hari ini). Mesti dipanggil dalam transaksi (baris sekolah dikunci). */
function pay_extend(int $schoolId,int $years): array {
    $cur=(string)query('SELECT expires_on FROM schools WHERE id=? FOR UPDATE',[$schoolId])->fetchColumn();
    $today=date('Y-m-d'); $base=$cur!=='' && $cur>=$today?$cur:$today;
    $new=date('Y-m-d',strtotime($base.' +'.max(0,$years).' year'));
    query('UPDATE schools SET expires_on=?,notices=NULL WHERE id=?',[$new,$schoolId]); // notis amaran tamat bermula semula bagi tarikh baharu
    return [$cur?:null,$new];
}
function pay_receipt_no(int $id): string { return 'KK'.date('Y').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT); }

/**
 * Tandakan bayaran berjaya & lanjutkan langganan — IDEMPOTEN (callback & halaman kembali boleh serentak).
 * $check: null = semak API gerbang; callable = pengesahan lain (tandatangan sah / kelulusan platform).
 * $by ≠ '' = kelulusan pentadbir platform (boleh meluluskan 'semak'). Pulangkan tarikh tamat baharu, atau null.
 */
function pay_confirm(int $id,?callable $check=null,string $by=''): ?string {
    $p=payment_get($id); if(!$p || !$p['school_id']) return null;
    $exp=fn()=>(string)query('SELECT expires_on FROM schools WHERE id=?',[$p['school_id']])->fetchColumn();
    if($p['status']==='berjaya') return $exp();
    if(in_array($p['status'],['ditolak','dibatal'],true)) return null;
    if($p['status']==='semak' && $by==='') return null; // ditahan — hanya pentadbir platform boleh meluluskan
    $ok=$check?(bool)$check($p):gw_check($p);
    if(!$ok) return null;
    // Mod ujian bukan wang sebenar: tahan untuk semakan, jangan lanjutkan secara automatik.
    if($by==='' && isset(PAY_GATEWAYS[$p['method']]) && (int)$p['sandbox']===1){
        if(query('UPDATE payments SET status="semak",note=? WHERE id=? AND status IN ("menunggu","gagal")',['Dibayar dalam MOD UJIAN (sandbox) — bukan wang sebenar. Luluskan hanya untuk tujuan ujian.',$id])->rowCount()===1)
            platform_log('payment_held',(int)$p['school_id'],$p['ref'].' mod ujian '.pay_method_name($p['method']));
        return null;
    }
    $pdo=db(); $pdo->beginTransaction(); $new=null;
    try {
        query('SELECT id FROM schools WHERE id=? FOR UPDATE',[$p['school_id']]); // pengesahan serentak sekolah yang sama berjalan satu demi satu
        // Kod diskaun sekali guna: bayaran lain yang berjaya dengan kod yang sama → tahan untuk keputusan platform.
        if($by==='' && !empty($p['discount_code']) && (int)query('SELECT COUNT(*) FROM payments WHERE school_id=? AND discount_code=? AND status="berjaya" AND id<>?',[$p['school_id'],$p['discount_code'],$id])->fetchColumn()>0){
            if(query('UPDATE payments SET status="semak",note=? WHERE id=? AND status IN ("menunggu","gagal")',['Dibayar, tetapi kod diskaun '.$p['discount_code'].' sudah digunakan sekolah ini — perlu semakan.',$id])->rowCount()===1)
                platform_log('payment_held',(int)$p['school_id'],$p['ref'].' kod diskaun berulang');
            $pdo->commit(); return null;
        }
        // Peralihan atomik: hanya menunggu/gagal (bil luput tetapi dibayar lewat) — atau semak oleh platform — menjadi berjaya.
        $u=query('UPDATE payments SET status="berjaya",paid_at=NOW(),receipt_no=?'.($by!==''?',by_name=?':'').' WHERE id=? AND status IN ("menunggu","gagal"'.($by!==''?',"semak"':'').')',
            $by!==''?[pay_receipt_no($id),mb_substr($by,0,120),$id]:[pay_receipt_no($id),$id]);
        if($u->rowCount()===1){
            [$from,$new]=pay_extend((int)$p['school_id'],(int)$p['years']);
            query('UPDATE payments SET expires_from=?,expires_to=? WHERE id=?',[$from,$new,$id]);
        }
        $pdo->commit();
    } catch(Throwable $ex){ $pdo->rollBack(); throw $ex; }
    if($new){
        platform_log('payment_ok',(int)$p['school_id'],pay_method_name($p['method']).' '.ringgit((int)$p['amount_sen']).' ('.($p['ref']?:'#'.$id).') → '.$new);
        pay_notify_success(payment_get($id));
    }
    return $exp();
}
/** E-mel resit kepada pegawai, pentadbir sekolah & pembayar. Kegagalan e-mel tidak menjejaskan bayaran. */
function pay_notify_success(array $p): void {
    $s=school_by_id((int)$p['school_id']); if(!$s) return;
    $txt="Terima kasih. Bayaran langganan Pro ".platform_name()." untuk {$s['name']} ({$s['code']}) telah diterima.\n\nNo. resit: {$p['receipt_no']}\nKaedah: ".pay_method_name($p['method'])."\nJumlah: ".ringgit((int)$p['amount_sen'])
        .($p['discount_code']?" (kod {$p['discount_code']}, potongan ".ringgit((int)$p['discount_sen']).')':'')."\nPro sah hingga: ".date('d/m/Y',strtotime((string)$p['expires_to']))
        ."\n\nResit boleh dicetak di halaman Langganan:\n".school_url($s['code'],'/?page=langganan');
    try {
        if(filter_var($p['payer_email'],FILTER_VALIDATE_EMAIL) && !isset(school_contacts($s)[strtolower($p['payer_email'])])){ require_once __DIR__.'/settings.php'; require_once __DIR__.'/mailer.php'; $e=null; with_school($s,fn()=>send_mail($p['payer_email'],$p['payer_name'],'Resit bayaran '.$p['receipt_no'],$txt,$e)); }
        school_mail($s,'Resit bayaran '.$p['receipt_no'],$txt);
    } catch(Throwable) {}
}

/* ---------- Tandatangan callback (lapis 1; status tetap disahkan semula melalui API) ---------- */
/** ToyyibPay: hash = md5(userSecretKey + status + order_id + refno + 'ok'). WAJIB ada. */
function tp_sig_ok(array $post): bool {
    $k=gw_secret('tp_secret'); $h=$post['hash']??'';
    if($k==='' || !is_string($h) || $h==='') return false;
    foreach(['status','order_id','refno'] as $f) if(isset($post[$f]) && !is_string($post[$f])) return false;
    return hash_equals(md5($k.($post['status']??'').($post['order_id']??'').($post['refno']??'').'ok'),strtolower($h));
}
/** Billplz X-Signature: "kunci+nilai" disusun menaik (tanpa beza huruf), dicantum '|', HMAC-SHA256. WAJIB ada. */
function bp_sig_ok(array $post): bool {
    $k=gw_secret('bp_xsig'); $sig=$post['x_signature']??'';
    if($k==='' || !is_string($sig) || $sig==='') return false;
    unset($post['x_signature']); $parts=[];
    foreach($post as $f=>$v){ if(!is_string($v)) return false; $parts[]=$f.$v; }
    usort($parts,'strcasecmp');
    return hash_equals(hash_hmac('sha256',implode('|',$parts),$k),strtolower($sig));
}
/** Kunci awam CHIP (PEM): disimpan; dimuat turun semula paling kerap sejam sekali (seminit jika belum ada). */
function chip_pubkey(bool $fresh=false): ?string {
    $k=pset('chip_pubkey'); if($k!=='' && !$fresh) return $k;
    if((int)pset('chip_pubkey_at','0')>time()-($k!==''?3600:60)) return $k!==''?$k:null;
    pset_set(['chip_pubkey_at'=>(string)time()]);
    try { [$c,,$raw]=gw_http('GET',chip_base().'/api/v1/public_key/',['headers'=>['Authorization: Bearer '.gw_secret('chip_key')]]); } catch(Throwable) { return $k!==''?$k:null; }
    $pem=json_decode($raw,true); if(!is_string($pem)) $pem=trim($raw);
    if($c!==200 || !str_contains($pem,'BEGIN PUBLIC KEY') || !openssl_pkey_get_public($pem)) return $k!==''?$k:null;
    pset_set(['chip_pubkey'=>$pem]); return $pem;
}
/** CHIP: X-Signature = base64(RSA-SHA256(badan mentah)). WAJIB ada. */
function chip_sig_ok(string $raw,string $sigB64): bool {
    $sig=base64_decode($sigB64,true); if($raw==='' || $sig===false || $sig==='') return false;
    foreach([false,true] as $fresh){ $pem=chip_pubkey($fresh); if($pem===null) return false; if(openssl_verify($raw,$sig,$pem,OPENSSL_ALGO_SHA256)===1) return true; }
    return false;
}
/** SecurePay: HMAC-SHA256 semua medan (kecuali checksum) disusun ikut kunci, dipisah '|'. */
function sp_sig_ok(array $post): bool {
    $cs=$post['checksum']??''; $k=gw_secret('sp_checksum'); if(!is_string($cs) || $cs==='' || $k==='') return false;
    unset($post['checksum']); foreach($post as $v) if(!is_string($v)) return false; ksort($post);
    return hash_equals(hash_hmac('sha256',implode('|',$post),$k),strtolower($cs));
}

/** POST /bayar-callback?g=… (pelayan-ke-pelayan, tanpa sesi & CSRF). */
function pay_callback(): never {
    while(ob_get_level()>0) @ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    $out=function(int $code,string $msg): never { http_response_code($code); echo $msg; exit; };
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST') $out(405,'POST sahaja');
    $g=(string)($_GET['g']??''); if(!isset(PAY_GATEWAYS[$g])) $out(400,'gerbang tidak sah');
    $find=function(string $ext) use($g): ?array {
        if(!preg_match('/^[A-Za-z0-9_-]{3,60}$/',$ext)) return null;
        return query('SELECT * FROM payments WHERE method=? AND (ext_id=? OR ref=?) ORDER BY id DESC LIMIT 1',[$g,$ext,$ext])->fetch()?:null;
    };
    try {
        $p=null;
        switch($g){
            case 'toyyibpay':
                if(!tp_sig_ok($_POST)) $out(403,'hash tidak sah');
                $p=$find((string)($_POST['billcode']??''));
                // Hash meliputi order_id (= rujukan kita), bukan billcode — kedua-duanya mesti merujuk bil yang sama.
                if($p && !hash_equals((string)$p['ref'],(string)($_POST['order_id']??''))) $out(403,'rujukan tidak sepadan');
                break;
            case 'billplz':
                if(!bp_sig_ok($_POST)) $out(403,'x_signature tidak sah');
                $p=$find((string)($_POST['id']??'')); break;
            case 'chip':
                $raw=(string)file_get_contents('php://input');
                if(!chip_sig_ok($raw,(string)($_SERVER['HTTP_X_SIGNATURE']??''))) $out(403,'x-signature tidak sah');
                $j=json_decode($raw,true); $p=$find(is_array($j)?(string)($j['id']??''):''); break;
            case 'securepay':
                // Tiada API semakan status — sah HANYA jika tandatangan HMAC sepadan.
                if(!sp_sig_ok($_POST)) $out(403,'checksum tidak sah');
                $p=$find((string)($_POST['order_number']??''));
                if($p){
                    $paid=in_array(strtolower((string)($_POST['payment_status']??'')),['true','1','success','paid'],true)
                        && (int)round((float)($_POST['transaction_amount']??$_POST['amount']??0)*100)>=(int)$p['amount_sen'];
                    $out(200,pay_confirm((int)$p['id'],fn()=>$paid)?'OK':'BELUM');
                }
                break;
        }
        if(!$p) $out(404,'bayaran tidak ditemui');
        $out(200,pay_confirm((int)$p['id'])?'OK':'BELUM');
    } catch(Throwable $ex){ error_log('bayar-callback '.$g.': '.$ex->getMessage()); $out(500,'RALAT'); }
}

/* ---------- Bukti bayaran manual (di luar public_html) ---------- */
function proof_dir(): string {
    $d=dirname(__DIR__).'/storage/bukti';
    if(!is_dir($d)){ @mkdir($d,0750,true); @file_put_contents($d.'/.htaccess',"Require all denied\n"); }
    return $d;
}
const PROOF_TYPES=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
/** Simpan fail bukti (JPG/PNG/WEBP/PDF ≤ 5 MB) dengan nama rawak. Pulangkan nama fail. */
function proof_save(array $f): string {
    if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name']??''))) throw new DomainException('Sila pilih fail bukti bayaran.');
    if((int)$f['size']>5*1048576) throw new DomainException('Fail bukti melebihi 5 MB.');
    $ext=PROOF_TYPES[(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])]??null;
    if(!$ext) throw new DomainException('Format bukti tidak disokong. Guna JPG, PNG, WEBP atau PDF.');
    $name=bin2hex(random_bytes(16)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'],proof_dir().'/'.$name)) throw new RuntimeException('Gagal menyimpan fail bukti.');
    return $name;
}
function proof_serve(array $p): never {
    $f=proof_dir().'/'.basename((string)$p['proof']);
    if(!$p['proof'] || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/',(string)$p['proof']) || !is_file($f)) error_page(404,'Fail bukti tidak dijumpai.');
    while(ob_get_level()>0) @ob_end_clean();
    $mime=array_search(pathinfo($f,PATHINFO_EXTENSION),PROOF_TYPES,true)?:'application/octet-stream';
    $pdf=$mime==='application/pdf'; // PDF dimuat turun (pemapar PDF tidak berfungsi dalam CSP sandbox); imej dipapar terus
    header('Content-Type: '.$mime); header('Content-Length: '.(string)filesize($f)); header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Content-Disposition: '.($pdf?'attachment':'inline').'; filename="bukti-'.$p['id'].'.'.pathinfo($f,PATHINFO_EXTENSION).'"'); readfile($f); exit;
}

/* ---------- Bayaran manual oleh platform (tunai / PO / pemindahan yang disahkan di luar sistem) ---------- */
function pay_record_manual(array $s,string $method,int $sen,int $years,string $ref,string $by): array {
    if(!in_array($method,['tunai','bank','qr','manual'],true)) throw new DomainException('Kaedah tidak sah.');
    if($sen<0 || $sen>10000000 || $years<1 || $years>5) throw new DomainException('Jumlah atau tempoh tidak sah.');
    $pdo=db(); $pdo->beginTransaction();
    try {
        query('INSERT INTO payments(school_id,school_code,method,amount_sen,years,status,ref,by_name,payer_name,paid_at) VALUES (?,?,?,?,?,"berjaya",?,?,?,NOW())',[$s['id'],$s['code'],$method,$sen,$years,mb_substr($ref,0,80),mb_substr($by,0,120),mb_substr((string)$s['pic_name'],0,120)]);
        $id=(int)$pdo->lastInsertId(); [$from,$new]=pay_extend((int)$s['id'],$years);
        query('UPDATE payments SET receipt_no=?,expires_from=?,expires_to=? WHERE id=?',[pay_receipt_no($id),$from,$new,$id]);
        $pdo->commit();
    } catch(Throwable $ex){ $pdo->rollBack(); throw $ex; }
    $p=payment_get($id); pay_notify_success($p); return $p;
}

/* ---------- Kod diskaun ---------- */
function discount_normal(string $c): string { return strtoupper((string)preg_replace('/\s+/','',$c)); }
function discount_label(array $d): string { return $d['kind']==='peratus'?(int)$d['value'].'%':ringgit((int)$d['value']); }
function discount_cut(array $d,int $price): int {
    $c=$d['kind']==='peratus'?(int)round($price*min(100,max(0,(int)$d['value']))/100):(int)$d['value'];
    return max(0,min($c,$price-DISCOUNT_MIN_SEN));
}
function discount_uses(string $code): int { return (int)query('SELECT COUNT(*) FROM payments WHERE discount_code=? AND (status IN ("berjaya","semak") OR (status="menunggu" AND method IN ("bank","qr")))',[$code])->fetchColumn(); }
/** ['ok','error','code','price','cut','final','d']. Sekali bagi setiap sekolah; had keseluruhan (max_uses) jika ditetapkan. */
function discount_check(string $code,int $schoolId,?int $price=null): array {
    $price??=pay_price(); $code=discount_normal($code);
    $fail=fn(string $m)=>['ok'=>false,'error'=>$m,'code'=>$code,'price'=>$price,'cut'=>0,'final'=>$price,'d'=>null];
    if(!preg_match('/^[A-Z0-9_-]{3,30}$/',$code)) return $fail('Kod diskaun tidak sah.');
    $d=query('SELECT * FROM discounts WHERE code=?',[$code])->fetch();
    if(!$d || !(int)$d['active']) return $fail('Kod diskaun tidak sah atau tidak lagi aktif.');
    if($d['ends_on'] && $d['ends_on']<date('Y-m-d')) return $fail('Kod diskaun ini telah tamat tempoh ('.date('d/m/Y',strtotime($d['ends_on'])).').');
    if((int)query('SELECT COUNT(*) FROM payments WHERE school_id=? AND discount_code=? AND (status IN ("berjaya","semak") OR (status="menunggu" AND method IN ("bank","qr")))',[$schoolId,$code])->fetchColumn()>0) return $fail('Kod diskaun ini telah digunakan oleh sekolah anda.');
    if($d['max_uses']!==null && discount_uses($code)>=(int)$d['max_uses']) return $fail('Kod diskaun ini telah mencapai had penggunaan.');
    $cut=discount_cut($d,$price); if($cut<=0) return $fail('Kod diskaun ini tidak memberi potongan pada harga semasa.');
    return ['ok'=>true,'error'=>null,'code'=>$code,'price'=>$price,'cut'=>$cut,'final'=>$price-$cut,'d'=>$d];
}
/** Kod yang sedang dipakai sekolah dalam sesi ini (disemak semula setiap kali). */
function discount_session(): ?array {
    $c=(string)($_SESSION['diskaun']??''); if($c==='') return null;
    $r=discount_check($c,sid()); if(!$r['ok']){ unset($_SESSION['diskaun']); return null; }
    return $r;
}

/* ---------- Tajaan ---------- */
/** Tajaan aktif yang meliputi tarikh tamat semasa sekolah (atau null). */
function sponsorship_current(int $schoolId): ?array {
    return query('SELECT t.*,p.name sponsor FROM sponsorships t JOIN sponsors p ON p.id=t.sponsor_id JOIN schools s ON s.id=t.school_id WHERE t.school_id=? AND t.status="aktif" AND t.end_on>=CURDATE() AND t.end_on=s.expires_on ORDER BY t.end_on DESC LIMIT 1',[$schoolId])->fetch()?:null;
}
/**
 * Tarikh tamat baharu: tahun = hingga 31 Dis tahun $v · lanjut = $v tahun dari tarikh tamat semasa (atau hari ini) · tarikh = hingga $v.
 * Maks 5 tahun ke hadapan; tidak memendekkan tarikh sedia ada. Pulangkan [mula, tamat] atau null.
 */
function sponsorship_calc(?string $cur,string $mode,string $v): ?array {
    $today=date('Y-m-d'); $base=$cur && $cur>=$today?$cur:null; $max=date('Y-m-d',strtotime('+5 year'));
    $target=match($mode){
        'tahun'=>((int)$v>=(int)date('Y') && (int)$v<=(int)date('Y')+5)?((int)$v).'-12-31':null,
        'lanjut'=>((int)$v>=1 && (int)$v<=5)?date('Y-m-d',strtotime(($base??$today).' +'.(int)$v.' year')):null,
        'tarikh'=>(($dt=DateTimeImmutable::createFromFormat('!Y-m-d',$v)) && $dt->format('Y-m-d')===$v)?$v:null,
        default=>null,
    };
    if($target===null || $target<$today || $target>date('Y-m-d',strtotime($max.' +1 year')) || ($cur && $target<=$cur)) return null;
    if($mode==='tarikh' && $target>$max) return null;
    $start=$base?date('Y-m-d',strtotime($base.' +1 day')):$today;
    return [min($start,$target),$target];
}
/** Taja satu sekolah aktif. Direkod juga dalam payments (kaedah tajaan, RM0) supaya sejarah sekolah lengkap. */
function sponsorship_give(int $sponsorId,array $s,string $mode,string $v,int $valueSen,string $ref,string $by,bool $mail=true): array {
    $sp=query('SELECT * FROM sponsors WHERE id=?',[$sponsorId])->fetch(); if(!$sp) return ['ok'=>false,'why'=>'Penaja tidak dijumpai'];
    if($s['status']!=='aktif') return ['ok'=>false,'why'=>'Status sekolah: '.$s['status']];
    $k=sponsorship_calc($s['expires_on']?:null,$mode,$v);
    if(!$k) return ['ok'=>false,'why'=>'Tempoh tidak sah (maks 5 tahun ke hadapan) atau tarikh tamat semasa ('.($s['expires_on']?date('d/m/Y',strtotime($s['expires_on'])):'—').') sudah meliputinya'];
    [$start,$end]=$k; $pdo=db(); $pdo->beginTransaction();
    try {
        $cur=(string)query('SELECT expires_on FROM schools WHERE id=? FOR UPDATE',[$s['id']])->fetchColumn();
        query('INSERT INTO payments(school_id,school_code,method,amount_sen,years,status,ref,note,by_name,payer_name,paid_at,expires_from,expires_to) VALUES (?,?,"tajaan",0,0,"berjaya",?,?,?,?,NOW(),?,?)',
            [$s['id'],$s['code'],mb_substr('Tajaan: '.$sp['name'].($ref!==''?' · '.$ref:''),0,80),'Nilai tajaan '.ringgit($valueSen).' · '.$start.' hingga '.$end,mb_substr($by,0,120),mb_substr($sp['name'],0,120),$cur?:null,$end]);
        $pid=(int)$pdo->lastInsertId(); query('UPDATE payments SET receipt_no=? WHERE id=?',[pay_receipt_no($pid),$pid]);
        query('INSERT INTO sponsorships(sponsor_id,school_id,start_on,end_on,prev_expires,value_sen,ref,payment_id,by_name) VALUES (?,?,?,?,?,?,?,?,?)',[$sponsorId,$s['id'],$start,$end,$cur?:null,max(0,$valueSen),mb_substr($ref,0,100),$pid,mb_substr($by,0,120)]);
        query('UPDATE schools SET expires_on=?,notices=NULL WHERE id=?',[$end,$s['id']]);
        $pdo->commit();
    } catch(Throwable $ex){ $pdo->rollBack(); throw $ex; }
    platform_log('sponsorship_given',(int)$s['id'],$sp['name'].' hingga '.$end);
    if($mail) try { school_mail($s,'Langganan Pro ditaja',"Berita baik! Langganan Pro ".platform_name()." untuk {$s['name']} ({$s['code']}) DITAJA oleh {$sp['name']}.\n\nTempoh: ".date('d/m/Y',strtotime($start)).' hingga '.date('d/m/Y',strtotime($end))."\nTiada bayaran diperlukan daripada pihak sekolah.\n\n".school_url($s['code'],'/?page=langganan')); } catch(Throwable) {}
    return ['ok'=>true,'start'=>$start,'end'=>$end];
}
/** Batalkan tajaan; pulihkan tarikh tamat lama jika tarikh tamat sekolah belum berubah selepas itu. */
function sponsorship_cancel(int $id,string $by): bool {
    $t=query('SELECT t.*,p.name sponsor FROM sponsorships t JOIN sponsors p ON p.id=t.sponsor_id WHERE t.id=? AND t.status="aktif"',[$id])->fetch(); if(!$t) return false;
    $pdo=db(); $pdo->beginTransaction();
    try {
        query('UPDATE sponsorships SET status="batal" WHERE id=?',[$id]);
        if($t['payment_id']) query('UPDATE payments SET status="dibatal",note=? WHERE id=? AND method="tajaan"',['Tajaan dibatalkan oleh '.mb_substr($by,0,100),$t['payment_id']]);
        $n=query('UPDATE schools SET expires_on=? WHERE id=? AND expires_on=?',[$t['prev_expires'],$t['school_id'],$t['end_on']])->rowCount();
        $pdo->commit();
    } catch(Throwable $ex){ $pdo->rollBack(); throw $ex; }
    platform_log('sponsorship_cancelled',(int)$t['school_id'],$t['sponsor'].' (hingga '.$t['end_on'].')'.($n?' — tarikh tamat dipulihkan ke '.($t['prev_expires']?:'tiada'):''));
    return true;
}

/* ---------- Kerja harian (dipanggil oleh subscription_daily) ---------- */
function payments_daily(): array {
    // Bil gerbang yang tidak dibayar dalam 3 hari → gagal (bayaran lewat masih boleh disahkan melalui callback).
    $n=query('UPDATE payments SET status="gagal",note="Luput — tidak dibayar dalam 3 hari" WHERE status="menunggu" AND method IN ("toyyibpay","billplz","chip","securepay") AND created_at<DATE_SUB(NOW(),INTERVAL 3 DAY)')->rowCount();
    // Peringatan kepada penaja 30 hari sebelum tajaan tamat (sekali bagi setiap tajaan).
    $rows=query('SELECT t.id,t.end_on,t.sponsor_id,p.name sponsor,p.email,s.code,s.name school FROM sponsorships t JOIN sponsors p ON p.id=t.sponsor_id JOIN schools s ON s.id=t.school_id
        WHERE t.status="aktif" AND t.reminded IS NULL AND t.end_on=s.expires_on AND t.end_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) ORDER BY p.id,s.code')->fetchAll();
    $group=[]; foreach($rows as $r) $group[$r['sponsor_id']][]=$r;
    $sent=0;
    foreach($group as $list){
        $p=$list[0]; $txt=implode("\n",array_map(fn($r)=>"  - {$r['code']} {$r['school']} — tamat ".date('d/m/Y',strtotime($r['end_on'])),$list));
        require_once __DIR__.'/settings.php'; require_once __DIR__.'/mailer.php';
        foreach(array_filter([filter_var($p['email'],FILTER_VALIDATE_EMAIL)?$p['email']:'',pset('emel_notis')]) as $to){ $e=null;
            if(send_mail($to,$p['sponsor'],'Tajaan '.platform_name().' akan tamat — '.count($list).' sekolah',"Tajaan {$p['sponsor']} untuk sekolah berikut akan tamat dalam 30 hari:\n\n$txt\n\nHubungi pentadbir platform untuk menyambung tajaan.",$e)) $sent++; }
        query('UPDATE sponsorships SET reminded=CURDATE() WHERE id IN ('.implode(',',array_map(fn($r)=>(int)$r['id'],$list)).')');
    }
    return ['luput'=>$n,'peringatan_tajaan'=>$sent];
}
