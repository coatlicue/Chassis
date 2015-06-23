<?php
namespace Chassis\Parser;

use Exception;
use Chassis\Intermediate\Cursor;

include_once __DIR__.'/Scanner.php';
include_once __DIR__.'/ScannerDriver.php';
include_once __DIR__.'/../Intermediate/Cursor.php';

define('STATE_PRE_INTERMEDIATE',1);
define('STATE_POST_INTERMEDIATE',2);

abstract class State
{
	/**
	 * กำหนดการทำงานของ state ให้กับเมธอดนี้
	 * ก. สามารถย้ายไปทำงานยัง state อื่นได้โดยสร้างออบเจกต์ transition ลงในฟิลด์ next transition และกำหนดปลายทาง และ message
     * ข. สามารถทำงานกับ scanner ได้เฉพาะ suicide และดึงตัวอักษร เท่านั้น
	 * @param Transition $transition Transition ที่ส่งมายัง state นี้
	 * @param array $exp_result อาร์เรย์เก็บข้อมูลเกี่ยวกับการคาดหมายสัญลักษณ์ ประกอบด้วยคีย์ต่อไปนี้ : succeed, symbol, tag
	 */
	protected abstract function operation($transition, $exp_result);
	/**
	 * expectation tree สำหรับคาดหมายสัญลักษณ์ก่อนที่จะเข้าสู่ state นี้
	 * @var ExpectationTreeNode
	 */
	public $expectation_tree;
	/**
	 * กำหนด transition ที่จะส่งไปยัง state ถัดไป (กำหนดโดยส่วนของ operation เท่านั้น)
	 * @var Transition
	 */
	public $next_transition;
	/**
	 * ระบุว่า state นี้เป็น intermediate state (state ที่จะทำงานแทรก state อื่นๆ ก่อนที่ scanner จะเคลื่อนไปข้างหน้า)
	 * มีค่าดังนี้
	 * STATE_PRE_INTERMEDIATE : จะทำงานหลังจาก scanner เคลื่อนไปข้างหน้า หลังจากที่ state ที่เป็น intermediate ชนิดนี้ทำงานเสร็จ จะเรียก state ถัดไปขึ้นมาทำงานทันที
	 * STATE_POST_INTERMEDIATE : state ชนิดนี้จะทำงานทันทีเมื่อ state อื่นส่ง transition มายัง state นี้ โดยไม่ต้องรอให้ scanner เคลื่อนที่ไปข้างหน้า
	 * หากไม่ตั้งค่าเป็น intermediate state ให้ระบุเป็น 0
	 * @var unknown
	 */
	public $intermediate_mode = 0;
	/**
	 * ระบุตำแหน่งเริ่มต้นที่ state นี้เริ่มทำงาน
	 * @var Cursor
	 */
	public $start_cursor;
	/**
	 * ระบุ scanner ที่เป็นเจ้าของ state นี้
	 * @var StateScanner
	 */
	public $scanner;
	/**
	 * สำหรับให้ scanner เรียก state นี้ขึ้นมาทำงาน
	 * @param Transition $transition
	 * @param array $exp_result
	 */
	public function enter($transition, $exp_result)
	{
		if($transition->first)
		{
			$this->start_cursor = clone $this->scanner->cursor;
		}
		$this->operation($transition, $exp_result);
	}
}

class Transition
{
	/**
	 * State ต้นทาง
	 * @var State
	 */
	public $source;
	/**
	 * เลขที่ของ State ปลายทาง
	 * @var int
	 */
	public $destination;
	/**
	 * ข้อมูลที่จะส่งไปให้ state ปลายทาง
	 * @var mixed
	 */
	public $data;
	/**
	 * ระบุว่า transition นี้ส่งมายัง state เป็นครั้งแรกหรือไม่
	 * @var bool
	 */
	public $first;
	
	/**
	 * 
	 * @param int $destination เลขที่ของ state ปลายทาง
	 * @param mixed $data ข้อมูลที่จะส่งไปให้ state ปลายทาง
	 */
	public function __construct($destination, $data = null)
	{
		$this->destination = $destination;
		$this->data = $data;
	}
}

