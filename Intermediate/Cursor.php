<?php
namespace Chassis\Intermediate;
/**
 * คลาสนี้จะใช้ระบุตำแหน่งภายใน template
 */
class Cursor
{
	/**
	 * ตำแหน่งจริงของเคอร์เซอร์ เริ่มนับจาก 0
	 * @var int
	 */
	public $position = -1;
	/**
	 * เลขบรรทัด เริ่มนับจาก 1
	 * @var int
	 */
	public $line = 1;
	/**
	 * ตำแหน่งตัวอักษรภายในบรรทัด เริ่มนับจาก 1
	 * @var int
	 */
	public $offset = 0;
	/**
	 * ป้อนตัวอักษร
	 * @param string $char ตัวอักษรเพียงตัวเดียวที่จะป้อนเข้า
	 */
	public function feed($char)
	{
		$this->position++;
		if($char === "\n")
		{
			$this->line++;
			$this->offset = 0;
		}
		else
		{
			$this->offset++;
		}
	}
	
	public static function _test()
	{
		$c = new Cursor();
		
		//----test #1 : feed non-linefeed chars.----
		$c->feed("a");
		$c->feed("b");
		$c->feed("c");
		
		assert($c->position === 2, "#1.1");
		assert($c->offset === 3, "#1.2");
		assert($c->line === 1, "#1.3");
		//----test #2 : feed linefeed char and others.----
		$c->feed("\n");
		$c->feed("x");
		$c->feed("y");
		
		assert($c->position === 5, "#2.1");
		assert($c->offset === 2, "#2.2");
		assert($c->line === 2, "#2.3");
	}
}