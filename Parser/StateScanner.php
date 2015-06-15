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
	 * �� callable ����Ѻ�ӧҹ �¨��Ѻ����������ѧ���
	 * 1. Transition ���Ѻ Transition ����繵�������ѧ State ���
     * 2. Expectation_Result ���Ѻ�š�äҴ�����ѭ�ѡɳ� ��Сͺ���¿�Ŵ� succeed(�к���ҤҴ����������������) ��� symbol(�ѭ�ѡɳ���Ѻ�����)
     * �. ����ö����价ӧҹ�ѧ state ����������ҧ�ͺਡ�� transition ŧ㹿�Ŵ� next transition ��С�˹����·ҧ ��� message
     * �. ����ö�ӧҹ�Ѻ scanner ��੾�� suicide ��д֧����ѡ�� ��ҹ��
	 * @var callable
	 */
	public $operation;
	/**
	 * expectation tree ����Ѻ�Ҵ�����ѭ�ѡɳ��͹���������� state ���
	 * @var ExpectationTreeNode
	 */
	public $expectation_tree;
	/**
	 * ��˹� transition ��������ѧ state �Ѵ� (��˹�����ǹ�ͧ operation ��ҹ��)
	 * @var Transition
	 */
	public $next_transition;
	/**
	 * �к���� state ����� intermediate state (state ���зӧҹ�á state ���� ��͹��� scanner ������͹仢�ҧ˹��)
	 * �դ�Ҵѧ���
	 * STATE_PRE_INTERMEDIATE : �зӧҹ��ѧ�ҡ scanner ����͹仢�ҧ˹�� ��ѧ�ҡ��� state ����� intermediate ��Դ���ӧҹ���� �����¡ state �Ѵ仢���ҷӧҹ�ѹ��
	 * STATE_POST_INTERMEDIATE : state ��Դ���зӧҹ�ѹ������� state ����� transition ���ѧ state ��� ������ͧ����� scanner ����͹���仢�ҧ˹��
	 * �ҡ����駤���� intermediate state ����к��� 0
	 * @var unknown
	 */
	public $intermediate_mode = 0;
}

class Transition
{
	/**
	 * State �鹷ҧ
	 * @var State
	 */
	public $source;
	/**
	 * State ���·ҧ
	 * @var State
	 */
	public $destination;
	/**
	 * �����ŷ��������� state ���·ҧ
	 * @var mixed
	 */
	public $data;
	/**
	 * �к���� transition ��������ѧ state �繤����á�������
	 * @var bool
	 */
	public $first;
	
	/**
	 * 
	 * @param State $destination state ���·ҧ
	 * @param mixed $data �����ŷ��������� state ���·ҧ
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
	 * state �������
	 * @var State
	 */
	protected $initial_state;
	/**
	 * transition ���������� state ��ͺ��÷ӧҹ����
	 * @var Transition
	 */
	protected $next_transition;
	/**
	 * �к���Ҩ����������ӧҹ��͹��������͹�����ѧ����ѡ�õ���á�ͧ string �������
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
				
				//��ѧ�ҡ state �ӧҹ����
				if($state->next_transition !== null)
				{
					$state->next_transition->source = $state;
					$state->next_transition->first = true;
					$this->next_transition = $state->next_transition;
					$this->expecter->expectation_tree = $this->next_transition->destination->expectation_tree;
				
					//��� state ����� pre-intermediate ���� state �Ѵ��� post intermediate ������¡ state �Ѵ仢���ҷӧҹ�ѹ��
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
	 * ���¡��ʶҹжѴ价��ж١���¡��
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