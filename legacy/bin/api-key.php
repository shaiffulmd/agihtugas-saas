<?php
// Run locally from a terminal. Never exposed as an HTTP endpoint.
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/database.php';
$command=$argv[1]??'help';
if($command==='create') {
 $name=trim($argv[2]??''); $scope=$argv[3]??'read';
 if($name===''||mb_strlen($name)>100||!in_array($scope,['read','write'],true)) { fwrite(STDERR,"Usage: php bin/api-key.php create 'Nama sistem' [read|write]\n"); exit(1); }
 $key='agt_'.bin2hex(random_bytes(32));
 q('INSERT INTO api_keys(name,token_hash,prefix,scope) VALUES (?,?,?,?)',[$name,hash('sha256',$key),substr($key,0,12),$scope]);
 $id=(int)$db->lastInsertId();
 q('INSERT INTO history(description) VALUES (?)',["API key #$id diwujudkan untuk $name ($scope)."]);
 echo "API key #$id ($scope). Simpan sekarang; nilai penuh tidak disimpan dan tidak boleh dipaparkan semula.\n$key\n";
} elseif($command==='list') {
 echo json_encode(q('SELECT id,name,prefix,scope,created_at,last_used_at,revoked_at FROM api_keys ORDER BY id')->fetchAll(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} elseif($command==='revoke') {
 $id=filter_var($argv[2]??null,FILTER_VALIDATE_INT);
 if(!$id||!q('SELECT id FROM api_keys WHERE id=?',[$id])->fetch()) { fwrite(STDERR,"API key tidak ditemui.\n"); exit(1); }
 q('UPDATE api_keys SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP) WHERE id=?',[$id]);
 q('INSERT INTO history(description) VALUES (?)',["API key #$id dibatalkan."]);
 echo "API key #$id dibatalkan.\n";
} else {
 echo "AgihTugas API keys\n  php bin/api-key.php create 'Nama sistem' [read|write]\n  php bin/api-key.php list\n  php bin/api-key.php revoke ID\n";
}
