<?php
// PHP development server: serve existing static files; route API and UI to index.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
$file=realpath(__DIR__.$path);
if($path!=='/' && !str_starts_with($path,'/api') && $file && str_starts_with($file,__DIR__.DIRECTORY_SEPARATOR) && is_file($file)) return false;
require __DIR__.'/index.php';
