<?php
use Chassis\Parser\ExecutionScanner;
use Chassis\Parser\ScannerDriver;
use Chassis\Intermediate\Context;
use Chassis\Intermediate as I;
include_once __DIR__.'/../Parser/ExecutionParserImplemented.php';
include_once __DIR__.'/../Intermediate/Context.php';

$driver = new ScannerDriver();
$sc = new ExecutionScanner($driver);

//----test #1 : if test----
$driver->str = "123{@if true}456{/@if}789";
$r = $driver->start()->execute();
assert($r === "123456789", "#1.1 if test");

$driver->str = "123{@if false}456{/@if}789";
$r = $driver->start()->execute();
assert($r === "123789", "#1.2 if test");

//----test #2 : else test----
$driver->str = "123{@if false}456{/@if}{@else}789{/@else}ABC";
$r = $driver->start()->execute();
assert($r === "123789ABC", "#2.1 else test");

$driver->str = "123{@if true}456{/@if}{@else}789{/@else}ABC";
$r = $driver->start()->execute();
assert($r === "123456ABC", "#2.2 else test");

//----test #3 : elseif test----
$driver->str = "123{@if false}456{/@if}{@elseif false}789{/@elseif}{@else}ABC{/@else}DEF";
$r = $driver->start()->execute();
assert($r === "123ABCDEF", "#3.1 else test");

$driver->str = "123{@if false}456{/@if}{@elseif true}789{/@elseif}{@else}ABC{/@else}DEF";
$r = $driver->start()->execute();
assert($r === "123789DEF", "#3.2 else test");

$driver->str = "123{@if true}456{/@if}{@elseif true}789{/@elseif}{@else}ABC{/@else}DEF";
$r = $driver->start()->execute();
assert($r === "123456DEF", "#3.3 else test");

//----test #4 : for test----
$driver->str = "{@for i from 1 to 9}{i}{/@for}";
$r = $driver->start()->execute();
assert($r === "123456789", "#4.1 for test");

//----test #5 : foreach test----
Context::set_var(I\VAR_CHANNEL_NORMAL, "array", ["key1" => "value1", "key2" => "value2", "key3" => "value3"], true);
$driver->str = "{@foreach key : value in array}{key}:{value},{/@foreach}";
$r = $driver->start()->execute();
assert($r === "key1:value1,key2:value2,key3:value3,", "#5.1 for test");