<?php
declare(strict_types=1);
/* API v1 UrusTugas (JSON, tanpa sesi/kuki) — /KOD/api/v1/… atau kod.domain/api/v1/…
 *  - Pengesahan: Authorization: Bearer ut_<64 hex>. Kunci dicari melalui hash (unik global) dan MESTI milik sekolah dalam URL;
 *    kunci sekolah lain ditolak seperti kunci tidak sah. Skop read = GET sahaja.
 *  - Semua tulisan hanya ketika Draf; API tidak boleh meluluskan / memuktamadkan. Kod ralat stabil (kontrak klien):
 *    unauthorized, insufficient_scope, not_found, validation_error, workflow_locked, stale_or_used_plan, duplicate_external_id,
 *    record_in_use, position_assigned, not_finalized, method_not_allowed, unsupported_media_type, payload_too_large, invalid_json. */

final class ApiError extends RuntimeException { public function __construct(public int $status,public string $errorCode,string $message){ parent::__construct($message); } }
function api_fail(int $status,string $code,string $message): never { throw new ApiError($status,$code,$message); }
function api_respond(mixed $data,int $status=200,?array $meta=null): never {
    http_response_code($status); $r=['data'=>$data]; if($meta!==null) $r['meta']=$meta; $r['request_id']=$GLOBALS['API_REQUEST_ID'];
    echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit;
}
function api_int(mixed $v,string $field,int $min=1,int $max=PHP_INT_MAX): int { if(!is_int($v) || $v<$min || $v>$max) api_fail(422,'validation_error',"$field mesti integer antara $min dan $max."); return $v; }
function api_str(mixed $v,string $field,int $max=160,bool $required=true): string { if(!is_string($v) || ($required && trim($v)==='') || mb_strlen(trim($v))>$max) api_fail(422,'validation_error',"$field mesti teks yang sah (maksimum $max aksara)."); return trim($v); }
function api_body(array $fields): array {
    $type=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
    if($type!=='application/json') api_fail(415,'unsupported_media_type','Gunakan Content-Type: application/json.');
    $raw=(string)file_get_contents('php://input',false,null,0,65537);
    if(strlen($raw)>65536) api_fail(413,'payload_too_large','Had saiz permintaan ialah 64 KB.');
    try { $o=json_decode($raw,false,32,JSON_THROW_ON_ERROR); } catch(JsonException) { api_fail(400,'invalid_json','JSON tidak sah.'); }
    if(!$o instanceof stdClass) api_fail(400,'invalid_json','Badan JSON mesti objek.');
    $d=(array)$o; if(array_diff(array_keys($d),$fields)) api_fail(422,'validation_error','Permintaan mengandungi medan yang tidak disokong.');
    if(!$d) api_fail(422,'validation_error','Sertakan sekurang-kurangnya satu medan.');
    return $d;
}
function api_method(string $m,array $allowed): void { if(!in_array($m,$allowed,true)){ header('Allow: '.implode(', ',$allowed)); api_fail(405,'method_not_allowed','Kaedah HTTP tidak disokong untuk endpoint ini.'); } }
function api_audit(string $action,string $entity,?int $id,string $detail=''): void {
    $k=$GLOBALS['API_KEY']; audit($action,$entity,$id,mb_substr('API #'.$k['id'].' ('.$k['name'].')'.($detail!==''?': '.$detail:''),0,255));
}
function api_teacher(int $id): array {
    $t=teachers_with_load($id)[0]??null; if(!$t) api_fail(404,'not_found','Rekod tidak ditemui.');
    foreach(['id','capacity','load','task_count'] as $k) $t[$k]=(int)$t[$k]; unset($t['school_id']); $t['over_capacity']=$t['load']>$t['capacity']; return $t;
}
function api_position(int $id): array {
    $p=positions_with_holder($id)[0]??null; if(!$p) api_fail(404,'not_found','Rekod tidak ditemui.');
    unset($p['school_id'],$p['teacher_name']); foreach(['id','weight','assignment_id','teacher_id'] as $k) $p[$k]=$p[$k]!==null?(int)$p[$k]:null; return $p;
}
function api_assignment(int $id): array {
    $a=query('SELECT a.id,a.teacher_id,a.position_id,a.created_at,t.name AS teacher_name,p.name AS position_name,p.category,p.weight FROM assignments a JOIN teachers t ON t.id=a.teacher_id AND t.school_id=a.school_id JOIN positions p ON p.id=a.position_id AND p.school_id=a.school_id WHERE a.id=? AND a.school_id='.sid(),[$id])->fetch();
    if(!$a) api_fail(404,'not_found','Rekod tidak ditemui.'); foreach(['id','teacher_id','position_id','weight'] as $k) $a[$k]=(int)$a[$k]; return $a;
}
function api_pagination(): array {
    $v=[]; foreach(['page'=>1,'per_page'=>50] as $k=>$d){ $x=$_GET[$k]??(string)$d; if(!is_string($x) || !ctype_digit($x) || (int)$x<1 || (int)$x>($k==='per_page'?100:1000000)) api_fail(422,'validation_error','page mesti 1–1000000 dan per_page mesti 1–100.'); $v[]=(int)$x; }
    return $v;
}
/** Medan guru daripada badan JSON (gabung dengan nilai sedia ada untuk PATCH). */
function api_teacher_fields(array $body,array $prev=[]): array {
    $d=array_merge(['email'=>'','specialty'=>''],$prev,$body);
    $out=['name'=>api_str($d['name']??null,'name'),'email'=>api_str($d['email'],'email',190,false),'specialty'=>api_str($d['specialty'],'specialty',160,false),'capacity'=>api_int($d['capacity']??null,'capacity',1,100)];
    if(array_key_exists('external_id',$body)) $out['external_id']=$body['external_id']===null?null:api_str($body['external_id'],'external_id',160);
    return $out;
}

