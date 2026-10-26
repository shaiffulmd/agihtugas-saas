<?php
declare(strict_types=1);
namespace AgihTugas\Integration;

/** Server-side client; instantiate per school. Never send the API key to browser JavaScript. */
final class AgihTugasClient {
 private string $base;
 public function __construct(string $baseUrl,private string $apiKey) {
  $url=parse_url($baseUrl);
  if(!$url||!isset($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!in_array($url['scheme']??'',['http','https'],true)) throw new \InvalidArgumentException('URL AgihTugas tidak sah.');
  if($url['scheme']==='http'&&!in_array($url['host'],['127.0.0.1','localhost','[::1]'],true)) throw new \InvalidArgumentException('Gunakan HTTPS untuk pelayan selain localhost.');
  if(!preg_match('/^agt_[a-f0-9]{64}$/D',$apiKey)) throw new \InvalidArgumentException('API key AgihTugas tidak sah.');
  $this->base=rtrim($baseUrl,'/');
 }
 public function request(string $method,string $endpoint,?array $body=null): array {
  if(!preg_match('#^/(teachers|positions|assignments|summary|school|letters|allocation|workflow)(?:[/?].*)?$#D',$endpoint)||str_contains($endpoint,"\r")||str_contains($endpoint,"\n")) throw new \InvalidArgumentException('Endpoint tidak sah.');
  $headers=['Accept: application/json','Authorization: Bearer '.$this->apiKey];
  $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS];
  if($body!==null) { $headers[]='Content-Type: application/json'; $options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR); }
  $options[CURLOPT_HTTPHEADER]=$headers;
  $handle=curl_init($this->base.'/api/v1'.$endpoint); curl_setopt_array($handle,$options);
  $raw=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE); unset($handle);
  if($raw===false) throw new \RuntimeException('Sambungan AgihTugas gagal. Tiada ulangan automatik dibuat.');
  try { $result=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch(\JsonException) { throw new \RuntimeException('Respons AgihTugas bukan JSON yang sah.'); }
  if($status<200||$status>=300) { $code=$result['error']['code']??'http_error'; $request=$result['request_id']??'-'; throw new \RuntimeException("AgihTugas HTTP $status ($code), request_id=$request",$status); }
  if(!is_array($result)||!array_key_exists('data',$result)) throw new \RuntimeException('Struktur respons AgihTugas tidak sah.');
  return $result;
 }
 public function previewAllocation(string $mode='vacant'): array { return $this->request('POST','/allocation/preview',['mode'=>$mode])['data']; }
 public function applyAllocation(string $planId): array { return $this->request('POST','/allocation/apply',['plan_id'=>$planId])['data']; }
 public function bulkPositions(array $rows): array { return $this->request('POST','/positions/bulk',['rows'=>$rows])['data']; }
 public function allocationRules(): array { return $this->request('GET','/allocation/rules')['data']; }
 public function workflowStatus(): array { return $this->request('GET','/workflow')['data']; }
 public function publishedAllocation(): array { return $this->request('GET','/workflow/published')['data']; }
 public function publishedLetter(int $teacherId): array { return $this->request('GET','/letters/'.$teacherId)['data']; }
 public function assetBytes(string $assetId): string {
  if(!preg_match('/^[a-f0-9]{64}$/D',$assetId)) throw new \InvalidArgumentException('ID imej tidak sah.');
  $handle=curl_init($this->base.'/api/v1/assets/'.$assetId);
  curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: image/png','Authorization: Bearer '.$this->apiKey]]);
  $bytes=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE); unset($handle);
  if($status!==200||!is_string($bytes)||!str_starts_with($bytes,"\x89PNG\r\n\x1a\n")) throw new \RuntimeException('Imej AgihTugas tidak dapat dimuat turun.', $status);
  return $bytes;
 }
 public function summary(): array { return $this->request('GET','/summary')['data']; }
 public function syncTeacher(int $schoolId,array $teacher): array {
  if($schoolId<1||!isset($teacher['id'])||!is_numeric($teacher['id'])||(int)$teacher['id']<1) throw new \InvalidArgumentException('ID sekolah dan guru diperlukan.');
  // koku-multi teacher_options(): id, name, email, role; no invented capacity or specialty on updates.
  $data=['external_id'=>'koku-multi:'.$schoolId.':'.(int)$teacher['id'],'name'=>$teacher['name'],'email'=>$teacher['email']??''];
  return $this->request('POST','/teachers/sync',$data)['data'];
 }
 public function assign(int $positionId,int $teacherId): array { return $this->request('PUT','/positions/'.$positionId.'/assignment',['teacher_id'=>$teacherId])['data']; }
 public function teacherAssignments(int $teacherId): array { return $this->request('GET','/teachers/'.$teacherId.'/assignments')['data']; }
 public function all(string $resource): array {
  if(!in_array($resource,['teachers','positions','assignments'],true)) throw new \InvalidArgumentException('Resource tidak sah.');
  $rows=[]; $page=1;
  do { $result=$this->request('GET','/'.$resource.'?per_page=100&page='.$page); array_push($rows,...$result['data']); $page++; } while($page<=($result['meta']['pages']??0));
  return $rows;
 }
}
