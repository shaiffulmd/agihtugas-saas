<?php
function fixture_run(string $command,string $root,array $env): void {
 $proc=proc_open([PHP_BINARY,$root.'/tests/fixtures.php',$command],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$streams,$root,$env);
 fclose($streams[0]); $out=stream_get_contents($streams[1]);$err=stream_get_contents($streams[2]);fclose($streams[1]);fclose($streams[2]);
 if(proc_close($proc)!==0) throw new RuntimeException('Fixture failed: '.$err.$out);
}
