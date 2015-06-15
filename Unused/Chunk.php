<?php
namespace Chassis\Parser;

include_once __DIR__.'/TreeStateScanner.php';

abstract class Chunk implements ITreeNode
{
	/**
	 * โนดแม่
	 * @var ITreeNode
	 */
	private $parent;
	/**
	 * ฉลากของ chunk ก้อนนี้ (แล้วแต่ผู้ใช้จะกำหนด)
	 * @var int
	 */
	public $label;
	/**
	 * บรรทัดที่ chunk นี้อยู่
	 * @var int
	 */
	public $line;
	/**
	 * ตำแหน่งของตัวอักษรในบรรทัดที่ chunk นี้อยู่
	 * @var offset
	 */
	public $offset;
	
	/**
	 * 
	 * เพิ่มโนดลูก
	 * @param Chunk $node โนดลูกที่ต้องการเพิ่ม
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
	 * @param int $label ฉลากที่ติดให้กับ chunk นี้
	 * @param int $line บรรทัดที่ chunk นี้อยู่
	 * @param int $offset ตำแหน่งของตัวอักษรในบรรทัดที่ chunk นี้อยู่
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
	 * ค่าของ chunk นี้
	 * @var string
	 */
	public $value = "";
	/**
	 * เขียนค่าเพิ่มให้แก่ chunk นี้
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
	 * ชี้ไปยังตำแหน่งท้ายสุดของ $children
	 * @var int
	 */
	private $last_child_index = -1;
	/**
	 * ชี้ไปยังตำแหน่งใน $children ที่จะถูกดึงต่อไป
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
	 * ดึงโนดลูกถัดไป และเลื่อนตำแหน่งไปข้างหน้า
	 * @return Chunk
	 * จะคืนค่าเป็น false เมื่อถึงจุดสิ้นสุดของ chunk
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
	 * ดึงโนดลูกถึงไปโดยไม่เพิ่มตำแหน่ง
	 * @return Chunk
	 * จะคืนค่าเป็น false เมื่อถึงจุดสิ้นสุดของ chunk
	 */
	public function peek_next_child()
	{
		return $this->get_child_at($this->next_child_to_get);
	}
	/**
	 * ดึงโนดลูกตัวท้ายสุด
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
		//ทดลองเพิ่มลูก เขียนค่า แล้วดึงออกมา
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