<?php
// Only for PHP's local development server. Production serves public_html/index.php.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(preg_match('~^/assets/([a-z0-9_-]+/)?[a-zA-Z0-9_.-]+$~D',$path) && !str_contains($path,'..') && is_file(__DIR__.$path)) return false;
if($path==='/install.php' && is_file(__DIR__.'/install.php')) { require __DIR__.'/install.php'; return true; }
if($path==='/sihat.php') { require __DIR__.'/sihat.php'; return true; }
require __DIR__.'/index.php';
