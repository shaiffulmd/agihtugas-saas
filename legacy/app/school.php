<?php
declare(strict_types=1);
const SCHOOL_TYPES=['SK'=>'Sekolah Kebangsaan (SK)','SMK'=>'Sekolah Menengah Kebangsaan (SMK)'];
function school_type(?array $school=null): string {
 $type=$school!==null?($school['school_type']??'SK'):(q('SELECT value FROM settings WHERE key="school_type"')->fetchColumn()?:'SK');
 return isset(SCHOOL_TYPES[$type])?$type:'SK';
}
function school_head_title(?array $school=null): string { return school_type($school)==='SMK'?'Pengetua':'Guru Besar'; }
function school_profile(): array {
 $school=q('SELECT key,value FROM settings WHERE key IN ("school","school_type","principal","year","reference") ORDER BY key')->fetchAll(PDO::FETCH_KEY_PAIR);
 $school['school_type']=school_type($school); $school['principal_title']=school_head_title($school); $school['branding']=branding_profile(); return $school;
}
