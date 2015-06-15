<?php
namespace Chassis\Parser;

include_once __DIR__.'/TreeStateScanner.php';

abstract class Chunk implements ITreeNode
{
	/**
	 * ⹴���
	 * @var ITreeNode
	 */
	private $parent;
	/**
	 * ��ҡ�ͧ chunk ��͹��� (����������С�˹�)
	 * @var int
	 */
	public $label;
	/**
	 * ��÷Ѵ��� chunk �������
	 * @var int
	 */
	public $line;
	/**
	 * ���˹觢ͧ����ѡ��㹺�÷Ѵ��� chunk �������
	 * @var offset
	 */
	public $offset;
	
	/**
	 * 
	 * ����⹴�١
	 * @param Chunk $node ⹴�١����ͧ�������
	 */
	public function add_child($node)
	{
		
	}
	public function set_parent($node)
	{
		$this->parent = $node;
	}
	public function get_parent()
	{
		return $this->parent;
	}
	
	/**
	 * 
	 * @param int $label ��ҡ���Դ���Ѻ chunk ���
	 * @param int $line ��÷Ѵ��� chunk �������
	 * @param int $offset ���˹觢ͧ����ѡ��㹺�÷Ѵ��� chunk �������
	 */
	public function __construct($label, $line, $offset)
	{
		$this->label = $label;
		$this->line = $line;
		$this->offset = $offset;
	}
}

class ValueChunk extends Chunk
{
	/**
	 * ��Ңͧ chunk ���
	 * @var string
	 */
	public $value = "";
	/**
	 * ��¹������������ chunk ���
	 * @param string $value
	 */
	public function write($value)
	{
		$this->value .= $value;
	}
}

class TreeChunk extends Chunk
{
	private $children = [];
	/**
	 * �����ѧ���˹觷����ش�ͧ $children
	 * @var int
	 */
	private $last_child_index = -1;
	/**
	 * �����ѧ���˹�� $children ���ж١�֧����
	 * @var unknown
	 */
	private $next_child_to_get = 0;
	
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\Chunk::add_child()
	 */
	public function add_child($node)
	{
		array_push($this->children, $node);
		$this->last_child_index++;
	}
	
	/**
	 * �֧⹴�١�Ѵ� �������͹���˹�仢�ҧ˹��
	 * @return Chunk
	 * �Ф׹����� false ����Ͷ֧�ش����ش�ͧ chunk
	 */
	public function get_next_child()
	{
		if($r = $this->peek_next_child())
		{
			$this->next_child_to_get++;
			return $r;
		}
		else
		{
			return false;
		}
	}
	/**
	 * �֧⹴�١�֧�������������˹�
	 * @return Chunk
	 * �Ф׹����� false ����Ͷ֧�ش����ش�ͧ chunk
	 */
	public function peek_next_child()
	{
		return $this->get_child_at($this->next_child_to_get);
	}
	/**
	 * �֧⹴�١��Ƿ����ش
	 * @return Chunk
	 */
	public function get_last_child()
	{
		return $this->get_child_at($this->last_child_index);
	}
	
	private function get_child_at($i)
	{
		if($i >= count($this->children))
		{
			return false;
		}
		else
		{
			return $this->children[$i];
		}
	}
	
	public static function _test()
	{
		//���ͧ�����١ ��¹��� ���Ǵ֧�͡��
		$root = new TreeChunk(1, 0, 0);
		$root->add_child(new ValueChunk(2, 0, 0));
		$root->get_last_child()->write("a");
		$root->get_last_child()->write("b");
		$root->get_last_child()->write("c");
		$root->add_child(new ValueChunk(3, 0, 0));
		$root->get_last_child()->write("de");
		$root->get_last_child()->write("f");
		$root->add_child(new ValueChunk(4, 0, 0));
		$root->get_last_child()->write("ghi");
		
		$c = $root->get_next_child();
		assert($c->label === 2, "#1 label test");
		assert($c->value === "abc", "#1 value test");
		$c = $root->get_next_child();
		assert($c->label === 3, "#1 label test");
		assert($c->value === "def", "#2 value test");
		$c = $root->get_next_child();
		assert($c->label === 4, "#1 label test");
		assert($c->value === "ghi", "#3 value test");
	}
}