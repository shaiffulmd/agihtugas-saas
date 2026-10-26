<?php
declare(strict_types=1);
// API requests deliberately do not start a browser session or accept cookies as authentication.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$requestId=bin2hex(random_bytes(8));
header('X-Request-ID: '.$requestId);
final class ApiError extends RuntimeException {
 public function __construct(public int $status,public string $errorCode,string $message) { parent::__construct($message); }
}
function fail(int $status,string $code,string $message): never { throw new ApiError($status,$code,$message); }
function respond(mixed $data,int $status=200,?array $meta=null): never {
 global $requestId;
 http_response_code($status);
 $result=['data'=>$data]; if($meta!==null) $result['meta']=$meta;
 $result['request_id']=$requestId;
 echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit;
}
function record(string $table,int $id): array {
 $row=q("SELECT * FROM $table WHERE id=?",[$id])->fetch();
 if(!$row) fail(404,'not_found','Rekod tidak ditemui.'); return $row;
}
function integer(mixed $v,string $field,int $min=1,int $max=PHP_INT_MAX): int {
 if(!is_int($v)||$v<$min||$v>$max) fail(422,'validation_error',"$field mesti integer antara $min dan $max."); return $v;
}
function str_field(mixed $v,string $field,int $max=160,bool $required=true): string {
 if(!is_string($v)||($required&&trim($v)==='')||mb_strlen(trim($v))>$max) fail(422,'validation_error',"$field mesti teks yang sah (maksimum $max aksara)."); return trim($v);
}
function json_body(array $fields): array {
 $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
 if($type!=='application/json') fail(415,'unsupported_media_type','Gunakan Content-Type: application/json.');
 $raw=file_get_contents('php://input',false,null,0,65537);
 if(strlen($raw)>65536) fail(413,'payload_too_large','Had saiz permintaan ialah 64 KB.');
 try { $object=json_decode($raw,false,32,JSON_THROW_ON_ERROR); } catch(JsonException) { fail(400,'invalid_json','JSON tidak sah.'); }
 if(!$object instanceof stdClass) fail(400,'invalid_json','Badan JSON mesti objek.');
 $data=(array)$object;
 if(array_diff(array_keys($data),$fields)) fail(422,'validation_error','Permintaan mengandungi medan yang tidak disokong.');
 if(!$data) fail(422,'validation_error','Sertakan sekurang-kurangnya satu medan.');
 return $data;
}
function method_allowed(string $method,array $allowed): void {
 if(!in_array($method,$allowed,true)) { header('Allow: '.implode(', ',$allowed)); fail(405,'method_not_allowed','Kaedah HTTP tidak disokong untuk endpoint ini.'); }
}
function teacher_data(int $id): array {
 record('teachers',$id);
 $t=q('SELECT t.*,COALESCE(SUM(p.weight),0) AS load,COUNT(a.id) AS task_count FROM teachers t LEFT JOIN assignments a ON a.teacher_id=t.id LEFT JOIN positions p ON p.id=a.position_id WHERE t.id=? GROUP BY t.id',[$id])->fetch();
 $t['over_capacity']=$t['load']>$t['capacity']; return $t;
}
function position_data(int $id): array {
 record('positions',$id);
 return q('SELECT p.*,a.id AS assignment_id,a.teacher_id FROM positions p LEFT JOIN assignments a ON a.position_id=p.id WHERE p.id=?',[$id])->fetch();
}
function assignment_data(int $id): array {
 record('assignments',$id);
 return q('SELECT a.*,t.name AS teacher_name,p.name AS position_name,p.category,p.weight FROM assignments a JOIN teachers t ON t.id=a.teacher_id JOIN positions p ON p.id=a.position_id WHERE a.id=?',[$id])->fetch();
}
function pagination(): array {
 $values=[];
 foreach(['page'=>1,'per_page'=>50] as $key=>$default) {
  $v=$_GET[$key]??(string)$default;
  if(!is_string($v)||!ctype_digit($v)||(int)$v<1||(int)$v>($key==='per_page'?100:1000000)) fail(422,'validation_error','page mesti 1–1000000 dan per_page mesti 1–100.');
  $values[]=(int)$v;
 }
 return $values;
}
function teacher_fields(array $body,array $previous=[]): array {
 $data=array_merge(['email'=>'','specialty'=>''],$previous,$body);
 $name=str_field($data['name']??null,'name');
 $email=str_field($data['email'],'email',254,false);
 if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) fail(422,'validation_error','email tidak sah.');
 $result=['name'=>$name,'email'=>$email,'specialty'=>str_field($data['specialty'],'specialty',160,false),'capacity'=>integer($data['capacity']??null,'capacity',1,100)];
 if(array_key_exists('external_id',$data)) $result['external_id']=$data['external_id']===null?null:str_field($data['external_id'],'external_id',160);
 return $result;
}
function position_fields(array $body,array $previous=[]): array {
 $data=array_merge($previous,$body);
 $name=str_field($data['name']??null,'name'); $category=str_field($data['category']??null,'category');
 if(!in_array($category,CATEGORIES,true)) fail(422,'validation_error','category mesti Pentadbiran, Kurikulum, HEM atau Kokurikulum.');
 return ['name'=>$name,'category'=>$category,'weight'=>integer($data['weight']??null,'weight',1,10)];
}
function audit_api(string $description): void {
 global $apiKey;
 q('INSERT INTO history(description) VALUES (?)',["API #{$apiKey['id']} ({$apiKey['name']}): $description"]);
}
try {
 $method=$_SERVER['REQUEST_METHOD'];
 $origin=$_SERVER['HTTP_ORIGIN']??'';
 $origins=array_filter(array_map('trim',explode(',',getenv('AGIHTUGAS_CORS_ORIGINS')?:'')));
 if($origin!==''&&in_array($origin,$origins,true)) {
  header('Access-Control-Allow-Origin: '.$origin); header('Vary: Origin');
  header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
  header('Access-Control-Allow-Headers: Authorization, Content-Type');
  header('Access-Control-Expose-Headers: X-Request-ID, Location');
 }
 if($method==='OPTIONS') { if($origin===''||!in_array($origin,$origins,true)) fail(403,'origin_not_allowed','Origin tidak dibenarkan.'); http_response_code(204); exit; }
 require_once __DIR__.'/database.php';
 require_once __DIR__.'/allocation.php';
 require_once __DIR__.'/bulk_positions.php';
 require_once __DIR__.'/workflow.php';
 $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
 if(!preg_match('/^Bearer (agt_[a-f0-9]{64})$/i',$authorization,$m)) { header('WWW-Authenticate: Bearer realm="AgihTugas"'); fail(401,'unauthorized','API key diperlukan dalam header Authorization: Bearer.'); }
 $apiKey=q('SELECT * FROM api_keys WHERE token_hash=? AND revoked_at IS NULL',[hash('sha256',$m[1])])->fetch();
 if(!$apiKey) { header('WWW-Authenticate: Bearer realm="AgihTugas"'); fail(401,'unauthorized','API key tidak sah atau telah dibatalkan.'); }
 if(!in_array($method,['GET','HEAD'],true)&&$apiKey['scope']!=='write') fail(403,'insufficient_scope','API key ini hanya membenarkan bacaan.');
 q('UPDATE api_keys SET last_used_at=CURRENT_TIMESTAMP WHERE id=?',[$apiKey['id']]);
 $path=rtrim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'','/');
 if(preg_match('#^/api/v1/assets/([a-f0-9]{64})$#D',$path,$assetMatch)) { method_allowed($method,['GET']); branding_serve($assetMatch[1]); }
 if($path==='/api/v1/workflow') { method_allowed($method,['GET']); respond(workflow_state()); }
 if($path==='/api/v1/workflow/published') {
  method_allowed($method,['GET']); $published=workflow_published();
  if(!$published) fail(409,'not_finalized','Belum ada agihan muktamad.');
  respond(['revision_id'=>$published['id'],'finalized_at'=>$published['finalized_at'],'snapshot'=>$published['data']]);
 }
 if($path==='/api/v1/allocation/rules') {
  method_allowed($method,['GET','PUT']);
  if($method==='GET') respond(['general'=>allocation_rules(),'positions'=>(object)allocation_position_rules()]);
  $body=json_body(array_keys(allocation_defaults()));
  if(isset($body['category_limits'])&&$body['category_limits'] instanceof stdClass) $body['category_limits']=(array)$body['category_limits'];
  $db->beginTransaction(); workflow_require_draft(); $rules=allocation_save_rules($body); audit_api('Syarat agihan automatik dikemas kini.'); $db->commit(); respond($rules);
 }
 if(preg_match('#^/api/v1/positions/([1-9][0-9]*)/rules$#D',$path,$ruleMatch)) {
  method_allowed($method,['GET','PUT']); $id=(int)$ruleMatch[1]; record('positions',$id);
  if($method==='GET') respond(allocation_position_rules()[$id]??['allowed_teachers'=>[],'fixed_teacher_id'=>null,'required_specialty'=>'']);
  $body=json_body(['allowed_teachers','fixed_teacher_id','required_specialty']);
  $db->beginTransaction(); workflow_require_draft(); $rule=allocation_save_position_rule($id,$body); audit_api("Syarat jawatan #$id dikemas kini."); $db->commit(); respond($rule);
 }
 if($path==='/api/v1/allocation/preview') {
  method_allowed($method,['POST']); $body=json_body(['mode']);
  if(!is_string($body['mode']??null)) fail(422,'validation_error','mode diperlukan.');
  $db->beginTransaction(); workflow_require_draft(); $plan=allocation_preview($body['mode']); $db->commit(); respond($plan);
 }
 if($path==='/api/v1/allocation/apply') {
  method_allowed($method,['POST']); $body=json_body(['plan_id']);
  if(!is_string($body['plan_id']??null)) fail(422,'validation_error','plan_id diperlukan.');
  $db->beginTransaction(); workflow_require_draft(); $summary=allocation_apply($body['plan_id']); audit_api('Agihan automatik disimpan: '.$summary['changed'].' perubahan.'); $db->commit(); respond($summary);
 }
 if($path==='/api/v1/positions/bulk') {
  method_allowed($method,['POST']); $body=json_body(['rows']);
  if(!is_array($body['rows']??null)) fail(422,'validation_error','rows mesti senarai objek jawatan.');
  $rows=array_map(fn($r)=>$r instanceof stdClass?(array)$r:$r,$body['rows']);
  $db->beginTransaction(); workflow_require_draft(); $validated=bulk_positions_validate($rows); $ids=bulk_positions_insert($validated); audit_api(count($ids).' jawatan ditambah secara pukal.'); $db->commit(); respond(['created'=>count($ids),'ids'=>$ids],201);
 }
 if($path==='/api/v1/teachers/sync') {
  method_allowed($method,['POST']);
  $body=json_body(['external_id','name','email','specialty','capacity']);
  $externalId=str_field($body['external_id']??null,'external_id',160);
  $db->beginTransaction(); workflow_require_draft();
  $existing=q('SELECT * FROM teachers WHERE external_id=?',[$externalId])->fetch();
  $values=teacher_fields($body,$existing?:['capacity'=>25]);
  $columns=array_keys($values);
  if($existing) { $id=(int)$existing['id']; q('UPDATE teachers SET '.implode(',',array_map(fn($c)=>"$c=?",$columns)).' WHERE id=?',[...array_values($values),$id]); }
  else { q('INSERT INTO teachers ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($values)); $id=(int)$db->lastInsertId(); }
  audit_api("Guru #$id disegerakkan daripada rujukan luar."); $result=teacher_data($id); $db->commit(); respond($result,$existing?200:201);
 }
 if(!preg_match('#^/api/v1/(teachers|positions|assignments|summary|school|letters)(?:/([1-9][0-9]*))?(?:/(assignment|assignments))?$#D',$path,$parts)) fail(404,'not_found','Endpoint tidak ditemui.');
 $resource=$parts[1]; $id=isset($parts[2])?(int)$parts[2]:null; $child=$parts[3]??null;
 if($resource==='school'&&!$id&&!$child) { method_allowed($method,['GET']); respond(school_profile()); }
 if($resource==='summary'&&!$id&&!$child) {
  method_allowed($method,['GET']);
  $teachers=q('SELECT t.id,t.capacity,COALESCE(SUM(p.weight),0) AS load FROM teachers t LEFT JOIN assignments a ON a.teacher_id=t.id LEFT JOIN positions p ON p.id=a.position_id GROUP BY t.id')->fetchAll();
  $positions=(int)q('SELECT COUNT(*) FROM positions')->fetchColumn(); $assigned=(int)q('SELECT COUNT(*) FROM assignments')->fetchColumn();
  respond(['teachers'=>count($teachers),'positions'=>$positions,'assigned'=>$assigned,'vacant'=>$positions-$assigned,'total_load'=>array_sum(array_column($teachers,'load')),'over_capacity'=>count(array_filter($teachers,fn($t)=>$t['load']>$t['capacity'])),'by_category'=>q('SELECT p.category,COUNT(p.id) AS positions,COUNT(a.id) AS assigned,COALESCE(SUM(CASE WHEN a.id IS NOT NULL THEN p.weight ELSE 0 END),0) AS load FROM positions p LEFT JOIN assignments a ON a.position_id=p.id GROUP BY p.category')->fetchAll()]);
 }
 if(($resource==='letters'&&$id&&!$child)||($resource==='teachers'&&$id&&$child==='assignments')) {
  method_allowed($method,['GET']);
  if($resource==='letters') {
   $doc=workflow_letter($id); $doc['assignments']=array_map(fn($p)=>['position_id'=>$p['id'],'position_name'=>$p['name'],'category'=>$p['category'],'weight'=>$p['weight']],$doc['assignments']);
   $doc['teacher']['load']=array_sum(array_column($doc['assignments'],'weight')); $doc['teacher']['task_count']=count($doc['assignments']); $doc['teacher']['over_capacity']=$doc['teacher']['load']>$doc['teacher']['capacity'];
   respond($doc);
  }
  teacher_data($id);
  respond(q('SELECT a.id,a.position_id,p.name AS position_name,p.category,p.weight FROM assignments a JOIN positions p ON p.id=a.position_id WHERE a.teacher_id=? ORDER BY p.category,p.name',[$id])->fetchAll());
 }
 if($resource==='positions'&&$id&&$child==='assignment') {
  method_allowed($method,['PUT']); $body=json_body(['teacher_id']); $tid=integer($body['teacher_id']??null,'teacher_id');
  $db->beginTransaction(); workflow_require_draft(); record('positions',$id); record('teachers',$tid);
  q('INSERT INTO assignments(teacher_id,position_id) VALUES (?,?) ON CONFLICT(position_id) DO UPDATE SET teacher_id=excluded.teacher_id',[$tid,$id]);
  $aid=(int)q('SELECT id FROM assignments WHERE position_id=?',[$id])->fetchColumn();
  audit_api("Jawatan #$id diagihkan kepada guru #$tid."); $result=assignment_data($aid); $result['teacher']=teacher_data($tid); $db->commit(); respond($result);
 }
 if($child||!in_array($resource,['teachers','positions','assignments'],true)) fail(404,'not_found','Endpoint tidak ditemui.');
 method_allowed($method,$id?['GET','PATCH','DELETE']:['GET','POST']);
 if($method==='GET') {
  if($id) respond(match($resource) {'teachers'=>teacher_data($id),'positions'=>position_data($id),'assignments'=>assignment_data($id)});
  [$page,$perPage]=pagination(); $params=[]; $where=[];
  if(isset($_GET['q'])) {
   $search=str_field($_GET['q'],'q',160,false); $where[]='r.name LIKE ?'; $params[]='%'.$search.'%';
   if($resource==='assignments') fail(422,'validation_error','Gunakan teacher_id atau position_id untuk menapis assignments.');
  }
  if($resource==='teachers'&&isset($_GET['external_id'])) { $where[]='r.external_id=?'; $params[]=str_field($_GET['external_id'],'external_id',160); }
  if($resource==='positions'&&isset($_GET['category'])) { if(!in_array($_GET['category'],CATEGORIES,true)) fail(422,'validation_error','category tidak sah.'); $where[]='r.category=?'; $params[]=$_GET['category']; }
  if($resource==='assignments') foreach(['teacher_id','position_id'] as $f) if(isset($_GET[$f])) { if(!is_string($_GET[$f])||!ctype_digit($_GET[$f])||(int)$_GET[$f]<1) fail(422,'validation_error',"$f tidak sah."); $where[]="r.$f=?"; $params[]=(int)$_GET[$f]; }
  $filter=$where?' WHERE '.implode(' AND ',$where):'';
  $total=(int)q("SELECT COUNT(*) FROM $resource r$filter",$params)->fetchColumn();
  $offset=($page-1)*$perPage;
  $ids=q("SELECT r.id FROM $resource r$filter ORDER BY r.id LIMIT $perPage OFFSET $offset",$params)->fetchAll(PDO::FETCH_COLUMN);
  $items=array_map(fn($n)=>match($resource) {'teachers'=>teacher_data((int)$n),'positions'=>position_data((int)$n),'assignments'=>assignment_data((int)$n)},$ids);
  respond($items,200,['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>(int)ceil($total/$perPage)]);
 }
 $db->beginTransaction(); workflow_require_draft();
 $previous=$id?record($resource,$id):[];
 if($method==='DELETE') {
  if($resource!=='assignments') { $column=$resource==='teachers'?'teacher_id':'position_id'; if(q("SELECT 1 FROM assignments WHERE $column=?",[$id])->fetchColumn()) fail(409,'record_in_use','Lepaskan agihan berkaitan dahulu sebelum memadam.'); }
  if($resource==='teachers'&&q('SELECT 1 FROM accounts WHERE teacher_id=?',[$id])->fetchColumn()) fail(409,'record_in_use','Nyahpaut akaun guru dahulu.');
  if($resource==='teachers'&&allocation_teacher_referenced($id)) fail(409,'record_in_use','Buang rujukan guru dalam Pusat Syarat dahulu.');
  q("DELETE FROM $resource WHERE id=?",[$id]); audit_api("Rekod $resource #$id dipadam."); $db->commit(); respond(['id'=>$id,'deleted'=>true]);
 }
 $fields=match($resource) {'teachers'=>['name','email','specialty','capacity','external_id'],'positions'=>['name','category','weight'],'assignments'=>['teacher_id','position_id']};
 $body=json_body($fields);
 if($resource==='assignments') {
  if($id&&isset($body['position_id'])&&$body['position_id']!==$previous['position_id']) fail(422,'validation_error','position_id tidak boleh diubah. Lepaskan dan cipta lantikan baharu.');
  $values=array_merge($previous,$body); $tid=integer($values['teacher_id']??null,'teacher_id'); $pid=integer($values['position_id']??null,'position_id');
  record('teachers',$tid); record('positions',$pid);
  $existing=q('SELECT id FROM assignments WHERE position_id=?',[$pid])->fetchColumn();
  if($existing&&(!$id||(int)$existing!==$id)) fail(409,'position_assigned','Jawatan telah diisi. Gunakan PUT /positions/{id}/assignment untuk menetapkan penyandang.');
  $values=['teacher_id'=>$tid,'position_id'=>$pid];
 } else $values=$resource==='teachers'?teacher_fields($body,$previous):position_fields($body,$previous);
 $columns=array_keys($values);
 if($id) q("UPDATE $resource SET ".implode(',',array_map(fn($c)=>"$c=?",$columns)).' WHERE id=?',[...array_values($values),$id]);
 else { q("INSERT INTO $resource (".implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($values)); $id=(int)$db->lastInsertId(); }
 audit_api("Rekod $resource #$id ".($method==='POST'?'dicipta.':'dikemas kini.'));
 $result=match($resource) {'teachers'=>teacher_data($id),'positions'=>position_data($id),'assignments'=>assignment_data($id)};
 $db->commit();
 if($method==='POST') header('Location: /api/v1/'.$resource.'/'.$id);
 respond($result,$method==='POST'?201:200);
} catch(Throwable $ex) {
 if(isset($db)&&$db->inTransaction()) $db->rollBack();
 if($ex instanceof BrandingError) $ex=new ApiError($ex->status,'asset_error',$ex->getMessage());
 if($ex instanceof WorkflowError) $ex=new ApiError($ex->status,$ex->status===409?'workflow_locked':'not_found',$ex->getMessage());
 if($ex instanceof AllocationError) $ex=new ApiError($ex->status,$ex->status===409?'stale_or_used_plan':($ex->status===404?'not_found':'validation_error'),$ex->getMessage());
 $duplicate=$ex instanceof PDOException&&str_contains($ex->getMessage(),'UNIQUE constraint failed: teachers.external_id');
 if($duplicate) $ex=new ApiError(409,'duplicate_external_id','external_id sudah digunakan. Gunakan POST /teachers/sync untuk menyegerakkan guru.');
 $status=$ex instanceof ApiError?$ex->status:500;
 if($status===500) error_log('AgihTugas API '.$requestId.': '.$ex->getMessage());
 http_response_code($status);
 echo json_encode(['error'=>['code'=>$ex instanceof ApiError?$ex->errorCode:'internal_error','message'=>$ex instanceof ApiError?$ex->getMessage():'Ralat dalaman. Hubungi pentadbir dengan request_id.'],'request_id'=>$requestId],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
