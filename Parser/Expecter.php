<?php
namespace Chassis\Parser;

include_once __DIR__."/ExpectationTreeNode.php";
//include_once "Scanner.php";
//include_once "ScannerDriver.php";

const EXP_STATE_FAILED = 0;
const EXP_STATE_EXPECTING = 1;
const EXP_STATE_SUCCEED = 2;

class Expecter
{
	/**
	 * initiation node ของ expectation tree ที่ต้องการจะให้ตรวจจับ
	 * @var ExpectationTreeNode
	 */
	public $expectation_tree;
	
	/**
	 * scanner ที่เป็นเจ้าของ
	 * @var Scanner
	 */
	public $scanner;
	
	/**
	 * สตริงที่กำลังจับได้
	 * @var string
	 */
	public $consumed_string;
	/**
	 * ข้อมูลล่าสุดที่ผูกติดกับ string ที่อ่านได้
	 *  @var mixed
	 */
	public $last_tag;
	
	/**
	 * สถานะ (ได้แก่ EXP_STATE_FAILED, EXP_STATE_EXPECTING, EXP_STATE_SUCCEED)
	 * @var int
	 */
	public $state;
	
	/**
	 * expectation tree ที่กำลังถูกพิจารณาอยู่
	 * @var ExpectationTreeNode
	 */
	private $current_expectation_tree;
	
	/**
	 * @param Scanner $scanner scanner ที่เป็นเจ้าของ
	 */
	public function __construct($scanner)
	{
		$this->scanner = $scanner;
	}
	/**
	 * สั่งให้เคลื่อนไปข้างหน้า
	 */
	public function advance()
	{
		if($this->expectation_tree === null)
		{
			$this->report(EXP_STATE_FAILED);
			return;
		}
		if($this->current_expectation_tree === null)
			$this->current_expectation_tree = $this->expectation_tree;
		if(($c = $this->scanner->get_current_char()) === SC_BEGIN || $c === SC_END)
		{
			$this->report(EXP_STATE_FAILED);
			return;
		}
			
		//ถ้าพบตัวอักษร ณ ตำแหน่งปัจจุบัน เป็น node หนึ่งๆ ของ expectation tree ก็เลือกโนดนั้นขึ้นมาทำงานได้เลย
		if(($child = $this->current_expectation_tree->retrieve(ETN_TYPE_CHARACTER, $c))
				!== false)
		{
			//ตรวจสอบโนดย่อยลงไปอีก
			if(count($child->children) === 1 && $child->children[0]->type === ETN_TYPE_TERMINATION)
			{
				$this->report(EXP_STATE_SUCCEED, $child->children[0]->tag);
			}
			else
			{
				$next_char = $this->scanner->peek_ahead();
				if($child->retrieve(ETN_TYPE_CHARACTER, $next_char))
				{
					$this->report(EXP_STATE_EXPECTING);
				}
				else if($term = $child->retrieve(ETN_TYPE_TERMINATION))
				{
					$this->report(EXP_STATE_SUCCEED, $term->tag);
				}
				else
				{
					$this->report(EXP_STATE_FAILED);
				}
			}
		}
		else
		{
			$this->report(EXP_STATE_FAILED);
		}
	}
	/**
	 * สั่งให้จบการคาดหมาย
	 * หากสถานะของ Expecter ยังคงเป็น expecting เมื่อเรียกใช้เมธอดนี้แล้ว สถานะจะเปลี่ยนเป็น failed ทันที
	 */
	public function finalize()
	{
		if($this->state === EXP_STATE_EXPECTING)
			$this->state = EXP_STATE_FAILED;
	}
	
	/**
	 * ตั้งสถานะ และรายงานสตริงที่ตรวจจับไว้ได้
	 * @param int $state
	 */
	private function report($state, $tag = null)
	{
		if($state !== EXP_STATE_EXPECTING)
		{
			//กลับไปที่โนดเริ่มต้น
			$this->current_expectation_tree = $this->expectation_tree;
		}
		else
		{
			//ไปยังโนดตัวอักษรถัดไป
			$this->current_expectation_tree = 
				$this->current_expectation_tree->retrieve
					(ETN_TYPE_CHARACTER, $this->scanner->get_current_char());
		}
		
		if($this->state !== EXP_STATE_EXPECTING)
		{
			$this->consumed_string = "";
		}
		
		$c = $this->scanner->get_current_char();
		if(is_string($c)) $this->consumed_string .= $c;
		$this->state = $state;
		$this->last_tag = $tag;
	}
	
