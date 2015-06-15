<?php
use Chassis\Parser\ExecutionScanner;
use Chassis\Parser\KeywordNode;
use Chassis\Parser\ScannerDriver;
use Chassis\Intermediate\Context;
use Chassis\Intermediate as I;
include_once "Parser/ExecutionParserImplemented.php";
include_once "Intermediate/Context.php";

for($i=1; $i<=100; $i++)
{
	Context::set_var(I\VAR_CHANNEL_NORMAL, "var$i", $i, true);
}

$d = new ScannerDriver();
for($i=1; $i<=100; $i++)
{
	$d->str .= "{var".$i."},";
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