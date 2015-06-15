<?php
namespace Chassis\Parser;

include_once __DIR__."/StateScanner.php";

interface ITreeNode
{
	public function set_parent($node);
	public function get_parent();
	public function add_child($node);
}

const TRAVDIR_UPWARD = 0;
const TRAVDIR_DOWNWARD = 1;

class TraversalDirection
{
	/**
	 * ��ȷҧ
	 * @var int
	 */
	public $direction;
	/**
	 * ⹴�١��������͹ŧ���
	 * @var ITreeNode
	 */
	public $child;
	
	/**
	 * 
	 * @param int $direction ��ȷҧ ���� TRAVDIR_UPWARD ��� TRAVDIR_DOWNWARD
	 * @param ITreeNode $child ⹴�١��������͹ŧ��� (�óշ���˹� direction = TRAVDIR_DOWNWARD)
	 */
	public function __construct($direction, $child = null)
	{
		$this->direction = $direction;
		$this->child = $child;
	}
}

class TreeTransition extends Transition
{
	/**
	 * ��ȷҧ��÷�ͧ���
	 * @var TraversalDirection
	 */
	public $traversal_direction;
	
	/**
	 * 
	 * @param State $destination state ���·ҧ
	 * @param TraversalDirection $traversal_direction ��ȷҧ��÷�ͧ���
	 * @param mixed $data �����ŷ��������� state ���·ҧ
	 */
	public function __construct($destination, $traversal_direction = null, $data = null)
	{
		$this->traversal_direction = $traversal_direction;
		parent::__construct($destination, $data);
	}
}

abstract class TreeStateScanner extends StateScanner
{
	/**
	 * ⹴�ҡ
	 * @var ITreeNode
	 */
	public $root_node;
	/**
	 * ⹴�Ѩ�غѹ�����ѧ�ӧҹ����
	 * @var ITreeNode
	 */
	public $current_node;
	
	protected function _scan()
	{
		parent::_scan();
		if($this->state === SC_STATE_INITIALIZING)
		{
			$this->current_node = $this->root_node;
		}
		else
		{
			if($this->next_transition instanceof TreeTransition
					&& $this->next_transition->traversal_direction !== null)
			{
				$dir = $this->next_transition->traversal_direction;
				switch($dir->direction)
				{
					case TRAVDIR_DOWNWARD:
						$child = $dir->child;
						$this->current_node->add_child($child);
						$child->set_parent($this->current_node);
						$this->current_node = $child;
						break;
					case TRAVDIR_UPWARD:
						$this->current_node = $this->current_node->get_parent();
						break;
				}
				$this->next_transition->traversal_direction = null;
			}	
		}
	}
}

class TestTreeNode implements ITreeNode
{
	public $children = [];
	public $parent;
	
	public function add_child($node)
	{
		array_push($this->children, $node);
	}
	
	public function get_parent()
	{
		return $this->parent;
	}
	
	public function set_parent($node)
	{
		$this->parent = $node;
	}
}

class TestTreeStateScanner extends TreeStateScanner
{
	public function __construct($parent)
	{
		parent::__construct($parent);
		
		$A = new State();
		$A->expectation_tree = ExpectationTreeNode::create(["(", ")"]);
		$A->operation = function($trans, $exp_res) use ($A)
		{
			if($this->state === SC_STATE_FINALIZING) return;
			if($exp_res['symbol'] === "(")
			{
				$A->next_transition = new TreeTransition($A, new TraversalDirection(TRAVDIR_DOWNWARD, new TestTreeNode()));
			}
			else if($exp_res['symbol'] === ")")
			{
				$A->next_transition = new TreeTransition($A, new TraversalDirection(TRAVDIR_UPWARD));
			}
			else
			{
				$this->current_node->add_child($exp_res['symbol']);
			}
		};
		
		$this->initial_state = $A;
	}
	
	public function _summarize()
	{
		return $this->root_node;
	}
	
	public static function _test()
	{
		$sc = new TestTreeStateScanner(new ScannerDriver());
		$sc->parent->str = "abc(def(ghi)jkl)mno";
		$sc->root_node = new TestTreeNode();
		$r = $sc->parent->start();
		
		assert($r->children[0] === "a", "#1 char test");
		assert($r->children[1] === "b", "#2 char test");
		assert($r->children[2] === "c", "#3 char test");
		assert($r->children[3]->children[0] === "d", "#4 char test");
		assert($r->children[3]->children[1] === "e", "#5 char test");
		assert($r->children[3]->children[2] === "f", "#6 char test");
		assert($r->children[3]->children[3]->children[0] === "g", "#7 char test");
		assert($r->children[3]->children[3]->children[1] === "h", "#8 char test");
		assert($r->children[3]->children[3]->children[2] === "i", "#9 char test");
		assert($r->children[3]->children[4] === "j", "#10 char test");
		assert($r->children[3]->children[5] === "k", "#11 char test");
		assert($r->children[3]->children[6] === "l", "#12 char test");
		assert($r->children[4] === "m", "#13 char test");
		assert($r->children[5] === "n", "#14 char test");
		assert($r->children[6] === "o", "#15 char test");
	}
}