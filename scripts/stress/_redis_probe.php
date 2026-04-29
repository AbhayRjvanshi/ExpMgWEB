<?php

$r = new Redis();
$r->connect('127.0.0.1', 6379);
$r->del('lock:test:raw');

echo "UPPERCASE" . PHP_EOL;
var_dump($r->set('lock:test:raw', 'a', ['NX', 'EX' => 8]));
var_dump($r->set('lock:test:raw', 'b', ['NX', 'EX' => 8]));

$r->del('lock:test:raw');
echo "LOWERCASE" . PHP_EOL;
var_dump($r->set('lock:test:raw', 'c', ['nx', 'ex' => 8]));
var_dump($r->set('lock:test:raw', 'd', ['nx', 'ex' => 8]));
