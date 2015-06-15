<?php
namespace Chassis\Intermediate;
/**
 * คลาสนี้เป้นตัวแทนของ Identifier
 * @author acer-pc
 *
 */
class Identifier
{
	private $name;

	public function __construct($name)
	{
		$this->name = $name;
	}

	public function get_name()
	{
		if($this->name instanceof Expression)
		{
			return $this->name->calculate();
		}
		else
		{
			return $this->name;
		}
	}
}