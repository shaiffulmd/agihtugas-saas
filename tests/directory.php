<?php
declare(strict_types=1);
/* Isolated directory tests. No bootstrap, network or application database access.
 * Run: php tests/directory.php (requires pdo_sqlite).
 * SQLite executes the real directory SELECTs against disposable in-memory rows;
 * configuration/session adapters below replace only the surrounding application. */
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$settings=[]; $logs=[]; $_SESSION=[]; $_GET=[]; $_POST=[]; $passed=0;
function query(string $sql,array $params=[]): PDOStatement { global $pdo; $stmt=$pdo->prepare($sql); $stmt->execute($params); return $stmt; }
function pset(string $key,string $default=''): string { global $settings; return $settings[$key]??$default; }
function pset_set(array $values): void { global $settings; $settings=array_replace($settings,$values); }
function input(string $key,string $source='post'): string { $value=($source==='get'?$_GET:$_POST)[$key]??''; return is_string($value)?$value:''; }
function platform_log(string $action,?int $id=null,string $detail=''): void { global $logs; $logs[]=$action; }
function flash(string $message): void {}
class DirectoryRedirect extends RuntimeException {}
function redirect(string $path): never { throw new DirectoryRedirect($path); }
function check(bool $ok,string $message): void { global $passed; if(!$ok) throw new RuntimeException('FAIL: '.$message); ++$passed; echo "PASS: $message\n"; }
if(!function_exists('e')){ function e(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
if(!function_exists('icon')){ function icon(string $name,string $class=''): string { return '<svg class="i" data-i="'.$name.'"></svg>'; } }
require dirname(__DIR__).'/app/appearance.php';

query('CREATE TABLE schools (id INTEGER PRIMARY KEY AUTOINCREMENT,code TEXT UNIQUE,name TEXT,state TEXT,status TEXT,pic_email TEXT)');
query('CREATE TABLE settings (school_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(school_id,name))');
for($i=1;$i<=26;$i++) query('INSERT INTO schools(code,name,state,status,pic_email) VALUES (?,?,?,?,?)',[sprintf('DEM%04d',$i),sprintf('Sekolah %02d',$i),$i<=20?'Pahang':'Johor','aktif','private@example.test']);
foreach(['emel','menunggu','digantung','diarkib'] as $i=>$status) query('INSERT INTO schools(code,name,state,status,pic_email) VALUES (?,?,?,?,?)',['HID000'.$i,'Sekolah Tersembunyi','Pahang',$status,'hidden@example.test']);
query('INSERT INTO schools(code,name,state,status,pic_email) VALUES (?,?,?,?,?)',['LIT0001','Sekolah 100%_!','Pahang','aktif','private@example.test']);

check(directory_default_layout()==='carian','fresh install defaults to search layout');
query('INSERT INTO settings VALUES ((SELECT id FROM schools WHERE code=?),"logo","iVBORw0KGgo="),((SELECT id FROM schools WHERE code=?),"theme_version","abc123")',['DEM0001','DEM0001']);
$rr=directory_results('DEM0001','',1)['rows']; $one=$rr[0]??[];
check(!empty($one['has_logo']) && str_contains(directory_school_mark($one),'/?page=sch-logo&amp;kod=DEM0001&amp;v=abc123'),'school with logo shows logo image in directory');
$two=directory_results('DEM0002','',1)['rows'][0]??[];
check(empty($two['has_logo']) && !str_contains(directory_school_mark($two),'<img'),'school without logo keeps school icon');
$settings['directory_layout']='kad';
check(directory_layout()==='kad','new visitor inherits admin default');
$_GET=['paparan'=>'senarai'];
check(directory_layout()==='senarai','valid user choice overrides default');
$_GET=[];
check(directory_layout()==='senarai','choice persists across requests in the session');
$_GET=['paparan'=>'<script>'];
check(directory_layout()==='senarai','invalid layout does not overwrite preference');
$_GET=['paparan'=>['kad']];
check(directory_layout()==='senarai','array layout input is ignored');
$settings['directory_switch']='0'; $_GET=['paparan'=>'carian'];
check(directory_layout()==='kad','admin lock overrides both URL and saved preference');
$settings['directory_layout']='invalid';
check(directory_layout()==='carian','invalid stored default safely falls back');

$result=directory_results('','',1);
check($result['total']===27 && count($result['rows'])===12,'only active schools counted; page bounded to 12');
check(array_keys($result['rows'][0])===['code','name','state','has_logo','logo_v'],'public query returns only public fields (incl. logo flag/version)');
$second=directory_results('','',2); $last=directory_results('','',999999);
check(!array_intersect(array_column($result['rows'],'code'),array_column($second['rows'],'code')),'pages do not duplicate schools');
check($last['page']===3 && count($last['rows'])===3,'out-of-range page clamps to last page');
check(directory_results('','',-10)['page']===1,'negative page clamps to first page');
check(directory_results('','Johor',1)['total']===6,'state filter');
check(directory_results('Sekolah 2','Johor',1)['total']===6,'combined name and state search');
check(directory_results('DEM0001','',1)['rows'][0]['code']==='DEM0001','school code search');
check(directory_results('Tersembunyi','',1)['total']===0,'pending, suspended and archived schools are excluded');
check(directory_results('%_!','',1)['total']===1,'SQL wildcard characters matched literally');
check(directory_results("' OR 1=1 --",'',1)['total']===0,'SQL metacharacters do not broaden results');
check(directory_results('','Unknown',1)['total']===0,'unknown state returns empty result');
$empty=directory_results('Missing','',99);
check($empty['total']===0 && $empty['page']===1 && $empty['rows']===[],'empty results have a valid page');
parse_str((string)parse_url(directory_url('A&B','Pahang','kad',2),PHP_URL_QUERY),$url);
check($url===['q'=>'A&B','negeri'=>'Pahang','paparan'=>'kad','halaman'=>'2'],'links preserve encoded search, state, layout and page');

$settings=['lp_fon'=>'mesra','lp_t1'=>'Keep this']; $_POST=['directory_layout'=>'senarai','directory_switch'=>'1'];
try { handle_appearance_platform('p_rupa_directory'); } catch(DirectoryRedirect $e){ check($e->getMessage()==='/platform/?p=rupa','save returns to appearance page'); }
check($settings['directory_layout']==='senarai' && $settings['directory_switch']==='1','admin save stores selected default and toggle');
check($settings['lp_fon']==='mesra' && $settings['lp_t1']==='Keep this','directory save preserves existing landing appearance');
check(in_array('directory_appearance_saved',$logs,true),'admin save records audit event');
$_POST=['directory_layout'=>'kad'];
try { handle_appearance_platform('p_rupa_directory'); } catch(DirectoryRedirect) {}
check($settings['directory_switch']==='0','unchecked toggle disables user switching');
$before=$settings; $_POST=['directory_layout'=>'../../file','directory_switch'=>'1']; $rejected=false;
try { handle_appearance_platform('p_rupa_directory'); } catch(DomainException){ $rejected=true; }
check($rejected && $settings===$before,'invalid admin choice rejected without changing settings');
check(directory_title_size()==='sederhana','title size defaults to medium (kept when form omits it)');
$_POST=['directory_layout'=>'kad','directory_title_size'=>'kecil'];
try { handle_appearance_platform('p_rupa_directory'); } catch(DirectoryRedirect) {}
check($settings['directory_title_size']==='kecil' && directory_title_size()==='kecil','admin sets small title size');
$before=$settings; $_POST=['directory_layout'=>'kad','directory_title_size'=>'raksasa']; $rejected=false;
try { handle_appearance_platform('p_rupa_directory'); } catch(DomainException){ $rejected=true; }
check($rejected && $settings===$before,'invalid title size rejected');
echo "\n$passed directory checks passed.\n";
