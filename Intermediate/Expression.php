<?php
namespace Chassis\Intermediate;

include_once __DIR__.'/Operators.php';
include_once __DIR__.'/Identifier.php';

const LITERAL_NUMBER = 0;
const LITERAL_STRING = 1;
const LITERAL_BOOLEAN = 2;

/**
 * นิพจน์
 * @author acer-pc
 *
 */
abstract class Expression
{
	/**
	 * แม่ของ expression ตัวนี้
	 * @var Expression
	 */
	public $parent;
	/**
	 * สั่งให้คำนวณค่า
	 */
	public abstract function calculate();
}
/**
 * เป็น "ช่องว่าง" สำหรับเติมนิพจน์ลงไปในระหว่งาการสร้างโดย ExpressionBuilder
 * @author acer-pc
 *
 */
class ExpressionHole
{
	/**
	 * Expression ที่ถูกเติมลงในช่องว่างแล้ว
	 * @var Expression
	 */
	public $expression = null;
	/**
	 * Operator Expression ที่เป็นเจ้าของรูนี้
	 * @var OperatorExpression
	 */
	public $owner;
	/**
	 * เติมนิพจน์ลงในรู
	 * @param Expression $exp นิพจน์ที่ต้องการเติมลงไป
	 */
	public function fill($exp)
	{
		if($exp !== null)
		{
			$exp->parent = $this->owner;
			$this->expression = $exp;
		}
		else
		{
			$this->expression = null;
		}
	}

	/**
	 *
	 * @param OperatorExpression $owner เจ้าของของ hole นี้
	 */
	public function __construct($owner)
	{
		$this->owner = $owner;
	}
}
/**
 * เป็นตัวแทนของ literal (ได้แก่ string, number, boolean)
 * @author acer-pc
 *
 */
class Literal extends Expression
{
	/**
	 * ค่าของ literal นี้
	 * @var mixed
	 */
	private $value;
	/**
	 * ชนิดของ literal นี้
	 * @var int
	 */
	public $type;
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\Expression::calculate()
	 */
	public function calculate()
	{
		return $this->value;
	}

	/**
	 *
	 * @param string $literal ค่าของ literal ในรูปของสตริง
	 * @param int $type ชนิดของ literal ได้แก่ LITERAL_NUMBER, LITERAL_STRING, LITERAL_BOOLEAN
	 */
	public function __construct($literal, $type)
	{
		switch($type)
		{
			case LITERAL_NUMBER:
				//ถ้ามีจุดทศนิยม
				if(strpos($literal, ".") !== false)
				{
					$this->value = floatval($literal);
				}
				else
				{
					$this->value = intval($literal);
				}
				break;
			case LITERAL_STRING:
				$this->value = $literal;
				break;
			case LITERAL_BOOLEAN:
				if(strtolower($literal) === "true")
				{
					$this->value = true;
				}
				else
				{
					$this->value = false;
				}
				break;
		}
		$this->type = $type;
	}

	public static function _test()
	{
		$a = new Literal("aaa", LITERAL_STRING);
		$b = new Literal("123", LITERAL_NUMBER);
		$c = new Literal("123.456", LITERAL_NUMBER);
		$d = new Literal("true", LITERAL_BOOLEAN);
		$e = new Literal("false", LITERAL_BOOLEAN);

		assert($a->calculate() === "aaa", "#1");
		assert($b->calculate() === 123, "#2");
		assert($c->calculate() === 123.456, "#3");
		assert($d->calculate() === true, "#4");
		assert($e->calculate() === false, "#5");
	}
}

class VariableExpression extends Expression
{
	/**
	 * ชื่อตัวแปร
	 * @var Variable
	 */
	private $var;
	/**
	 * เก็บรายการของ modifier
	 * @var array
	 */
	public $modifier = [];

	public function calculate($base = null)
	{
		if($base === null || !is_array($base))
		{
			//เรียกดูตัวแปรจาก current context
		}
		else
		{
			return $base[$this->get_var_name()];
		}
	}

