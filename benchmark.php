<?php
use Chassis\Parser\ExecutionScanner;
use Chassis\Parser\KeywordNode;
use Chassis\Parser\ScannerDriver;
use Chassis\Intermediate\Context;
use Chassis\Intermediate as I;
include_once "Parser/ExecutionParserImplemented.php";
include_once "Intermediate/Context.php";

$arr = [];
for($i=1; $i<=10000; $i++)
{
	array_push($arr, "$i");
}
Context::set_var(I\VAR_CHANNEL_NORMAL, "arr", $arr, true);

$d = new ScannerDriver();
$d->str = "{@foreach i in arr}{i}{/@foreach}";
/*
for($i=1; $i<=10000; $i++)
{
	Context::set_var(I\VAR_CHANNEL_NORMAL, "var$i", "a", true);
}

for($i=1; $i<=10000; $i++)
{
	$d->str .= "{var".$i."},";
}

echo "Length: ".strlen($d->str)."<br>";
*/

$s = new ExecutionScanner($d);

$time = microtime(true);
$n = $d->start();
echo microtime(true) - $time . "<br>";

$time = microtime(true);
echo $n->execute()."<br>";
echo microtime(true) - $time."<br>";

echo memory_get_usage();

/*
$time = microtime(true);
$ser = gzcompress(serialize($n));
echo "Length: ".strlen($ser)."<br>";
echo microtime(true) - $time . "<br>";

$time = microtime(true);
$n2 = unserialize(gzuncompress($ser));
$n2->execute();
echo microtime(true) - $time . "<br>";
*/