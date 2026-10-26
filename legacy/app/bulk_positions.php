<?php
declare(strict_types=1);
function bulk_positions_validate(array $rows): array {
 if(!array_is_list($rows)||!$rows||count($rows)>500) throw new AllocationError('Sediakan antara 1 hingga 500 baris jawatan.');
 $seen=[]; foreach(q('SELECT name,category FROM positions')->fetchAll() as $p) $seen[mb_strtolower(trim($p['name'])).'|'.$p['category']]=true;
 $result=[];
 foreach($rows as $i=>$row) {
  $line=$i+1;
  if(!is_array($row)||array_diff(array_keys($row),['name','category','weight','count'])) throw new AllocationError("Baris $line: medan tidak sah.");
  $name=$row['name']??null; $category=$row['category']??null;
  if(!is_string($name)||trim($name)===''||mb_strlen(trim($name))>145) throw new AllocationError("Baris $line: nama jawatan diperlukan (maksimum 145 aksara).");
  if(!in_array($category,CATEGORIES,true)) throw new AllocationError("Baris $line: bidang mesti Pentadbiran, Kurikulum, HEM atau Kokurikulum.");
  $weight=allocation_int($row['weight']??null,"Baris $line: pemberat",1,10);
  $count=allocation_int($row['count']??1,"Baris $line: bilangan",1,50);
  for($n=1;$n<=$count;$n++) {
   $title=trim($name).($count>1?" ($n/$count)":''); $key=mb_strtolower($title).'|'.$category;
   if(isset($seen[$key])) throw new AllocationError("Baris $line: jawatan '$title' sudah wujud atau berulang dalam senarai.");
   $seen[$key]=true; $result[]=['name'=>$title,'category'=>$category,'weight'=>$weight];
   if(count($result)>1000) throw new AllocationError('Maksimum 1,000 tempat lantikan bagi satu import.');
  }
 }
 return $result;
}
function bulk_positions_parse(string $text): array {
 if(strlen($text)>131072) throw new AllocationError('Fail / teks pukal maksimum 128 KB.');
 $text=preg_replace('/^\xEF\xBB\xBF/','',$text);
 $first=strtok($text,"\r\n"); $delimiter=str_contains((string)$first,"\t")?"\t":',';
 $stream=fopen('php://temp','r+'); fwrite($stream,$text); rewind($stream); $rows=[]; $firstRow=true;
 try {
  while(($row=fgetcsv($stream,0,$delimiter,'"',''))!==false) {
   if(count($row)===1&&trim($row[0]??'')==='') continue;
   $row=array_map('trim',$row);
   if($firstRow&&in_array(array_map('strtolower',$row),[['name','category','weight','count'],['name','category','weight']],true)) { $firstRow=false; continue; }
   $firstRow=false;
   if(count($row)<3||count($row)>4) throw new AllocationError('Gunakan lajur name, category, weight dan count (pilihan).');
   if(!ctype_digit($row[2])||(isset($row[3])&&$row[3]!==''&&!ctype_digit($row[3]))) throw new AllocationError('Pemberat dan bilangan mesti nombor bulat.');
   $rows[]=['name'=>$row[0],'category'=>$row[1],'weight'=>(int)$row[2],'count'=>isset($row[3])&&$row[3]!==''?(int)$row[3]:1];
   if(count($rows)>500) throw new AllocationError('Maksimum 500 baris bagi satu import.');
  }
 } finally { fclose($stream); }
 return bulk_positions_validate($rows);
}
function bulk_positions_insert(array $validated): array {
 global $db;
 $ids=[]; foreach($validated as $p) { q('INSERT INTO positions(name,category,weight) VALUES (?,?,?)',array_values($p)); $ids[]=(int)$db->lastInsertId(); }
 return $ids;
}
