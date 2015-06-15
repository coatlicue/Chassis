<?php
namespace Chassis\Parser;

include_once __DIR__."/Scanner.php";

class ScannerDriver implements IScanner
{
	/**
	 * สตริงที่จะสแกน
	 * @var string
	 */
	public $str;
	
	/**
	 * scanner ที่จะถูกขับเคลื่อน
	 * @var Scanner
	 */
	public $child;
	
	/**
	 * ตำแหน่งเริ่มต้น
	 * @var int
	 */
	public $position = -1;
	
	/**
	 * error ล่าสุดที่เกิดจากการทำงานของ scanner
	 * @var Error
	 */
	public $last_error;
	
	public $current_line = 1;
	public $current_offset = -1;
	
	public function start()
	{
		$this->child->reset();
		$this->child->initialize();
		//สั่งให้ scanner เคลื่อนไปยังตำแหน่งท้ายสุดของสตริง
		$this->child->advance_to(strlen($this->str) - 1);
		$this->child->finalize();
		if($this->child->state === SC_STATE_DEAD)
		{
			$this->last_error = $this->child->error;
			return false;
		}
		else
		{
			return $this->child->summarize();
		}
	}
	
	public function get_char_at($i)
	{
		if($i < 0) return SC_BEGIN;
		else if($i > strlen($this->str) - 1) return SC_END;
		else return $this->str[$i];
	}
	
	public static function _test_start()
	{
		$scd = new ScannerDriver();
		$sc = new TestScanner($scd);
		
		$scd->str = "abcde";
		assert($scd->start() !== false, "#1 drive test");
		
		$scd->str = "fgh#i";
		assert($scd->start() === false, "#2 drive test");
		assert($scd->last_error === "err", "#2 error check");
		
	}
}