abstract class StateScanner extends Scanner
{
	/**
	 * กำหนดเลขที่ของ state เริ่มต้น
	 * @var int
	 */
	protected $initial_state = 0;
	/**
	 * transition ที่จะส่งไปให้ state ในรอบการทำงานต่อไป
	 * @var Transition
	 */
	protected $next_transition;
	/**
	 * เก็บอาร์เรย์ของ เลข state จับคู่กับ object ของ state
	 * @var array
	 */
	protected $state_list = [];
	
	protected function _scan()
	{
		if($this->state === SC_STATE_INITIALIZING)
		{
			if(!array_key_exists($this->initial_state, $this->state_list))
				throw new Exception("Initial state is not yet defined.");
			$this->next_transition = new Transition($this->initial_state);
			$this->next_transition->first = true;
			$this->expecter->expectation_tree = $this->state_list[$this->initial_state]->expectation_tree;
		}
		else
		{
			if($this->expecter->state !== EXP_STATE_EXPECTING)
			{
				$trans = $this->next_transition;
				$exp_res = [
					'succeed' => ($this->expecter->state === EXP_STATE_SUCCEED),
					'symbol' => $this->expecter->consumed_string,
					'tag' => $this->expecter->last_tag,
				];
				
				$state = $this->state_list[$trans->destination];
				$state->next_transition = null;
				$state->enter($trans, $exp_res);
				
				//หลังจาก state ทำงานเสร็จ
				if($state->next_transition !== null)
				{
					$state->next_transition->source = $state;
					$state->next_transition->first = true;
					$this->next_transition = $state->next_transition;
					$this->expecter->expectation_tree = $this->state_list[$this->next_transition->destination]->expectation_tree;
				
					//ถ้า state นี้เป็น pre-intermediate หรือ state ถัดไปเป็น post intermediate ให้เรียก state ถัดไปขึ้นมาทำงานทันที
					if($this->state_list[$this->get_next_state()]->intermediate_mode === STATE_POST_INTERMEDIATE
							|| $state->intermediate_mode === STATE_PRE_INTERMEDIATE)
					{
						$this->_scan();	
					}
				}
				else
				{
					$this->next_transition->first = false;
				}
			}
		}
	}
	/**
	 * เรียกดูเลขที่ของ state ถัดไปที่จะถูกเรียกใช้
	 * @return \Chassis\Parser\State
	 */
	public function get_next_state()
	{
		return $this->next_transition->destination;
	}
	/**
	 * เพิ่ม state ลงในสารบบ
	 * @param int $id เลขที่ของ state
	 * @param State $state object ของ state
	 */
	public function add_state($id, $state)
	{
		$state->scanner = $this;
		$this->state_list[$id] = $state;
	}
}

class TestStateScanner extends StateScanner
{
	public $summary;
	
	public function __construct($parent)
	{
		parent::__construct($parent);
		
		$this->add_state(1, new TestStateScanner_A());
		$this->add_state(2, new TestStateScanner_B());
		$this->add_state(3, new TestStateScanner_C());
		
		$this->initial_state = 1;
	}
	
	protected function _summarize()
	{
		return $this->summary;
	}
	
	public static function _test()
	{
		$sc = new TestStateScanner(new ScannerDriver());
		$sc->parent->str = "000(00[0000]0[000]00)0";
		$res = $sc->parent->start();
		assert($res === "AAABB!CCCCB!CCCBBAA$", "state scanner test");
	}
}

class TestStateScanner_A extends State
{
	public function __construct()
	{
		$this->expectation_tree = ExpectationTreeNode::create(["("]);
	}
	
	public function operation($transition, $exp_result)
	{
		if($exp_result['symbol'] == "(")
		{
			$this->next_transition = new Transition(2);
		}
		else
		{
			$this->scanner->summary .= "A";
			if($this->scanner->state === SC_STATE_FINALIZING)
			{
				$this->scanner->summary .= "$";
			}
		}
	}
}

