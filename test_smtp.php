<?php
$e=0; $s='';
$c = fsockopen('smtp.gmail.com', 587, $e, $s, 10);
echo "Port 587: " . ($c ? 'Connected OK' : 'FAILED: '.$s) . "\n";

$e=0; $s='';
$c = fsockopen('smtp.gmail.com', 465, $e, $s, 10);
echo "Port 465: " . ($c ? 'Connected OK' : 'FAILED: '.$s) . "\n";
