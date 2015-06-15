<?php
namespace Chassis\Parser;

use Exception;

include_once __DIR__.'/Scanner.php';
include_once __DIR__.'/ScannerDriver.php';

define('STATE_PRE_INTERMEDIATE',1);
define('STATE_POST_INTERMEDIATE',2);

class State
{
	/**
	 * เก็บ callable สำหรับทำงาน โดยจะรับพารามิเตอร์ดังนี้
	 * 1. Transition จะรับ Transition ที่เป็นตัวส่งมายัง State นี้
     * 2. Expectation_Result จะรับผลการคาดหมายสัญลักษณ์ ประกอบด้วยฟีลด์ succeed(ระบุว่าคาดหมายสำเร็จหรือไม่) และ symbol(สัญลักษณ์ที่จับไว้ได้)
     * ก. สามารถย้ายไปทำงานยัง state อื่นได้โดยสร้างออบเจกต์ transition ลงในฟิลด์ next transition และกำหนดปลายทาง และ message
     * ข. สามารถทำงานกับ scanner ได้เฉพาะ suicide และดึงตัวอักษร เท่านั้น
	 * @var callable
	 */
	public $operation;
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
}

class Transition
{
	/**
	 * State ต้นทาง
	 * @var State
	 */
	public $source;
	/**
	 * State ปลายทาง
	 * @var State
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
	 * @param State $destination state ปลายทาง
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
	 * state เริ่มต้น
	 * @var State
	 */
	protected $initial_state;
	/**
	 * transition ที่จะส่งไปให้ state ในรอบการทำงานต่อไป
	 * @var Transition
	 */
	protected $next_transition;
	/**
	 * ระบุว่าจะให้เริ่มทำงานก่อนที่จะเคลื่อนที่ไปยังตัวอักษรตัวแรกของ string หรือไม่
	 * @var bool
	 */
	
	protected function _scan()
	{
		if($this->state === SC_STATE_INITIALIZING)
		{
			if(!($this->initial_state instanceof State)) throw new Exception("The initial state is not yet defined.");
			$this->next_transition = new Transition($this->initial_state);
			$this->expecter->expectation_tree = $this->initial_state->expectation_tree;
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
				
				$state = $trans->destination;
				$state->next_transition = null;
				call_user_func($state->operation, $trans, $exp_res);
				
				//หลังจาก state ทำงานเสร็จ
				if($state->next_transition !== null)
				{
					$state->next_transition->source = $state;
					$state->next_transition->first = true;
					$this->next_transition = $state->next_transition;
					$this->expecter->expectation_tree = $this->next_transition->destination->expectation_tree;
				
					//ถ้า state นี้เป็น pre-intermediate หรือ state ถัดไปเป็น post intermediate ให้เรียก state ถัดไปขึ้นมาทำงานทันที
					if($this->get_next_state()->intermediate_mode === STATE_POST_INTERMEDIATE
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
	 * เรียกดูสถานะถัดไปที่จะถูกเรียกใช้
	 * @return \Chassis\Parser\State
	 */
	public function get_next_state()
	{
		return $this->next_transition->destination;
	}
}

class TestStateScanner extends StateScanner
{
	private $summary;
	
	public function __construct($parent)
	{
		parent::__construct($parent);
		
		$A = new State();
		$B = new State();
		$C = new State();
		
		$A->expectation_tree = ExpectationTreeNode::create(["("]);
		$A->operation = function($transition, $exp_result) use ($A, $B)
		{
			if($exp_result['symbol'] == "(")
			{
				$A->next_transition = new Transition($B);
			}
			else
			{
				$this->summary .= "A";
				if($this->state === SC_STATE_FINALIZING)
				{
					$this->summary .= "$";
				}
			}
		};
		
		$B->expectation_tree = ExpectationTreeNode::create([")", "["]);
		$B->operation = function($transition, $exp_result) use ($B, $A, $C)
		{
			if($exp_result['symbol'] == ")")
			{
				$B->next_transition = new Transition($A);
			}
			else if($exp_result['symbol'] == "[")
			{
				$B->next_transition = new Transition($C);
			}
			else
			{
				$this->summary .= "B";
			}
		};
		
		$C->expectation_tree = ExpectationTreeNode::create(["]"]);
		$C->operation = function($transition, $exp_result) use ($C, $B)
		{
			if($exp_result['symbol'] == "]")
			{
				$C->next_transition = new Transition($B);
			}
			else 
			{
				if($transition->first) $this->summary .= "!";
				$this->summary .= "C";
			}
		};
		
		$this->initial_state = $A;
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

class TestStateScanner2 extends StateScanner
{
	private $summ = "";
	
	public function __construct($parent)
	{
		parent::__construct($parent);
		
		$A = new State();
		$B = new State();
		$C = new State();
		
		$A->intermediate_mode = STATE_PRE_INTERMEDIATE;
		$A->operation = function() use ($A, $B) 
			{ $this->summ .= "A"; $A->next_transition = new Transition($B); };
		
		$B->intermediate_mode = 0;
		$B->operation = function() use ($B, $C) 
			{ $this->summ .= "B"; $B->next_transition = new Transition($C); };
		
		$C->intermediate_mode = STATE_POST_INTERMEDIATE;
		$C->operation = function() use ($C, $A)
			{ $this->summ .= "C"; $C->next_transition = new Transition($A); };
		
		$this->initial_state = $A;
	}
	
	public function _summarize()
	{
		return $this->summ;
	}
	
	public static function _test_intermediate_state()
	{
		$scd = new ScannerDriver();
		$scd->str = "!!!";
		
		$sc = new TestStateScanner2($scd);
	
		assert($scd->start() === "ABCABCABCABC", "test intermediate state");
	}
}