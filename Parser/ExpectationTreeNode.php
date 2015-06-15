<?php
namespace Chassis\Parser;

const ETN_TYPE_INITIATION = 1;
const ETN_TYPE_TERMINATION = 2;
const ETN_TYPE_CHARACTER = 3;

class ExpectationTreeNode
{
	/**
	 * ชนิดของโนด
	 */
	public $type;
	/**
	 * ตัวอักษรประจำโนด (หากกำหนดชนิดของโนดเป็น ETN_TYPE_CHARACTER)
	 * @var string
	 */
	public $char;
	/**
	 * รายการของโนดลูก
	 * @var array
	 */
	public $children = [];
	/**
	 * แคชการค้นหาของโนดลูก (จะเก็บโนดลูกที่ถูกค้นหาครั้งล่าสุด)
	 * @var ExpectationTreeNode
	 */
	public $search_cache;
	/**
	 * ข้อมูลที่จะถูกผูกติดกับ node นี้
	 * @var mixed
	 */
	public $tag;
	/**
	 * โนดที่อยู่ในระดับสูงขึ้นไปจากโนดนี้
	 * @var ExpectationTreeNode
	 */
	public $parent;
	
	/**
	 * @param int $type ชนิดของโนด
	 * @param string $char ตัวอักษรประจำโนด
	 */
	public function __construct($type, $char = null)
	{
		$this->type = $type;
		$this->char = $char;
	}
	
	/**
	 * เพิ่มโนดลูก
	 * @param int $type ชนิดของโนดลูก
	 * @param string $char ตัวอักษร
	 */
	public function add($type, $char = null)
	{
		if($node = $this->retrieve($type, $char))
		{ //ถ้ามีโนดซ้ำอยู่แล้ว
			return $node;
		}
		else 
		{ //ถ้ายังไม่มีโนดที่ระบุ ให้สร้างขึ้นมาใหม่
			$child = new self($type, $char);
			$child->parent = $this;
			$this->search_cache = $child;
			array_push($this->children, $child);
			return $child;
		}
	}
	
	/**
	 * ดึงโนดลูกตามที่ระบุ
	 * @param int $type ชนิดของโนดลูก
	 * @param string $char ตัวอักษร
	 */
	public function retrieve($type, $char = null)
	{
		if($this->search_cache !== null 
				&& $this->search_cache->type === $type 
				&& $this->search_cache->char === $char)
		{
			return $this->search_cache;
		}
		else
		{
			foreach($this->children as $child)
			{
				if($child->type === $type && $child->char === $char)
				{
					$this->search_cache = $child;
					return $child;
				}
			}
			return false;
		}
	}
	
	/**
	 * ลบโนดลูกที่ระบุ
	 * @param int $type
	 * @param string $char
	 */
	public function delete($type, $char = null)
	{
		foreach($this->children as $key=>$value)
		{
			if($value->type === $type && $value->char === $char)
			{
				$this->children[$key]->parent = null;
				unset($this->children[$key]);
			}
		}
		if($this->search_cache !== null
				&& $this->search_cache->type === $type 
				&& $this->search_cache->char === $char)
		{
			$this->search_cache = null;
		}
	}
	/**
	 * เพิ่มรายการสตริงลงในโนดนี้
	 * @param array $string_list
	 */
	public function add_list(array $string_list)
	{
		foreach($string_list as $key=>$value)
		{
			if(is_string($key))
			{
				$str = $key;
			}
			else
			{
				$str = $value;
				$value = null;
			}
				
			$this->add_str($str, $value);
		}
	}
	/**
	 * เพิ่มสตริงที่ระบุลงในโนดนี้
	 * @param string $str สตริง
	 * @param mixed $tag สิ่งที่จะติดไปกับสตริงนี้
	 * @param boolean $terminate ระบุว่า จะให้จบท้ายสตริงนี้หรือไม่
	 * @return ExpectationTreeNode
	 */
	public function add_str($str, $tag = null, $terminate = true)
	{
		$parent = $this;
		for($i=0; $i<strlen($str); $i++)
		{
			$child = $parent->add(ETN_TYPE_CHARACTER, $str[$i]);
			$parent = $child;
		}
		if($terminate)
		{
			$parent->add(ETN_TYPE_TERMINATION)->tag = $tag;
		}
		return $parent;
	}
	/**
	 * ลบรายการสตริงออกจากโนดนี้
	 * @param array $string_list
	 */
	public function remove_list(array $string_list)
	{
		foreach($string_list as $str)
		{
			$parent = $this->get_char_node($str);
			
			$parent->delete(ETN_TYPE_TERMINATION);
			//ตรวจสอบว่าโนดแม่ของโนดที่ถูกลบ เป็นโนดว่างหรือไม่ ถ้าใช่ก็ให้ลบไปเรื่อยๆ
			while(count($parent->children) === 0)		
			{
				$child = $parent;
				$parent = $child->parent;
				$parent->delete($child->type, $child->char);
			}
		}
	}
	/**
	 * ดึงโนดลูกที่แทนสตริงที่ระบุ
	 * @param string $str
	 * @return ถ้าพบโนดลูก จะคืนออบเจกต์ของโนดลูก ถ้าไม่พบ คืนค่า false
	 */
	public function get_char_node($str)
	{
		$parent = $this;
		for($i=0; $i<strlen($str); $i++)
		{
			$parent = $parent->retrieve(ETN_TYPE_CHARACTER, $str[$i]);
			if($parent === false) break;
		}
		return $parent;
	}
	
