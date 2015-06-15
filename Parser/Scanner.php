<?php
namespace Chassis\Parser;

include_once __DIR__."/Expecter.php";
include_once __DIR__."/ExpectationTreeNode.php";

const SC_STATE_IDLE = 1;
const SC_STATE_INITIALIZING = 2;
const SC_STATE_WORKING = 3;
const SC_STATE_DEAD = 4;
const SC_STATE_FINALIZING = 5;
const SC_STATE_FINALIZED = 6;
const SC_STATE_SUMMARIZED = 7;

/**
 * indicates the position before the first char of string.
 * @var int
 */
const SC_BEGIN = -1;
/**
 * indicates the position after the last char of string.
 * @var int
 */
const SC_END = -2;

/**
 * implemented to Scanner and ScannerDriver
 * @author acer-pc
 *
 */
interface IScanner
{
	public function get_char_at($i); 
}

/**
 * เป็นคลาสสำหรับกราดตรวจไปบนสตริง และทำงานตามที่กำหนด
 * ในการใช้ ให้เรียกเมธอด initialize() ก่อน
 * หลังจากใช้งานเสร็จให้เรียกใช้ finalize() และ summarize() ตามลำดับ
 * หากต้องการนำออบเจกต์เดิมมาใช้งานใหม่ ให้เรียกเมธอด reset()
 * @author acer-pc
 *
 */
abstract class Scanner implements IScanner
{
	/**
	 * สถานะ
	 * @var int
	 */
	public $state;
	/**
	 * ตำแหน่งปัจจุบัน
	 * @var int
	 */
	public $position;
	/**
	 * Scanner ที่เป็นแม่
	 * @var IScanner
	 */
	public $parent;
	/**
	 * Scanner ที่เป็นลูก
	 * @var IScanner
	 */
	public $child;
	/**
	 * Error ที่เกิดขึ้นก่อนที่จะเปลี่ยนสถานะเป็น Dead
	 * @var Error
	 */
	public $error;
	/**
	 * @var Expecter
	 */
	protected $expecter;
	/**
	 * เลขบรรทัดปัจจุบัน
	 * @var int
	 */
	public $current_line = 1;
	/**
	 * ตำแหน่งของตัวอักษรปัจจุบันในบรรทัด
	 * @var int
	 */
	public $current_offset = -1;
	
	/**
	 * ส่วนนี้จะใช้สำหรับการกราดตรวจตัวอักษร ซึ่งถูกเรียกขึ้นมาทำงานเมื่อมีการเลื่อนตำแหน่งของ scanner ไป 1 ตำแหน่ง
	 */
	protected abstract function _scan();
	/**
	 * ส่วนนี้ใช้สำหรับสรุปข้อมูลที่วิเคราะห์ได้ จะถูกเรียกขึ้นมาทำงานเมื่อ scanner ถูกสั่งให้สรุป
	 */
	protected abstract function _summarize();
	
	/**
	 * @param IScanner $parent scanner ที่เป็นแม่
	 */
	public function __construct($parent)
	{
		$parent->child = $this;
		$this->parent = $parent;
		$this->expecter = new Expecter($this);
		
		$this->reset();
	}
	
	/**
	 * ดึงตัวอักษร ณ ตำแหน่งปัจจุบัน
	 */
	public function get_current_char()
	{
		return $this->get_char_at($this->position);
	}
	/**
	 * ชำเลืองมองตัวอักษรตัวหน้า
	 */
	public function peek_ahead()
	{
		return $this->get_char_at($this->position + 1);
	}
	
