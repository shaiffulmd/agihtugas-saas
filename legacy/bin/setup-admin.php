<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/database.php';
if(q('SELECT COUNT(*) FROM accounts')->fetchColumn()) { fwrite(STDERR,"Akaun telah tersedia. Log masuk sebagai pentadbir untuk mengurus akaun.\n"); exit(1); }
$code=bin2hex(random_bytes(16));
q('INSERT INTO setup_codes VALUES(1,?,?) ON CONFLICT(id) DO UPDATE SET code_hash=excluded.code_hash,expires_at=excluded.expires_at',[hash('sha256',$code),time()+1800]);
echo "Kod persediaan pentadbir (sah 30 minit, sekali guna):\n$code\nBuka /?page=setup dan tetapkan e-mel serta kata laluan sendiri.\n";
