<?php
use Chassis\Parser\ExecutionScanner;
use Chassis\Parser\ScannerDriver;
include_once 'Parser/ExecutionParserImplemented.php';

$driver = new ScannerDriver();
$sc = new ExecutionScanner($driver);

//----test #1 : execute if blockfÒ
$driver->str = "123{@if true}456{/@if}789";
$r = $driver->start()->execute();
assert($r === "123456789", "")