	/**
	 * สร้าง expectation tree จากรายการของสตริงที่ระบุ
	 * สามารถกำหนดอาร์เรย์ในรูป ["s1", "s2" => "tag", "s3"]
	 * @param array $string_list
	 */
	public static function create(array $string_list)
	{
		$root = new ExpectationTreeNode(ETN_TYPE_INITIATION);
		$root->add_list($string_list);
		return $root;
	}
}

class _Test_ExpectationTreeNode
{
	public $obj;
	
	public function __construct()
	{
		assert_options(ASSERT_ACTIVE | ASSERT_WARNING);
	}
	
	public function create_obj()
	{
		$this->obj = new ExpectationTreeNode(ETN_TYPE_INITIATION);
	}
	
	public function add()
	{
		$obj = $this->obj;
		
		$c = $obj->add(ETN_TYPE_INITIATION);
		assert($c === $obj->children[0], "add return #1");
		assert($obj->children[0]->type === ETN_TYPE_INITIATION, "adding #1");
		assert($c === $obj->search_cache, "add cache #1");
		
		$c = $obj->add(ETN_TYPE_TERMINATION);
		assert($c === $obj->children[1], "add return #2");
		assert($obj->children[1]->type === ETN_TYPE_TERMINATION, "adding #2");
		assert($c === $obj->search_cache, "add cache #2");
		
		$c = $obj->add(ETN_TYPE_CHARACTER, "d");
		assert($c === $obj->children[2], "add return #3");
		assert($obj->children[2]->type === ETN_TYPE_CHARACTER && $obj->children[2]->char === "d", "adding #3");
		assert($c === $obj->search_cache, "add cache #3");
		
		$c = $obj->add(ETN_TYPE_CHARACTER, "b");
		assert($c === $obj->children[3], "add return #4");
		assert($obj->children[3]->type === ETN_TYPE_CHARACTER && $obj->children[3]->char === "b", "adding #4");
		assert($c === $obj->search_cache, "add cache #4");
	}
	
	public function add_duplicate()
	{
		$obj = $this->obj;
		
		$obj->add(ETN_TYPE_CHARACTER, "a");
		$obj->add(ETN_TYPE_CHARACTER, "a");
		$obj->add(ETN_TYPE_CHARACTER, "b");
		$obj->add(ETN_TYPE_CHARACTER, "b");
		$obj->add(ETN_TYPE_INITIATION);
		$obj->add(ETN_TYPE_INITIATION);
		$obj->add(ETN_TYPE_TERMINATION);
		$obj->add(ETN_TYPE_TERMINATION);
		assert(count($obj->children) === 4, "add duplicate children");
	}
	
