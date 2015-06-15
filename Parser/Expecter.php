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
	 * initiation node �ͧ expectation tree ����ͧ��è�����Ǩ�Ѻ
	 * @var ExpectationTreeNode
	 */
	public $expectation_tree;
	
	/**
	 * scanner �������Ңͧ
	 * @var Scanner
	 */
	public $scanner;
	
	/**
	 * ʵ�ԧ�����ѧ�Ѻ��
	 * @var string
	 */
	public $consumed_string;
	/**
	 * ����������ش���١�Դ�Ѻ string �����ҹ��
	 *  @var mixed
	 */
	public $last_tag;
	
	/**
	 * ʶҹ� (���� EXP_STATE_FAILED, EXP_STATE_EXPECTING, EXP_STATE_SUCCEED)
	 * @var int
	 */
	public $state;
	
	/**
	 * expectation tree �����ѧ�١�Ԩ�ó�����
	 * @var ExpectationTreeNode
	 */
	private $current_expectation_tree;
	
	/**
	 * @param Scanner $scanner scanner �������Ңͧ
	 */
	public function __construct($scanner)
	{
		$this->scanner = $scanner;
	}
	/**
	 * ����������͹仢�ҧ˹��
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
			
		//��Ҿ�����ѡ�� � ���˹觻Ѩ�غѹ �� node ˹��� �ͧ expectation tree �����͡⹴��鹢���ҷӧҹ�����
		if(($child = $this->current_expectation_tree->retrieve(ETN_TYPE_CHARACTER, $c))
				!== false)
		{
			//��Ǩ�ͺ⹴����ŧ��ա
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
	 * �����騺��äҴ����
	 * �ҡʶҹТͧ Expecter �ѧ���� expecting ��������¡�����ʹ������� ʶҹШ�����¹�� failed �ѹ��
	 */
	public function finalize()
	{
		if($this->state === EXP_STATE_EXPECTING)
			$this->state = EXP_STATE_FAILED;
	}
	
	/**
	 * ���ʶҹ� �����§ҹʵ�ԧ����Ǩ�Ѻ�����
	 * @param int $state
	 */
	private function report($state, $tag = null)
	{
		if($state !== EXP_STATE_EXPECTING)
		{
			//��Ѻ价��⹴�������
			$this->current_expectation_tree = $this->expectation_tree;
		}
		else
		{
			//��ѧ⹴����ѡ�öѴ�
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
		//��˹�������Ǵ������:
		//current_exp_tree = i -> [f, x]
		//current_char = x
		//consumed_string = i
		//state = expecting
		$this->current_expectation_tree = $this->expectation_tree->retrieve(ETN_TYPE_CHARACTER, "i");
		//��˹�����ѡ�ûѨ�غѹ�� x
		$this->scanner->current_char = "x";
		$this->consumed_string = "i";
		$this->state = EXP_STATE_EXPECTING;
		//��� report ����ʶҹ� expecting
		$this->report(EXP_STATE_EXPECTING);
		//��Ҷ١��ͧ ����:
		//expectation tree = x -> [l]
		//consumed_string = ix;
		//state = expecting
		assert($this->current_expectation_tree->type === ETN_TYPE_CHARACTER
				&& $this->current_expectation_tree->char === "x", "#1 exp tree advancing");
		assert($this->consumed_string === "ix", "#1 check consumed string");
		assert($this->state = EXP_STATE_EXPECTING, "#1 check state");
		
		//----test #2----
		//��˹�������Ǵ������
		//current_exp_tree = x -> [l]
		//current_char = l
		//consumed_string = ix
		//state = expecting
		$this->current_expectation_tree = Parser\ExpectationTreeNode::create(["xl"])->retrieve(ETN_TYPE_CHARACTER, "x");
		$this->scanner->current_char = "l";
		$this->consumed_string = "ix";
		$this->state = EXP_STATE_EXPECTING;
		//���Ƿӡ�� report
		$this->report(EXP_STATE_FAILED);
		//��Ҷ١��ͧ �е�ͧ��
		//consumed_string = ixl
		//current_exp_tree = root
		//state = failed
		assert($this->current_expectation_tree === $this->expectation_tree,
				"#2 current exp tree returned to root");
		assert($this->consumed_string === "ixl", "#2 check consumed string");
		assert($this->state === EXP_STATE_FAILED, "#2 check state");
		
		//----test #3----
		//��˹�������Ǵ������
		//current_char = a
		//consumed_string = ixl
		//state = failed
		//current_exp_tree = null
		$this->scanner->current_char = "a";
		$this->consumed_string = "ixl";
		$this->state = EXP_STATE_FAILED;
		$this->current_expectation_tree = null;
		//���� report ����ʶҹ� succeed
		$this->report(EXP_STATE_SUCCEED);
		//��Ҷ١��ͧ ����
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
			//��˹�
			//current_char = $str[$i]
			//next_char = $str[$i + 1]
			$this->scanner->current_char = $str[$i];
			if($i !== strlen($str)-1)
				$this->scanner->next_char = $str[$i+1];
			else
				$this->scanner->next_char = "#";
			//����������͹仢�ҧ˹��
			$this->scanner->advance();
			//�纤�� state ���
			array_push($states, $this->state);
			//�ҡʶҹ��� final state �����ش�ӧҹ
			if($this->state === EXP_STATE_FAILED || $this->state === EXP_STATE_SUCCEED)
				break;
			//��ҷӧҹ�١��ͧ exp_tree �Ѩ�غѹ��ͧ�繢ͧ����ѡ�ûѨ�غѹ
			assert($this->current_expectation_tree === $exp_tree, "#$i exp tree advanced.");
		}
		
		//ǹ�ٻ��Ǩ�ͺʶҹз�������
		echo "states: ";
		foreach($states as $s)
		{
			echo $s;
		}
	}
	
	public static function _test_expect_null()
	{
		//���ͺ��� ���������˹� exp tree ����� expecter ���� ʶҹТͧ expecter ���� failed ��ʹ�������
		$e = new Expecter(new DummyScanner());
		$e->expectation_tree = null;
		$e->scanner->current_char = "a";
		$e->scanner->next_char = "b";
		$e->scanner->advance();
		assert($e->state === EXP_STATE_FAILED, "test expecter on null tree");
	}
	
	//���ͺ��õԴ tag 㹡�èѺ string �ͧ expecter
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