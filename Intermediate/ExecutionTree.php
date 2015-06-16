<?php
namespace Chassis\Intermediate;

const BLOCKNODE_ROOT = 1;

const EVALNODE_NO_HTML_ESCAPE = 0b1;

include_once __DIR__."/Context.php";

/**
 * คลาสนี้ใช้เป็นตัวแทนของสิ่งต่างๆ ที่ปรากฎในเทมเพลต ได้แก่ แท็กพิเศษ นิพจน์ในเครื่องหมาย {...} และข้อความ
 * @author acer-pc
 *
 */
abstract class ExecutionNode
{
	/**
	 * โนดระดับที่สูงขึ้นไปจากโนดนี้
	 * @var ExecutionNode
	 */
	public $parent;
	/**
	 * สั่งให้โนดนี้ทำงาน
	 * @return string
	 */
	public abstract function execute();
}
/**
 * คลาสนี้เป็นตัวแทนของข้อความ ที่ไม่ใช่ language construct
 * @author acer-pc
 *
 */
class TextNode extends ExecutionNode
{
	/**
	 * ข้อความของโนดนี้
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
	 * เขียนข้อความเพิ่มลงในโนดนี้
	 * @param string $str
	 */
	public function write($str)
	{
		$this->text .= $str;
	}
}
/**
 * คลาสนี้เป็นตัวแทนของโนด {...}
 * @author acer-pc
 *
 */
class EvaluationNode extends ExecutionNode
{
	/**
	 * เก็บนิพจน์ของโนดนี้
	 * @var Expression
	 */
	public $expression;
	/**
	 * modifier ของโนดนี้ ได้แก่
	 * EVALNODE_NO_HTML_ESCAPE : ไม่ให้มีการเข้ารหัสอักขระพิเศษของ HTML
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
	 * @param Expression $exp นิพจน์ประจำโนดนี้
	 */
	public function __construct($exp, $modifier)
	{
		$this->expression = $exp;
		$this->modifier = $modifier;
	}
}
/**
 * เป็นตัวแทนของแท็ก(บล็อก) พิเศษ เช่น {@if} {@for}
 * @author acer-pc
 *
 */
class BlockNode extends ExecutionNode
{
	/**
	 * เก็บออบเจกต์ของคำสั่งประจำบล็อกนี้
	 * หมายเหตุ : ฟิลด์นี้ สามารถกำหนดค่าเป็น BLOCKNODE_ROOT ได้ หากออบเจกต์นั้นเป็นโนดราก
	 * @var BlockInstruction
	 */
	public $block_instruction;
	/**
	 * เก็บรายการ header ของบล็อกนี้ในรูป key => value
	 * @var array
	 */
	public $headers = [];
	/**
	 * เก็บโนดย่อยๆ ของบล็อกนี้
	 * @var ExecutionNodeList
	*/
	public $children;
	/**
	 *
	 * @param BlockInstruction $instruction คำสั่งประจำบล็อกนี้
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
			$ret = $this->block_instruction->operation($this->headers, $this->children);
			Context::exit_block();
			return $ret;
		}
		else
		{
			$ret = $this->children->execute_all();
			Context::clear_reserve();
			return $ret;
		}
	}
	/**
	 * เพิ่มเฮดเดอร์
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function add_header($key, $value)
	{
		$this->headers[$key] = $value;
	}
	/**
	 * เพิ่มโนดย่อย
	 * @param ExecutionNode $node
	 */
	public function add_child($node)
	{
		$node->parent = $this;
		$this->children->add($node);
	}
}
/**
 * เป็นรายการของ execution node
 * @author acer-pc
 *
 */
class ExecutionNodeList
{
	/**
	 * เก็บ execution node
	 * @var array
	 */
	public $list = [];
	/**
	 * สั่งให้โนดในรายการทำงานทุกตัว และคืนค่าผลการทำงาน
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
	 * เพิ่มโนดลงในรายการ
	 * @param ExecutionNode $node
	 */
	public function add($node)
	{
		array_push($this->list, $node);
	}
}