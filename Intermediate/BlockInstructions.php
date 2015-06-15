<?php
namespace Chassis\Intermediate;

use Chassis\Parser\KeywordNode;
use Chassis\Intermediate as I;
include_once __DIR__."/../Parser/ExecutionParser.php";
include_once __DIR__."/Context.php";

/**
 * เก็บข้อมูลของคำสั่งประจำบล็อก เช่น if for
 * @author acer-pc
 *
 */
abstract class BlockInstruction
{
	/**
	 * เก็บชื่อของคำสั่งนี้
	 * @var string
	 */
	public $name;
	/**
	 * เก็บค่าคงตัวที่ระบุคำสั่งนี้ได้
	 * @var int
	 */
	public $id;
	/**
	 * เก็บ keyword tree ที่ระบุถึง keyword ต่างๆ ในแท็กเปิดของบล็อก
	 * เช่น {@for i from x to y step -2} => from, to และ step คือ keyword
	 * @var KeywordNode
	 */
	public $keyword_tree;
	/**
	 * ระบุว่า บล็อกนี้ ไม่ต้องมีแท็กปิด ใช่ไหม?
	 * @var boolean
	 */
	public $no_close = false;
	/**
	 * กำหนดฟังก์ชันสำหรับทำงาน
	 * @param array $headers รายการเฮดเดอร์
	 * @param ExecutionNodeList $children ลูกๆ
	 */
	public abstract function operation($headers, $children);
}

const BI_IF = 1;
const BI_ELSE = 2;
const BI_ELSEIF = 3;
const BI_FOR = 4;
const BI_FOREACH = 5;

class BlockInstruction_If extends BlockInstruction
{
	public function __construct()
	{
		$this->id = BI_IF;
		$this->name = "if";
		$this->no_close = false;
		$this->keyword_tree = KeywordNode::compile(["#exp.cond"]);
	}
	
	public function operation($headers, $children)
	{
		if($headers['cond'])
		{
			Context::set_var(I\VAR_CHANNEL_RESERVED, "execute_else", false, true);
			return $children->execute_all();
		}
		else
		{ //ส่งให้แท็ก {@else} ถัดไป รัน
			Context::set_var(I\VAR_CHANNEL_RESERVED, "execute_else", true, true);
			return "";
		}
	}
}

class BlockInstruction_Else extends BlockInstruction
{
	public function __construct()
	{
		$this->id = BI_ELSE;
		$this->name = "else";
		$this->no_close = false;
		$this->keyword_tree = KeywordNode::compile([""]);
	}

	public function operation($headers, $children)
	{
		if(Context::get_var(I\VAR_CHANNEL_RESERVED, "execute_else"))
		{
			return $children->execute_all();
		}
	}
}

class BlockInstruction_ElseIf extends BlockInstruction_If
{
	public function __construct()
	{
		$this->id = BI_ELSEIF;
		$this->name = "elseif";
		$this->no_close = false;
		$this->keyword_tree = KeywordNode::compile(["#exp.cond"]);
	}

	public function operation($headers, $children)
	{
		if(Context::get_var(I\VAR_CHANNEL_RESERVED, "execute_else"))
		{
			return parent::operation($headers, $children);
		}
	}
}

class BlockInstruction_For extends BlockInstruction
{
	public function __construct()
	{
		$this->id = BI_FOR;
		$this->name = "for";
		$this->no_close = false;
		$this->keyword_tree = KeywordNode::compile([
				"#var.counter from #exp.start to #exp.end", 
				"#var.counter from #exp.start to #exp.end step #exp.step"
		]);
	}

	public function operation($headers, $children)
	{
		//TODO : ตรวจสอบด้วยว่า พารามิเตอร์ที่ได้รับมีค่าเป็นตัวเลขหรือไม่
		$start = $headers['start']->calculate();
		$end = $headers['end']->calculate();
		$counter_name = $header['counter']->get_name();
		$step = array_key_exists('step', $header) ? $header['step']->calculate() : 1;
	
		$min = min([$start, $end]);
		$max = max([$start, $end]);
	
		$ret = "";
	
		for($i=$start; $i<=$max && $i>=$min; $i+=$step)
		{
			Context::set_var(I\VAR_CHANNEL_NORMAL, $counter_name, $i, false);
			$ret .= $children->execute_all();
		}
	
		return $ret;
	}
}

class BlockInstruction_ForEach extends BlockInstruction
{
	public function __construct()
	{
		$this->id = BI_FOREACH;
		$this->name = "foreach";
		$this->no_close = false;
		$this->keyword_tree = KeywordNode::compile([
				"#var.var1 in #exp.array",
		 		"#var.var1 : #var.var2 in #exp.array"
		]);
	}

	public function operation($headers, $children)
	{
		if(is_array($headers['array']))
		{
			$key_name = "";
			$value_name = "";
			if(array_key_exists('var2', $headers))
			{
				$key_name = $headers['var1']->get_name();
				$value_name = $headers['var2']->get_name();
			}
			else
			{
				$key_name = null;
				$value_name = $headers['var1']->get_name();
			}
			
			$ret = "";
			
			foreach($headers['array'] as $key=>$value)
			{
				if($key_name !== null) Context::set_var(I\VAR_CHANNEL_NORMAL, $key_name, $key, false);
				Context::set_var(I\VAR_CHANNEL_NORMAL, $value_name, $value, false);
				$ret .= $children->execute_all();
			}
			
			return $ret;
		}
		else
		{
			//error.
		}
	}
}

class BlockInstruction_Test_NoClose extends BlockInstruction
{
	public function __construct()
	{
		$this->id = 0;
		$this->name = "test_noclose";
		$this->no_close = true;
		$this->keyword_tree = KeywordNode::compile([""]);
	}

	public function operation($headers, $children)
	{

	}
}
?>