	public function retrieve()
	{
		$obj = $this->obj;
		
		$c = $obj->retrieve(ETN_TYPE_INITIATION);
		assert($c === false, "retrieve non-exist child #1");
		$c = $obj->retrieve(ETN_TYPE_TERMINATION);
		assert($c === false, "retrieve non-exist child #2");
		$c = $obj->retrieve(ETN_TYPE_CHARACTER, "n");
		assert($c === false, "retrieve non-exist child #3");
		
		$obj->add(ETN_TYPE_INITIATION);
		assert($obj->retrieve(ETN_TYPE_INITIATION) !== false, "retrieve exist child #1");
		$obj->add(ETN_TYPE_TERMINATION);
		assert($obj->retrieve(ETN_TYPE_TERMINATION) !== false, "retrieve exist child #2");
		$obj->add(ETN_TYPE_CHARACTER, "a");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "a") !== false, "retrieve exist child #3");
		$obj->add(ETN_TYPE_CHARACTER, "b");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "b") !== false, "retrieve exist child #4");
		
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "c") === false, "retrieve non-exist char child #1");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "d") === false, "retrieve non-exist char child #2");
	}
	
	public function delete()
	{
		$obj = $this->obj;
		$obj->add(ETN_TYPE_TERMINATION);
		$obj->add(ETN_TYPE_INITIATION);
		$obj->add(ETN_TYPE_CHARACTER, "b");
		$obj->add(ETN_TYPE_CHARACTER, "a");
		
		$obj->delete(ETN_TYPE_CHARACTER, "a");
		assert($obj->search_cache === null, "check deleted cache #1");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "a") === false, "retrieve deleted child #1.1");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "b") !== false, "retrieve non-deleted child #1.2");
		assert($obj->retrieve(ETN_TYPE_INITIATION) !== false, "retrieve non-deleted child #1.3");
		assert($obj->retrieve(ETN_TYPE_TERMINATION) !== false, "retrieve non-deleted child #1.4");
		
		$obj->delete(ETN_TYPE_CHARACTER, "b");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "a") === false, "retrieve deleted child #2.1");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "b") === false, "retrieve deleted child #2.2");
		assert($obj->retrieve(ETN_TYPE_INITIATION) !== false, "retrieve non-deleted child #2.3");
		assert($obj->retrieve(ETN_TYPE_TERMINATION) !== false, "retrieve non-deleted child #2.4");
		
		$obj->delete(ETN_TYPE_INITIATION);
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "a") === false, "retrieve deleted child #3.1");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "b") === false, "retrieve deleted child #3.2");
		assert($obj->retrieve(ETN_TYPE_INITIATION) === false, "retrieve deleted child #3.3");
		assert($obj->retrieve(ETN_TYPE_TERMINATION) !== false, "retrieve non-deleted child #3.4");
		
		$obj->delete(ETN_TYPE_TERMINATION);
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "a") === false, "retrieve deleted child #4.1");
		assert($obj->retrieve(ETN_TYPE_CHARACTER, "b") === false, "retrieve deleted child #4.2");
		assert($obj->retrieve(ETN_TYPE_INITIATION) === false, "retrieve deleted child #4.3");
		assert($obj->retrieve(ETN_TYPE_TERMINATION) === false, "retrieve deleted child #4.4");
	}
	
	public function create()
	{
		$list = ["if" => "ifx", "ifelse", "for", "foreach", "india"];
		
		$node = ExpectationTreeNode::create($list);
		
		assert($node->retrieve(ETN_TYPE_CHARACTER, "i") !== false, "retrieve at root #1");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "f") !== false, "retrieve at root #2");
		
		$node = $node->retrieve(ETN_TYPE_CHARACTER, "i");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "f") !== false, "retrieve at 'i(1)' #1");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "n") !== false, "retrieve at 'i(1)' #2");
		
		assert($node->retrieve(ETN_TYPE_CHARACTER, "f")->retrieve(ETN_TYPE_TERMINATION)->tag === "ifx", "check termination tag");
		
		$node = $node->retrieve(ETN_TYPE_CHARACTER, "f");
		assert($node->retrieve(ETN_TYPE_TERMINATION) !== false, "retrieve at 'f(2)' #1");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "e") !== false, "retrieve at 'f(2)' #2");
	}
	
	public function remove_list()
	{
		$add_list = ["eee", "eer", "eerq", "eesw", "ewn"];
		$remove_list = ["eer", "eesw"];
		
		$node = ExpectationTreeNode::create($add_list);
		$node->remove_list($remove_list);
		
		assert($node->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_TERMINATION) !== false, "#1");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "r")
				->retrieve(ETN_TYPE_TERMINATION) === false, "#2");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "r")
				->retrieve(ETN_TYPE_CHARACTER, "q") !== false, "#3");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "s") === false, "#4");
		assert($node->retrieve(ETN_TYPE_CHARACTER, "e")
				->retrieve(ETN_TYPE_CHARACTER, "w")
				->retrieve(ETN_TYPE_CHARACTER, "n") !== false, "#5");
	}
}
?>