	/**
	 * ดูตัวอักษร ณ ตำแหน่งใดๆ
	 * @param unknown $pos
	 */
	public function get_char_at($pos)
	{
		return $this->parent->get_char_at($pos);
	}
	/**
	 * เลื่อนไปข้างหน้า 1 ตำแหน่ง
	 */
	public function advance()
	{
		if($this->peek_ahead() !== SC_END && $this->state === SC_STATE_WORKING)
		{
			$this->position++;
			//if($this->get_current_char() === "\r") return;
			if($this->get_current_char() === "\n")
			{
				$this->current_line++;
				$this->current_offset = 0;
			}
			else
			{
				$this->current_offset++;
			}
			$this->expecter->advance(); //สั่งให้ expecter เคลื่อนไปข้างหน้า
			$this->_scan();
		}
	}
	/**
	 * เลื่อนไปข้างหน้า n ตำแหน่ง
	 * @param int $n
	 */
	public function advance_by($n)
	{
		for($i=1; $i<=$n; $i++)
		{
			$this->advance();
		}
	}
	/**
	 * เลื่อนไปยังตำแหน่ง i (ไม่สามารถเลื่อนถอยหลังได้)
	 * @param int $i
	 */
	public function advance_to($i)
	{
		$n = $i - $this->position;
		if($n > 0)
		{
			$this->advance_by($n);
		}
	}
	/**
	 * เลื่อนไปยังตำแหน่งเดียวกับ parent scanner
	 */
	public function advance_here()
	{
		$this->advance_to($this->parent->position);
	}
	
	/**
	 * สั่งให้สรุปผล
	 */
	public function summarize()
	{
		if($this->state === SC_STATE_FINALIZED)
		{
			$ret = $this->_summarize();
			$this->state = SC_STATE_SUMMARIZED;
			return $ret;
		}
	}
	
	/**
	 * ตั้งสถานะเป็น Dead
	 */
	public function kill()
	{
		$this->state = SC_STATE_DEAD;
	}
	
	/**
	 * เรียกส่วนกราดตรวจขึ้นมาทำงานครั้งสุดท้าย จะต้องใช้ก่อน summarize เสมอ
	 */
	public function finalize()
	{
		if($this->state === SC_STATE_WORKING)
		{
			$this->state = SC_STATE_FINALIZING;
			$this->expecter->finalize();
			$this->_scan();
			if($this->state !== SC_STATE_DEAD) $this->state = SC_STATE_FINALIZED;
		}
	}
	
	/**
	 * รายงานผลความผิดพลาด
	 * @param IError #error ความผิดพลาดที่จะรายงาน
	 */
	protected function suicide($error)
	{
		$this->error = $error;
		$this->state = SC_STATE_DEAD;
	}
	
	/**
	 * กลับสู่สภาวะเริ่มต้น
	 */
	public function reset()
	{
		if($this->state !== SC_STATE_IDLE)
		{
			$this->child = null;
			$this->error = null;
			$this->position = $this->parent->position;
			$this->state = SC_STATE_IDLE;
			$this->current_line = $this->parent->current_line;
			$this->current_offset = $this->parent->current_offset;
		}
	}
	
	/**
	 * เรียกส่วนตรวจตราขึ้นมาเตรียมตัว
	 */
	public function initialize()
	{
		if($this->state === SC_STATE_IDLE)
		{
			$this->state = SC_STATE_INITIALIZING;
			$this->_scan();
			$this->state = SC_STATE_WORKING;
		}
	}
}
/**
 * เป็นคลาสสำหรับรายงาน error ออกมาจาก scanner
 * @author acer-pc
 *
 */
class Error
{
	/**
	 * เลขบรรทัดที่เกิดความผิดพลาด
	 * @var int
	 */
	public $line;
	/**
	 * ตำแหน่งของตัวอักษรในบรรทัดที่เกิดความผิดพลาด
	 * @var int
	 */
	public $offset;
	/**
	 * รหัสของความผิดพลาด
	 * @var int
	 */
	public $code;
	/**
	 * ข้อความของความผิดพลาด
	 * @var string
	 */
	public $message;
	/**
	 * 
	 * @param int $line เลขบรรทัดที่เกิดความผิดพลาด
	 * @param int $offset ตำแหน่งของตัวอักษรในบรรทัดที่เกิดความผิดพลาด
	 * @param int $code รหัสของความผิดพลาด
	 * @param string $message ข้อความแสดงความผิดพลาด
	 */
	public function __construct($scanner, $code, $message = null)
	{
		$this->line = $scanner->current_line;
		$this->offset = $scanner->current_offset;
		$this->code = $code;
		$this->message = $message;
	}
}

class TestScanner extends Scanner
{
	public $list = [];
	
