<?php
namespace Chassis\Intermediate;

const BLOCKNODE_ROOT = 1;

const EVALNODE_NO_HTML_ESCAPE = 0b1;

include_once __DIR__."/Context.php";

/**
 * ���ʹ�����繵��᷹�ͧ��觵�ҧ� ����ҡ�����ŵ ���� �硾���� �Ծ��������ͧ���� {...} ��Т�ͤ���
 * @author acer-pc
 *
 */
abstract class ExecutionNode
{
	/**
	 * ⹴�дѺ����٧���仨ҡ⹴���
	 * @var ExecutionNode
	 */
	public $parent;
	/**
	 * ������⹴���ӧҹ
	 * @return string
	 */
	public abstract function execute();
}
/**
 * ���ʹ���繵��᷹�ͧ��ͤ��� �������� language construct
 * @author acer-pc
 *
 */
class TextNode extends ExecutionNode
{
	/**
	 * ��ͤ����ͧ⹴���
	 * @var string
	 */
	public $text;
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\Implementation\ExecutionNode::execute()
	 */
	public function execute()
	{
		return $this->text;
	}
	/**
	 * ��¹��ͤ�������ŧ�⹴���
	 * @param string $str
	 */
	public function write($str)
	{
		$this->text .= $str;
	}
}
/**
 * ���ʹ���繵��᷹�ͧ⹴ {...}
 * @author acer-pc
 *
 */
class EvaluationNode extends ExecutionNode
{
	/**
	 * �纹Ծ���ͧ⹴���
	 * @var Expression
	 */
	public $expression;
	/**
	 * modifier �ͧ⹴��� ����
	 * EVALNODE_NO_HTML_ESCAPE : �������ա����������ѡ��о���ɢͧ HTML
	 * @var int
	 */
	public $modifier;
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\ExecutionNode::execute()
	 */
	public function execute()
	{
		$res = strval($this->expression->calculate());
		if(!($this->modifier & EVALNODE_NO_HTML_ESCAPE))
		{
			$res = htmlspecialchars($res);
		}
		return $res;
	}
	/**
	 *
	 * @param Expression $exp �Ծ����Ш�⹴���
	 */
	public function __construct($exp, $modifier)
	{
		$this->expression = $exp;
		$this->modifier = $modifier;
	}
}
/**
 * �繵��᷹�ͧ��(���͡) ����� �� {@if} {@for}
 * @author acer-pc
 *
 */
class BlockNode extends ExecutionNode
{
	/**
	 * ���ͺਡ��ͧ����觻�ШӺ��͡���
	 * �����˵� : ��Ŵ��� ����ö��˹������ BLOCKNODE_ROOT �� �ҡ�ͺਡ������⹴�ҡ
	 * @var BlockInstruction
	 */
	public $block_instruction;
	/**
	 * ����¡�� header �ͧ���͡�����ٻ key => value
	 * @var array
	 */
	public $headers = [];
	/**
	 * ��⹴����� �ͧ���͡���
	 * @var ExecutionNodeList
	*/
	public $children;
	/**
	 *
	 * @param BlockInstruction $instruction ����觻�ШӺ��͡���
	 */
	public function __construct($instruction)
	{
		$this->children = new ExecutionNodeList();
		$this->block_instruction = $instruction;
	}
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\ExecutionNode::execute()
	 */
	public function execute()
	{
		if($this->block_instruction !== BLOCKNODE_ROOT)
		{
			Context::enter_block();
			$ret = call_user_func($this->block_instruction->operation
					, $this->headers, $this->children);
			Context::exit_block();
			return $ret;
		}
		else
		{
			return $this->children->execute_all();
		}
	}
	/**
	 * �����δ����
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function add_header($key, $value)
	{
		$this->headers[$key] = $value;
	}
	/**
	 * ����⹴����
	 * @param ExecutionNode $node
	 */
	public function add_child($node)
	{
		$node->parent = $this;
		$this->children->add($node);
	}
}
/**
 * ����¡�âͧ execution node
 * @author acer-pc
 *
 */
class ExecutionNodeList
{
	/**
	 * �� execution node
	 * @var array
	 */
	public $list = [];
	/**
	 * ������⹴���¡�÷ӧҹ�ء��� ��Ф׹��Ҽš�÷ӧҹ
	 * @return string
	*/
	public function execute_all()
	{
		$ret = "";
		foreach($this->list as $node)
		{
			$ret .= $node->execute();
		}
		return $ret;
	}
	/**
	 * ����⹴ŧ���¡��
	 * @param ExecutionNode $node
	 */
	public function add($node)
	{
		array_push($this->list, $node);
	}
}