class TestStateScanner_B extends State
{
	public function __construct()
	{
		$this->expectation_tree = ExpectationTreeNode::create([")", "["]);
	}
	
	public function operation($transition, $exp_result)
	{
		if($exp_result['symbol'] == ")")
		{
			$this->next_transition = new Transition(1);
		}
		else if($exp_result['symbol'] == "[")
		{
			$this->next_transition = new Transition(3);
		}
		else
		{
			$this->scanner->summary .= "B";
		}
	}
}

class TestStateScanner_C extends State
{
	public function __construct()
	{
		$this->expectation_tree = ExpectationTreeNode::create(["]"]);
	}
	
	public function operation($transition, $exp_result)
	{
		if($exp_result['symbol'] == "]")
		{
			$this->next_transition = new Transition(2);
		}
		else
		{
			if($transition->first) $this->scanner->summary .= "!";
			$this->scanner->summary .= "C";
		}
	}
}

class TestIntermediateStateScanner extends StateScanner
{
	public $summ = "";
	
	public function __construct($parent)
	{
		parent::__construct($parent);
		
		$this->add_state(1, new TestIntermediateStateScanner_A());
		$this->add_state(2, new TestIntermediateStateScanner_B());
		$this->add_state(3, new TestIntermediateStateScanner_C());
		
		$this->initial_state = 1;
	}
	
	public function _summarize()
	{
		return $this->summ;
	}
	
	public static function _test_intermediate_state()
	{
		$scd = new ScannerDriver();
		$scd->str = "!!!";
		
		$sc = new TestIntermediateStateScanner($scd);
	
		assert($scd->start() === "ABCABCABCABC", "test intermediate state");
	}
}

class TestIntermediateStateScanner_A extends State
{
	public function __construct()
	{
		$this->intermediate_mode = STATE_PRE_INTERMEDIATE;
	}
	
	public function operation($transition, $exp_result)
	{
		$this->scanner->summ .= "A"; 
		$this->next_transition = new Transition(2);
	}
}

class TestIntermediateStateScanner_B extends State
{
	public function operation($transition, $exp_result)
	{
		$this->scanner->summ .= "B";
		$this->next_transition = new Transition(3);
	}
}

class TestIntermediateStateScanner_C extends State
{
	public function __construct()
	{
		$this->intermediate_mode = STATE_POST_INTERMEDIATE;
	}
	
	public function operation($transition, $exp_result)
	{
		$this->scanner->summ .= "C"; 
		$this->next_transition = new Transition(1);
	}
}

class TestStateScanner_StartCursor extends StateScanner
{
	public $summ = [];
	
	public function __construct($parent)
	{
		parent::__construct($parent);
		
		$this->add_state(1, new TestStateScanner_StartCursor_A());
		$this->add_state(2, new TestStateScanner_StartCursor_B());
		
		$this->initial_state = 1;
	}
	
	public function _summarize()
	{
		return $this->summ;
	}
	
	public static function _test_start_cursor()
	{
		//start at state A.
		//when state A encounters 1, it will transfer to state B.
		//when state B encounters 0, it will transfer to state A.
		
		$scd = new ScannerDriver();
		$scd->str = "00011111100";
		
		$sc = new TestStateScanner_StartCursor($scd);
		
		$res = $scd->start();
		
		echo "TEST StateScanner: start cursor.";
		assert($res[0]->position === 0, "#1");
		assert($res[1]->position === 3, "#2");
		assert($res[2]->position === 9, "#3");
	}
}

class TestStateScanner_StartCursor_A extends State
{
	public function operation($transition, $exp_result)
	{
		if($transition->first) array_push($this->scanner->summ, $this->start_cursor);
		if($this->scanner->peek_ahead() === "1")
		{
			$this->next_transition = new Transition(2);
		}
	}
}

class TestStateScanner_StartCursor_B extends State
{
	public function operation($transition, $exp_result)
	{
		if($transition->first)
		{
			array_push($this->scanner->summ, $this->start_cursor);
		}
		if($this->scanner->peek_ahead() === "0")
		{
			$this->next_transition = new Transition(1);
		}
	}
}