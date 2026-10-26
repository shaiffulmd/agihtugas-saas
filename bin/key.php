<?php
if(PHP_SAPI!=='cli') exit;
echo base64_encode(random_bytes(32)).PHP_EOL;