function api_main(): never {
    $GLOBALS['API_REQUEST_ID']=bin2hex(random_bytes(8));
    while(ob_get_level()>0) @ob_end_clean();
    header_remove('Content-Security-Policy'); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Request-ID: '.$GLOBALS['API_REQUEST_ID']);
    try {
        $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
        $origin=(string)($_SERVER['HTTP_ORIGIN']??''); $origins=array_filter(array_map('trim',explode(',',env('API_CORS_ORIGINS'))));
        if($origin!=='' && in_array($origin,$origins,true)){ header('Access-Control-Allow-Origin: '.$origin); header('Vary: Origin'); header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS'); header('Access-Control-Allow-Headers: Authorization, Content-Type'); header('Access-Control-Expose-Headers: X-Request-ID, Location'); }
        if($method==='OPTIONS'){ if($origin==='' || !in_array($origin,$origins,true)) api_fail(403,'origin_not_allowed','Origin tidak dibenarkan.'); http_response_code(204); exit; }
        if(!school()) api_fail(404,'not_found','Gunakan URL API sekolah: /KOD/api/v1.');
        if($b=ip_blocked(client_ip(),sid(),false)) api_fail(403,'ip_blocked','Alamat IP disekat (rujukan #'.(int)$b['id'].').');
        $em=emergency_mode(); if($em==='tutup' || ($em!=='' && !in_array($method,['GET','HEAD'],true))) api_fail(503,'maintenance','Sistem dalam penyelenggaraan keselamatan. Cuba sebentar lagi.');
        if(school_plan()==='tamat') api_fail(403,'subscription_expired','Langganan sekolah ini telah tamat.');
        $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        $rk=rate_key('api-ip',client_ip()); if(!rate_allowed($rk,120)) api_fail(429,'rate_limited','Terlalu banyak permintaan gagal. Cuba selepas 15 minit.');
        if(!preg_match('/^Bearer (ut_[a-f0-9]{64})$/D',$auth,$m)){ header('WWW-Authenticate: Bearer realm="UrusTugas"'); rate_hit($rk); api_fail(401,'unauthorized','API key diperlukan dalam header Authorization: Bearer.'); }
        $key=query('SELECT * FROM api_keys WHERE token_hash=? AND revoked_at IS NULL',[api_key_hash($m[1])])->fetch();
        if(!$key || (int)$key['school_id']!==sid()){ header('WWW-Authenticate: Bearer realm="UrusTugas"'); rate_hit($rk); api_fail(401,'unauthorized','API key tidak sah atau telah dibatalkan.'); }
        $GLOBALS['API_KEY']=$key;
        if(!feature_on('api')) api_fail(403,'pro_required','API tersedia untuk pakej Pro sahaja. Kunci kekal sah selepas naik taraf.');
        if(!in_array($method,['GET','HEAD'],true) && $key['scope']!=='write') api_fail(403,'insufficient_scope','API key ini hanya membenarkan bacaan.');
        query('UPDATE api_keys SET last_used_at=NOW() WHERE id=? AND school_id='.sid(),[$key['id']]);
        api_route($method,rtrim((string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH),'/'));
    } catch(Throwable $ex){
        if(db()->inTransaction()) db()->rollBack();
        if($ex instanceof EngineError) $ex=new ApiError($ex->status,$ex->apiCode,$ex->getMessage());
        elseif($ex instanceof DomainException) $ex=new ApiError(422,'validation_error',$ex->getMessage()); // cth. had pakej Free
        elseif($ex instanceof PDOException && str_contains($ex->getMessage(),'school_external')) $ex=new ApiError(409,'duplicate_external_id','external_id sudah digunakan. Gunakan POST /teachers/sync untuk menyegerakkan guru.');
        $status=$ex instanceof ApiError?$ex->status:500;
        if($status===500) error_log('UrusTugas API '.$GLOBALS['API_REQUEST_ID'].': '.get_class($ex).': '.$ex->getMessage().' @ '.$ex->getFile().':'.$ex->getLine());
        http_response_code($status);
        echo json_encode(['error'=>['code'=>$ex instanceof ApiError?$ex->errorCode:'internal_error','message'=>$ex instanceof ApiError?$ex->getMessage():'Ralat dalaman. Hubungi pentadbir dengan request_id.'],'request_id'=>$GLOBALS['API_REQUEST_ID']],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

/** Tulisan API: transaksi + mesti Draf. */
function api_write(callable $fn): mixed { db()->beginTransaction(); workflow_require_draft(); $r=$fn(); db()->commit(); return $r; }

function api_route(string $method,string $path): never {
    if(preg_match('#^/api/v1/assets/([a-f0-9]{64})$#D',$path,$am)){ api_method($method,['GET']); $a=query('SELECT bytes FROM school_assets WHERE school_id='.sid().' AND id=?',[$am[1]])->fetch(); if(!$a) api_fail(404,'not_found','Imej tidak ditemui.'); header('Content-Type: image/png'); header('Content-Length: '.strlen((string)$a['bytes'])); echo $a['bytes']; exit; }
    if($path==='/api/v1/workflow'){ api_method($method,['GET']); api_respond(workflow_state()); }
    if($path==='/api/v1/workflow/published'){ api_method($method,['GET']); $p=workflow_published(); if(!$p) api_fail(409,'not_finalized','Belum ada agihan muktamad.'); api_respond(['revision_id'=>$p['id'],'finalized_at'=>$p['finalized_at'],'snapshot'=>$p['data']]); }
    if($path==='/api/v1/school'){ api_method($method,['GET']); api_respond(school_profile()); }
    if(($path==='/api/v1/allocation/rules' || str_starts_with($path,'/api/v1/allocation/') || preg_match('#^/api/v1/positions/[0-9]+/rules$#D',$path)) && !feature_on('auto')) api_fail(403,'pro_required','Agihan automatik & syarat tersedia untuk pakej Pro sahaja.');
    if($path==='/api/v1/positions/bulk' && !feature_on('pukal')) api_fail(403,'pro_required','Import pukal tersedia untuk pakej Pro sahaja.');
    if($path==='/api/v1/allocation/rules'){
        api_method($method,['GET','PUT']);
        if($method==='GET') api_respond(['general'=>allocation_rules(),'positions'=>(object)allocation_position_rules()]);
        $body=api_body(array_keys(allocation_defaults())); if(isset($body['category_limits']) && $body['category_limits'] instanceof stdClass) $body['category_limits']=(array)$body['category_limits'];
        api_respond(api_write(function()use($body){ $r=allocation_save_rules($body); api_audit('allocation_rules','allocation_config',null); return $r; }));
    }
    if(preg_match('#^/api/v1/positions/([1-9][0-9]*)/rules$#D',$path,$rm)){
        api_method($method,['GET','PUT']); $id=(int)$rm[1]; if(!position_find($id)) api_fail(404,'not_found','Rekod tidak ditemui.');
        if($method==='GET') api_respond(allocation_position_rules()[$id]??['allowed_teachers'=>[],'fixed_teacher_id'=>null,'required_specialty'=>'']);
        $body=api_body(['allowed_teachers','fixed_teacher_id','required_specialty']);
        api_respond(api_write(function()use($id,$body){ $r=allocation_save_position_rule($id,$body); api_audit('position_rule','positions',$id); return $r; }));
    }
    if($path==='/api/v1/allocation/preview'){ api_method($method,['POST']); $body=api_body(['mode']); if(!is_string($body['mode']??null)) api_fail(422,'validation_error','mode diperlukan.'); api_respond(api_write(fn()=>allocation_preview($body['mode']))); }
    if($path==='/api/v1/allocation/apply'){
        api_method($method,['POST']); $body=api_body(['plan_id']); if(!is_string($body['plan_id']??null)) api_fail(422,'validation_error','plan_id diperlukan.');
        api_respond(api_write(function()use($body){ $s=allocation_apply($body['plan_id']); api_audit('allocation_apply','allocation_plans',null,$s['changed'].' perubahan'); return $s; }));
    }
    if($path==='/api/v1/positions/bulk'){
        api_method($method,['POST']); $body=api_body(['rows']); if(!is_array($body['rows']??null)) api_fail(422,'validation_error','rows mesti senarai objek jawatan.');
        $rows=array_map(fn($r)=>$r instanceof stdClass?(array)$r:$r,$body['rows']);
        $ids=api_write(function()use($rows){ $ids=bulk_positions_insert(bulk_positions_validate($rows)); api_audit('position_bulk','positions',null,count($ids).' jawatan'); return $ids; });
        api_respond(['created'=>count($ids),'ids'=>$ids],201);
    }
    if($path==='/api/v1/teachers/sync'){
        api_method($method,['POST']); $body=api_body(['external_id','name','email','specialty','capacity']); $x=api_str($body['external_id']??null,'external_id',160);
        [$id,$new]=api_write(function()use($body,$x){
            $ex=query('SELECT * FROM teachers WHERE school_id='.sid().' AND external_id=?',[$x])->fetch();
            $v=api_teacher_fields($body,$ex?:['capacity'=>25]); $id=teacher_save($ex?(int)$ex['id']:0,$v);
            api_audit('teacher_sync','teachers',$id,$x); return [$id,!$ex];
        });
        api_respond(api_teacher($id),$new?201:200);
    }
    if(!preg_match('#^/api/v1/(teachers|positions|assignments|summary|letters)(?:/([1-9][0-9]*))?(?:/(assignment|assignments))?$#D',$path,$pt)) api_fail(404,'not_found','Endpoint tidak ditemui.');
    $res=$pt[1]; $id=isset($pt[2])?(int)$pt[2]:null; $child=$pt[3]??null;
    if($res==='summary' && !$id && !$child){
        api_method($method,['GET']); $t=teachers_with_load(); $c=engine_counts();
        $cat=array_map(fn($r)=>['category'=>$r['category'],'positions'=>(int)$r['positions'],'assigned'=>(int)$r['assigned'],'load'=>(int)$r['load']],query('SELECT p.category,COUNT(p.id) AS positions,COUNT(a.id) AS assigned,COALESCE(SUM(CASE WHEN a.id IS NOT NULL THEN p.weight ELSE 0 END),0) AS `load` FROM positions p LEFT JOIN assignments a ON a.position_id=p.id AND a.school_id=p.school_id WHERE p.school_id='.sid().' GROUP BY p.category')->fetchAll());
        api_respond(['teachers'=>$c['teachers'],'positions'=>$c['positions'],'assigned'=>$c['assigned'],'vacant'=>$c['vacant'],'total_load'=>array_sum(array_map(fn($r)=>(int)$r['load'],$t)),'over_capacity'=>count(array_filter($t,fn($r)=>$r['load']>$r['capacity'])),'by_category'=>$cat]);
    }
    if($res==='letters' && $id && !$child){
        api_method($method,['GET']); $doc=workflow_letter($id);
        $doc['assignments']=array_map(fn($p)=>['position_id'=>$p['id'],'position_name'=>$p['name'],'category'=>$p['category'],'weight'=>$p['weight']],$doc['assignments']);
        $doc['teacher']['load']=array_sum(array_column($doc['assignments'],'weight')); $doc['teacher']['task_count']=count($doc['assignments']); $doc['teacher']['over_capacity']=$doc['teacher']['load']>$doc['teacher']['capacity'];
        api_respond($doc);
    }
    if($res==='teachers' && $id && $child==='assignments'){
        api_method($method,['GET']); api_teacher($id);
        api_respond(array_map(fn($r)=>['id'=>(int)$r['id'],'position_id'=>(int)$r['position_id'],'position_name'=>$r['position_name'],'category'=>$r['category'],'weight'=>(int)$r['weight']],query('SELECT a.id,a.position_id,p.name AS position_name,p.category,p.weight FROM assignments a JOIN positions p ON p.id=a.position_id AND p.school_id=a.school_id WHERE a.school_id='.sid().' AND a.teacher_id=? ORDER BY p.category,p.name',[$id])->fetchAll()));
    }
    if($res==='positions' && $id && $child==='assignment'){
        api_method($method,['PUT']); $body=api_body(['teacher_id']); $tid=api_int($body['teacher_id']??null,'teacher_id');
        $aid=api_write(function()use($id,$tid){ $aid=assign_position($id,$tid); api_audit('assign','assignments',$aid,'jawatan #'.$id.' → guru #'.$tid); return $aid; });
        $r=api_assignment($aid); $r['teacher']=api_teacher($tid); api_respond($r);
    }
    if($child || !in_array($res,['teachers','positions','assignments'],true)) api_fail(404,'not_found','Endpoint tidak ditemui.');
    api_method($method,$id?['GET','PATCH','DELETE']:['GET','POST']);
    $one=fn(int $n)=>match($res){'teachers'=>api_teacher($n),'positions'=>api_position($n),'assignments'=>api_assignment($n)};
    if($method==='GET'){
        if($id) api_respond($one($id));
        [$page,$per]=api_pagination(); $where=['r.school_id='.sid()]; $params=[];
        if(isset($_GET['q'])){ if($res==='assignments') api_fail(422,'validation_error','Gunakan teacher_id atau position_id untuk menapis assignments.'); $where[]='r.name LIKE ?'; $params[]='%'.addcslashes(api_str($_GET['q'],'q',160,false),'%_\\').'%'; }
        if($res==='teachers' && isset($_GET['external_id'])){ $where[]='r.external_id=?'; $params[]=api_str($_GET['external_id'],'external_id',160); }
        if($res==='positions' && isset($_GET['category'])){ if(!in_array($_GET['category'],CATEGORIES,true)) api_fail(422,'validation_error','category tidak sah.'); $where[]='r.category=?'; $params[]=$_GET['category']; }
        if($res==='assignments') foreach(['teacher_id','position_id'] as $f) if(isset($_GET[$f])){ if(!is_string($_GET[$f]) || !ctype_digit($_GET[$f]) || (int)$_GET[$f]<1) api_fail(422,'validation_error',"$f tidak sah."); $where[]="r.$f=?"; $params[]=(int)$_GET[$f]; }
        $w=' WHERE '.implode(' AND ',$where); $total=(int)query("SELECT COUNT(*) FROM $res r$w",$params)->fetchColumn();
        $ids=query("SELECT r.id FROM $res r$w ORDER BY r.id LIMIT $per OFFSET ".(($page-1)*$per),$params)->fetchAll(PDO::FETCH_COLUMN);
        api_respond(array_map(fn($n)=>$one((int)$n),$ids),200,['page'=>$page,'per_page'=>$per,'total'=>$total,'pages'=>(int)ceil($total/$per)]);
    }
    if($method==='DELETE'){
        api_write(function()use($res,$id){
            if($res==='teachers') teacher_delete($id); elseif($res==='positions') position_delete($id); else unassign($id);
            api_audit(['teachers'=>'teacher_delete','positions'=>'position_delete','assignments'=>'unassign'][$res],$res,$id);
        });
        api_respond(['id'=>$id,'deleted'=>true]);
    }
    $fields=match($res){'teachers'=>['name','email','specialty','capacity','external_id'],'positions'=>['name','category','weight'],'assignments'=>['teacher_id','position_id']};
    $body=api_body($fields);
    $newId=api_write(function()use($res,$id,$body){
        if($res==='assignments'){
            $prev=$id?api_assignment($id):[];
            if($id && isset($body['position_id']) && $body['position_id']!==$prev['position_id']) api_fail(422,'validation_error','position_id tidak boleh diubah. Lepaskan dan cipta lantikan baharu.');
            $v=array_merge($prev,$body); $tid=api_int($v['teacher_id']??null,'teacher_id'); $pid=api_int($v['position_id']??null,'position_id');
            if(!teacher_find($tid) || !position_find($pid)) api_fail(404,'not_found','Rekod tidak ditemui.');
            $ex=query('SELECT id FROM assignments WHERE school_id='.sid().' AND position_id=?',[$pid])->fetchColumn();
            if($ex && (!$id || (int)$ex!==$id)) api_fail(409,'position_assigned','Jawatan telah diisi. Gunakan PUT /positions/{id}/assignment untuk menetapkan penyandang.');
            $n=assign_position($pid,$tid); api_audit($id?'assign':'assign','assignments',$n,'jawatan #'.$pid.' → guru #'.$tid); return $n;
        }
        if($res==='teachers'){ $prev=$id?api_teacher($id):[]; $n=teacher_save((int)$id,api_teacher_fields($body,array_intersect_key($prev,array_flip(['name','email','specialty','capacity'])))); }
        else { $prev=$id?api_position($id):[]; $d=array_merge(array_intersect_key($prev,array_flip(['name','category','weight'])),$body);
            $n=position_save((int)$id,['name'=>api_str($d['name']??null,'name'),'category'=>api_str($d['category']??null,'category'),'weight'=>api_int($d['weight']??null,'weight',1,10)]); }
        api_audit(($res==='teachers'?'teacher_':'position_').($id?'update':'create'),$res,$n); return $n;
    });
    if($method==='POST') header('Location: '.tenant_prefix().'/api/v1/'.$res.'/'.$newId);
    api_respond($one($newId),$method==='POST'?201:200);
}
