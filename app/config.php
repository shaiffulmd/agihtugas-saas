<?php
declare(strict_types=1);
// Per-installation configuration is private and never included in release packages.
global $localConfig;
$localConfigFile=dirname(__DIR__).'/config/local.php';
$localConfig=is_file($localConfigFile)?require $localConfigFile:[];
if(!is_array($localConfig)) throw new RuntimeException('Konfigurasi pemasangan tidak sah.');
function app_database_path(): string {
 global $localConfig;
 return getenv('AGIHTUGAS_DB') ?: ($localConfig['database']??dirname(__DIR__).'/storage/agihtugas.sqlite');
}
function app_seed_demo(): bool {
 global $localConfig;
 $env=getenv('AGIHTUGAS_SEED_DEMO');
 return $env!==false ? $env==='1' : ($localConfig['seed_demo']??true);
}