	protected function _scan()
	{
		if($this->state === SC_STATE_INITIALIZING) return;
		array_push($this->list, [$this->get_current_char(), $this->peek_ahead()]);
		if($this->get_current_char() === "#") $this->suicide("err");
	}
	
	protected function _summarize()
	{
		return $this->list;
	}
	
	public static function _test_scan()
	{
		$str = "abcdef#g";
		$sc = new TestScanner(new DummyScannerDriver($str));
		$sc->initialize();
		//ทดลองเคลื่อนไปข้างหน้า
		$sc->advance();
		//ตรวจสอบค่าที่ได้
		$res = array_pop($sc->list);
		assert($res[0] === "a", "#1.1 scan test");
		assert($res[1] === "b", "#1.2 scan test");
		
		//ทดลองเคลื่อนไปข้างหน้า
		$sc->advance();
		//ตรวจสอบค่าที่ได้
		$res = array_pop($sc->list);
		assert($res[0] === "b", "#2.1 scan test");
		assert($res[1] === "c", "#2.2 scan test");
		
		//ทดลองเคลื่อนไปข้างหน้า
		$sc->advance_by(4);
		//ตรวจสอบค่าที่ได้
		$res = $sc->list[0];
		assert($res[0] === "c", "#3.1 scan test");
		assert($res[1] === "d", "#3.2 scan test");
		$res = $sc->list[1];
		assert($res[0] === "d", "#4.1 scan test");
		assert($res[1] === "e", "#4.2 scan test");
		$res = $sc->list[2];
		assert($res[0] === "e", "#5.1 scan test");
		assert($res[1] === "f", "#5.2 scan test");
		$res = $sc->list[3];
		assert($res[0] === "f", "#6.1 scan test");
		assert($res[1] === "#", "#6.2 scan test");
		
		$sc->advance();
		assert($sc->state === SC_STATE_DEAD, "#7.1 suicide state test");
		assert($sc->error === "err", "#7.2 suicide error obj test");
		
		//----test #8 ทดสอบ expecter----
		$sc = new TestScanner(new DummyScannerDriver("abc<br>de<divf"));
		$sc->initialize();
		$sc->expecter->expectation_tree = ExpectationTreeNode::create(["<br>", "<div>"]);
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "a", "#8.1 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_FAILED, "#8.1 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "b", "#8.2 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_FAILED, "#8.2 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "c", "#8.3 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_FAILED, "#8.3 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<", "#8.4 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_EXPECTING, "#8.4 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<b", "#8.5 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_EXPECTING, "#8.5 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<br", "#8.6 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_EXPECTING, "#8.6 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<br>", "#8.7 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_SUCCEED, "#8.7 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "d", "#8.8 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_FAILED, "#8.7 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "e", "#8.9 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_FAILED, "#8.9 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<", "#8.10 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_EXPECTING, "#8.10 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<d", "#8.11 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_EXPECTING, "#8.11 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<di", "#8.12 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_EXPECTING, "#8.12 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "<div", "#8.13 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_FAILED, "#8.13 expect state");
		
		$sc->advance();
		assert($sc->expecter->consumed_string === "f", "#8.14 expect consumed str");
		assert($sc->expecter->state === EXP_STATE_FAILED, "#8.14 expect state");
	}
}

class TestScannerB extends Scanner
{
	private $summary = "";
	
	protected function _scan()
	{
		$this->summary .= $this->state;
	}
	
	protected function _summarize()
	{
		$this->summary .= "#";
		return $this->summary;
	}
	
	public static function _test_state()
	{
		$str = "abc\r\ndef";
		$s = new TestScannerB(new DummyScannerDriver($str));
		$s->initialize();
		$s->advance_to(strlen($str) - 1);
		$s->finalize();
		assert($s->summarize() === "1222222224#", "scanner state test");
	}
}

class DummyScannerDriver
{
	private $str;
	public $position = -1;
	
	public function __construct($str)
	{
		$this->str = $str;
	}
	
	public function get_char_at($i)
	{
		if($i < 0) return SC_BEGIN;
		else if($i > strlen($this->str) - 1) return SC_END;
		else return $this->str[$i];
	}
}