	public function _test_report()
	{
		$this->expectation_tree = ExpectationTreeNode::create(["if", "ifelse", "ixl"]);
		
		//----test #1----
		//กำหนดสภาวะแวดล้อมเป็น:
		//current_exp_tree = i -> [f, x]
		//current_char = x
		//consumed_string = i
		//state = expecting
		$this->current_expectation_tree = $this->expectation_tree->retrieve(ETN_TYPE_CHARACTER, "i");
		//กำหนดตัวอักษรปัจจุบันเป็น x
		$this->scanner->current_char = "x";
		$this->consumed_string = "i";
		$this->state = EXP_STATE_EXPECTING;
		//และ report ด้วยสถานะ expecting
		$this->report(EXP_STATE_EXPECTING);
		//ถ้าถูกต้อง จะได้:
		//expectation tree = x -> [l]
		//consumed_string = ix;
		//state = expecting
		assert($this->current_expectation_tree->type === ETN_TYPE_CHARACTER
				&& $this->current_expectation_tree->char === "x", "#1 exp tree advancing");
		assert($this->consumed_string === "ix", "#1 check consumed string");
		assert($this->state = EXP_STATE_EXPECTING, "#1 check state");
		
		//----test #2----
		//กำหนดสภาวะแวดล้อมเป็น
		//current_exp_tree = x -> [l]
		//current_char = l
		//consumed_string = ix
		//state = expecting
		$this->current_expectation_tree = Parser\ExpectationTreeNode::create(["xl"])->retrieve(ETN_TYPE_CHARACTER, "x");
		$this->scanner->current_char = "l";
		$this->consumed_string = "ix";
		$this->state = EXP_STATE_EXPECTING;
		//แล้วทำการ report
		$this->report(EXP_STATE_FAILED);
		//ถ้าถูกต้อง จะต้องได้
		//consumed_string = ixl
		//current_exp_tree = root
		//state = failed
		assert($this->current_expectation_tree === $this->expectation_tree,
				"#2 current exp tree returned to root");
		assert($this->consumed_string === "ixl", "#2 check consumed string");
		assert($this->state === EXP_STATE_FAILED, "#2 check state");
		
		//----test #3----
		//กำหนดสภาวะแวดล้อมเป็น
		//current_char = a
		//consumed_string = ixl
		//state = failed
		//current_exp_tree = null
		$this->scanner->current_char = "a";
		$this->consumed_string = "ixl";
		$this->state = EXP_STATE_FAILED;
		$this->current_expectation_tree = null;
		//แล้ว report ด้วยสถานะ succeed
		$this->report(EXP_STATE_SUCCEED);
		//ถ้าถูกต้อง จะได้
		//consumed_string = a
		//current_exp_tree = root
		//state = succeed
		assert($this->consumed_string === "a", "#3 check consumed string");
		assert($this->current_expectation_tree === $this->expectation_tree, "#3 current exp tree returned to root");
		assert($this->state === EXP_STATE_SUCCEED, "#3 check state");
	}
	
	public function _test_expect($str, $exp_list)
	{	
		$exp_tree = ExpectationTreeNode::create($exp_list);
		$this->expectation_tree = $exp_tree;
		$states = [];
		
		for($i=0; $i<=strlen($str)-1; $i++)
		{
			$exp_tree = $exp_tree->retrieve(ETN_TYPE_CHARACTER, $str[$i]);
			
			//----test #$i----
			//กำหนด
			//current_char = $str[$i]
			//next_char = $str[$i + 1]
			$this->scanner->current_char = $str[$i];
			if($i !== strlen($str)-1)
				$this->scanner->next_char = $str[$i+1];
			else
				$this->scanner->next_char = "#";
			//สั่งให้เคลื่อนไปข้างหน้า
			$this->scanner->advance();
			//เก็บค่า state ไว้
			array_push($states, $this->state);
			//หากสถานะเป็น final state ให้หยุดทำงาน
			if($this->state === EXP_STATE_FAILED || $this->state === EXP_STATE_SUCCEED)
				break;
			//ถ้าทำงานถูกต้อง exp_tree ปัจจุบันต้องเป็นของตัวอักษรปัจจุบัน
			assert($this->current_expectation_tree === $exp_tree, "#$i exp tree advanced.");
		}
		
		//วนลูปตรวจสอบสถานะที่เก็บไว้
		echo "states: ";
		foreach($states as $s)
		{
			echo $s;
		}
	}
	
	public static function _test_expect_null()
	{
		//ทดสอบว่า ถ้าไม่ได้กำหนด exp tree ให้แก่ expecter แล้ว สถานะของ expecter จะเป็น failed ตลอดหรือไม่
		$e = new Expecter(new DummyScanner());
		$e->expectation_tree = null;
		$e->scanner->current_char = "a";
		$e->scanner->next_char = "b";
		$e->scanner->advance();
		assert($e->state === EXP_STATE_FAILED, "test expecter on null tree");
	}
	
	//ทดสอบการติด tag ในการจับ string ของ expecter
	public static function _test_tag()
	{
		$driver = new ScannerDriver();
		$driver->str = "abcac";
		
		$sc = new TestBlankScanner($driver);
		$e = new Expecter($sc);
		$e->expectation_tree = ExpectationTreeNode::create(["a" => "#1", "bc" => "#2", "ac" => "#3"]);
		
		$sc->initialize();
		
		$sc->advance();
		assert($e->last_tag === "#1", "#1 tag check");
		$sc->advance_by(2);
		assert($e->last_tag === "#2", "#2 tag check");
		$sc->advance_by(2);
		assert($e->last_tag === "#3", "#3 tag check");
	}
}

class DummyScanner
{
	public $current_char;
	public $next_char;
	public $on_advanced = [];
	
	public function get_current_char()
	{
		return $this->current_char;
	}
	
	public function peek_ahead()
	{
		return $this->next_char;
	}
	
	public function advance()
	{
		foreach ($this->on_advanced as $c)
		{
			$c();
		}
	}
}

/**
class TestBlankScanner extends Scanner
{
	public function __construct($parent)
	{
		parent::__construct($parent);
		}
	
	protected function _scan()
	{
	}
	
	protected function _summarize()
	{
	}
}
*/