	/**
	 *
	 * @param string $name ชื่อตัวแปร
	 * @param string $modifier modifier
	 */
	public function __construct($name, $modifier = "")
	{
		$this->var = new Identifier($name);
		$this->modifier = $modifier;
	}
	/**
	 * ดึงชื่อตัวแปร หรือค่าอื่นๆ ที่ระบุตัวแปรได้
	 * @return mixed
	 */
	public function get_var_name()
	{
		return $this->var->get_name();
	}
}

class Closure extends Expression
{
	/**
	 * เก็บ expression ย่อยๆ ไว้
	 * @var array
	 */
	public $expr_list = [];
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\Expression::calculate()
	*/
	public function calculate()
	{
		return $this->expr_list[0]->calculate();
	}
	/**
	 * คำนวณ expression ทุกตัวในรายการ
	 * @return คืนค่าผลการคำนวณ เรียงตามลำดับของ expression ย่อย
	 */
	public function calculate_all()
	{
		$ret = [];
		foreach($this->expr_list as $expr)
		{
			array_push($ret, $expr->calculate());
		}
		return $ret;
	}
	/**
	 * เพิ่มนิพจน์ลงในรายการ
	 * @param Expression $expr นิพจน์ที่จะเพิ่ม
	 */
	public function add_expr($expr)
	{
		$expr->parent = $this;
		array_push($this->expr_list, $expr);
	}
}

class OperatorExpression extends Expression
{
	/**
	 * เก็บ "ช่องว่าง" สำหรับตัวถูกดำเนินการทางด้านซ้าย
	 * @var ExpressionHole
	 */
	public $left;
	/**
	 * เก็บ "ช่องว่าง" สำหรับตัวถูกดำเนินการทางด้านขวา
	 * @var ExpressionHole
	 */
	public $right;
	/**
	 * เก็บตัวดำเนินการประจำนิพจน์นี้
	 * @var Operator
	 */
	public $operator;
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\Expression::calculate()
	 */
	public function calculate()
	{
		return $this->operator->operation($this->left->expression, $this->right->expression);
	}
	/**
	 * สลับข้อมูลกับ operator expression อีกตัว
	 * (ระวัง! จะสลับข้อมูลในตัวแปรเฉยๆ ชื่อตัวแปรไม่ได้ถูกสลับด้วย)
	 * @param OperatorExpression $exp
	 */
	public function swap($exp)
	{
		$oper1 = $this->operator;
		$left1 = $this->left->expression;
		$right1 = $this->right->expression;

		$oper2 = $exp->operator;
		$left2 = $exp->left->expression;
		$right2 = $exp->right->expression;

		$this->operator = $oper2;
		$this->left->fill($left2);
		$this->right->fill($right2);

		$exp->operator = $oper1;
		$exp->left->fill($left1);
		$exp->right->fill($right1);
	}

	/**
	 *
	 * @param Operator $oper
	 */
	public function __construct($oper)
	{
		$this->left = new ExpressionHole($this);
		$this->right = new ExpressionHole($this);
		$this->operator = $oper;
	}

	public static function _test_swap()
	{
		$e1 = new OperatorExpression(1);
		$e1->left->fill(new Literal("left1", LITERAL_STRING));
		$e1->right->fill(new Literal("right1", LITERAL_STRING));

		$e2 = new OperatorExpression(2);
		$e2->left->fill(new Literal("left2", LITERAL_STRING));
		$e2->right->fill(new Literal("right2", LITERAL_STRING));

		$e1->swap($e2);

		assert($e1->operator === 2
		&& $e1->left->expression->calculate() === "left2"
				&& $e1->right->expression->calculate() === "right2"
						, "#1");

		assert($e2->operator === 1
		&& $e2->left->expression->calculate() === "left1"
				&& $e2->right->expression->calculate() === "right1"
						, "#2");
	}
}
