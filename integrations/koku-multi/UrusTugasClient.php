<?php
declare(strict_types=1);
namespace UrusTugas\Integration;

/**
 * Klien pelayan untuk API v1 UrusTugas (berbilang sekolah). Satu objek = SATU sekolah:
 *   - $schoolUrl ialah URL utama sekolah di UrusTugas, cth. https://urustugas.my/CEA1234 atau https://cea1234.urustugas.my
 *   - $apiKey (ut_…) dicipta oleh pentadbir sekolah itu di UrusTugas → API & Integrasi; kunci sekolah lain ditolak (401).
 * Klien ini berdiri sendiri (tiada kebergantungan pada kod UrusTugas atau sistem pemanggil). Jangan hantar kunci ke JavaScript pelayar.
 */
final class UrusTugasClient {
    private string $base;
    public function __construct(string $schoolUrl,private string $apiKey,private int $timeout=20) {
        $u=parse_url($schoolUrl);
        if(!$u || !isset($u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment']) || !in_array($u['scheme']??'',['http','https'],true)) throw new \InvalidArgumentException('URL sekolah UrusTugas tidak sah.');
        if($u['scheme']==='http' && !in_array($u['host'],['127.0.0.1','localhost','[::1]'],true) && !str_ends_with($u['host'],'.localhost')) throw new \InvalidArgumentException('Gunakan HTTPS untuk pelayan selain localhost.');
        $path=rtrim($u['path']??'','/');
        if($path!=='' && !preg_match('#^/[A-Za-z]{3}[0-9]{4}$#D',$path)) throw new \InvalidArgumentException('Laluan URL mesti /KOD sekolah (cth. /CEA1234) atau kosong untuk subdomain sekolah.');
        if($path==='' && !preg_match('/^[a-z]{3}[0-9]{4}\./i',$u['host'])) throw new \InvalidArgumentException('URL tanpa /KOD mesti subdomain sekolah (cth. https://cea1234.domain).');
        if(!preg_match('/^ut_[a-f0-9]{64}$/D',$apiKey)) throw new \InvalidArgumentException('Kunci API UrusTugas tidak sah (ut_…).');
        $this->base=$u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'').$path.'/api/v1';
    }
    /** @return array{0:int,1:string,2:array<string,string>} [status, badan, pengepala] */
    private function http(string $method,string $endpoint,?string $json,string $accept): array {
        $headers=['Accept: '.$accept,'Authorization: Bearer '.$this->apiKey]; $resp=[];
        $opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$this->timeout,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>function($h,$line) use(&$resp){ if(str_contains($line,':')){ [$k,$v]=explode(':',$line,2); $resp[strtolower(trim($k))]=trim($v); } return strlen($line); }];
        if($json!==null){ $headers[]='Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS]=$json; }
        $opt[CURLOPT_HTTPHEADER]=$headers;
        $h=curl_init($this->base.$endpoint); curl_setopt_array($h,$opt); $raw=curl_exec($h); $status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE); unset($h);
        if($raw===false) throw new \RuntimeException('Sambungan UrusTugas gagal. Tiada ulangan automatik dibuat.');
        // Kunci Bearer tidak dihantar semula ke lokasi lain: lencongan bermaksud URL yang dikonfigurasi bukan bentuk utama sekolah.
        if($status>=300 && $status<400) throw new \RuntimeException('UrusTugas melencong ke '.($resp['location']??'?').' — gunakan URL utama sekolah itu dalam konfigurasi.',$status);
        return [$status,(string)$raw,$resp];
    }
    public function request(string $method,string $endpoint,?array $body=null): array {
        if(!preg_match('#^/(teachers|positions|assignments|summary|school|letters|allocation|workflow)(?:[/?][A-Za-z0-9_/?&=%:.,-]*)?$#D',$endpoint)) throw new \InvalidArgumentException('Endpoint tidak sah.');
        [$status,$raw]=$this->http($method,$endpoint,$body!==null?json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):null,'application/json');
        try { $r=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch(\JsonException) { throw new \RuntimeException('Respons UrusTugas bukan JSON yang sah (HTTP '.$status.').',$status); }
        if($status<200 || $status>=300){ $code=$r['error']['code']??'http_error'; throw new UrusTugasError("UrusTugas HTTP $status ($code), request_id=".($r['request_id']??'-'),$status,$code,(string)($r['error']['message']??'')); }
        if(!is_array($r) || !array_key_exists('data',$r)) throw new \RuntimeException('Struktur respons UrusTugas tidak sah.');
        return $r;
    }
    public function summary(): array { return $this->request('GET','/summary')['data']; }
    public function school(): array { return $this->request('GET','/school')['data']; }
    public function workflowStatus(): array { return $this->request('GET','/workflow')['data']; }
    public function publishedAllocation(): array { return $this->request('GET','/workflow/published')['data']; }
    public function publishedLetter(int $teacherId): array { return $this->request('GET','/letters/'.$teacherId)['data']; }
    public function allocationRules(): array { return $this->request('GET','/allocation/rules')['data']; }
    public function previewAllocation(string $mode='vacant'): array { return $this->request('POST','/allocation/preview',['mode'=>$mode])['data']; }
    public function applyAllocation(string $planId): array { return $this->request('POST','/allocation/apply',['plan_id'=>$planId])['data']; }
    public function bulkPositions(array $rows): array { return $this->request('POST','/positions/bulk',['rows'=>$rows])['data']; }
    public function assign(int $positionId,int $teacherId): array { return $this->request('PUT','/positions/'.$positionId.'/assignment',['teacher_id'=>$teacherId])['data']; }
    public function teacherAssignments(int $teacherId): array { return $this->request('GET','/teachers/'.$teacherId.'/assignments')['data']; }
    /** Segerak profil guru daripada sistem lain. $externalId stabil & unik dalam sekolah, cth. "koku-multi:{school_id}:{user_id}". */
    public function syncTeacher(string $externalId,string $name,string $email='',?int $capacity=null,?string $specialty=null): array {
        if(!preg_match('/^[\x21-\x7e]{1,160}$/',$externalId)) throw new \InvalidArgumentException('external_id tidak sah.');
        $d=['external_id'=>$externalId,'name'=>$name,'email'=>$email];
        if($capacity!==null) $d['capacity']=$capacity;          // guru baharu tanpa kapasiti = 25 mata; guru sedia ada dikekalkan
        if($specialty!==null) $d['specialty']=$specialty;
        return $this->request('POST','/teachers/sync',$d)['data'];
    }
    /** Pembantu koku-multi: baris teacher_options() (id, name, email) → external_id "koku-multi:{sid}:{id}". */
    public function syncKokuTeacher(int $kokuSchoolId,array $row): array {
        if($kokuSchoolId<1 || !isset($row['id']) || (int)$row['id']<1) throw new \InvalidArgumentException('ID sekolah dan guru koku-multi diperlukan.');
        return $this->syncTeacher('koku-multi:'.$kokuSchoolId.':'.(int)$row['id'],(string)$row['name'],(string)($row['email']??''));
    }
    public function all(string $resource): array {
        if(!in_array($resource,['teachers','positions','assignments'],true)) throw new \InvalidArgumentException('Resource tidak sah.');
        $rows=[]; $page=1;
        do { $r=$this->request('GET','/'.$resource.'?per_page=100&page='.$page); array_push($rows,...$r['data']); $page++; } while($page<=($r['meta']['pages']??0));
        return $rows;
    }
    /** PNG logo / Jata Negara yang dirujuk oleh school.branding dalam surat muktamad. */
    public function assetBytes(string $assetId): string {
        if(!preg_match('/^[a-f0-9]{64}$/D',$assetId)) throw new \InvalidArgumentException('ID imej tidak sah.');
        [$status,$bytes]=$this->http('GET','/assets/'.$assetId,null,'image/png');
        if($status!==200 || !str_starts_with($bytes,"\x89PNG\r\n\x1a\n")) throw new \RuntimeException('Imej UrusTugas tidak dapat dimuat turun.',$status);
        return $bytes;
    }
}

/** Ralat API dengan kod stabil (cth. workflow_locked, pro_required, duplicate_external_id, record_in_use, unauthorized). */
final class UrusTugasError extends \RuntimeException {
    public function __construct(string $message,int $status,public readonly string $apiCode,public readonly string $apiMessage){ parent::__construct($message,$status); }
}
