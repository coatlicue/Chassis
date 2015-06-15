<?php
use Chassis\Parser\ExecutionScanner;
use Chassis\Parser\KeywordNode;
use Chassis\Parser\ScannerDriver;
include_once "Parser/ExecutionParserImplemented.php";

$d = new ScannerDriver();
for($i=1; $i<=1000; $i++)
{
	$d->str .= "{".$i."},";
}

echo "Length: ".strlen($d->str)."<br>";

$s = new ExecutionScanner($d);

$time = microtime(true);
$n = $d->start();
echo microtime(true) - $time . "<br>";

$time = microtime(true);
$ser = gzcompress(serialize($n));
echo "Length: ".strlen($ser)."<br>";
echo microtime(true) - $time . "<br>";

$time = microtime(true);
$n2 = unserialize(gzuncompress($ser));
$n2->execute();
echo microtime(true) - $time . "<br>";