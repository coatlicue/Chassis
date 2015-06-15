<?php
namespace Chassis\Intermediate;

use Chassis\Parser;
use Chassis\Parser\VariableExpression;
include_once __DIR__."/../Parser/ExpressionParser.php";

const OPER_PREFIX = 1;
const OPER_INFIX = 2;
const OPER_POSTFIX = 3;

/**
 * คลาสนี้เป็นตัวแทนของตัวดำเนินการ
 * @author acer-pc
 *
 */
abstract class Operator
{
	/**
	 * เก็บค่าที่ระบุชนิดของตัวดำเนินการ
	 * @var int
	 */
	public $id;
	/**
	 * เก็บค่าที่ระบุว่าตัวดำเนินการนี้ต้องวางตรงส่วนใดของตัวถูกดำเนินการ ได้แก่
	 * OPER_PREFIX : วางด้านหน้า
	 * OPER_INFIX : วางตรงกลาง
	 * OPER_POSTFIX : วางด้านหลัง
	 * @var int
	 */
	public $affix;
	/**
	 * ระบุ precedence ของตัวดำเนินการ
	 * @var int
	 */
	public $precedence;
	/**
	 * กำหนดการทำงานของตัวดำเนินการ
	 * @param Expression $left
	 * @param Expression $right
	 */
	public abstract function operation($left, $right);
}

/**
 * เป็นคลาสที่เก็บ operator ไว้หลายๆ ตัว แบ่งตามชนิด affix
 * @author acer-pc
 *
 */
class OperatorPolymorphism
{
	/**
	 * เก็บชนิด affix จับคู่กับ oper
	 * @var array
	 */
	private $opers;
	/**
	 *
	 * @param Operator $oper1
	 * @param Operator $oper2
	 * @param Operator $oper3
	 */
	public function __construct($oper1, $oper2, $oper3 = null)
	{
		$this->opers[$oper1->affix] = $oper1;
		$this->opers[$oper2->affix] = $oper2;
		if($oper3 !== null) $this->opers[$oper3->affix] = $oper3;
	}
	/**
	 * ตรวจสอบว่า ในกลุ่มนี้มี operator ชนิดที่ระบุหรือไม่
	 * @param int $affix
	 * @return bool
	 */
	public function has($affix)
	{
		return array_key_exists($affix, $this->opers);
	}
	/**
	 * ดึง operator ในรายการที่มีชนิด affix ที่ระบุ
	 * @param int $affix
	 * @return Operator
	 */
	public function get($affix)
	{
		return $this->opers[$affix];
	}
}

//operator list
const OPER_NOT = 1; //!
const OPER_AND = 2; //&&
const OPER_OR = 3; //||
const OPER_EQUAL = 4; //=
const OPER_LESS_THAN = 5; //<
const OPER_GREATER_THAN = 6;
const OPER_LESS_THAN_OR_EQUAL = 7; //<=
const OPER_GREATER_THAN_OR_EQUAL = 8; //>=
const OPER_NEGATIVE = 9; //-x
const OPER_MULTIPLY = 10; //+
const OPER_DIVIDE = 11; // /
const OPER_ADD = 12; // +
const OPER_SUBTRACT = 13; // -
const OPER_MOD = 14; // %
const OPER_FUNC_CALL = 15; // x(y,z)
const OPER_VAR_IMPORT_CHANNEL = 16; //$
const OPER_VAR_SUBROUTINE_CHANNEL = 17; //%
const OPER_ARRAY_RETRIEVAL = 18; // x.y
const OPER_VAR_BUILT_IN = 19; //@x

/**
 * นิเสธ (!)
 * @author acer-pc
 *
 */
class Operator_Not extends Operator
{
	public function __construct()
	{
		$this->id = OPER_NOT;
		$this->precedence = 20;
		$this->affix = OPER_PREFIX;
	}
	
	public function operation($left, $right)
	{
		return ! $right->calculate();
	}
}
/**
 * และ (&&)
 */
class Operator_And extends Operator
{
	public function __construct()
	{
		$this->id = OPER_AND;
		$this->precedence = 19;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() && $right->calculate();
	}
}
/**
 * หรือ (||)
 */
class Operator_Or extends Operator
{
	public function __construct()
	{
		$this->id = OPER_OR;
		$this->precedence = 18;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() || $left->calculate();
	}
}
/**
 * เท่ากับ (==)
 */
class Operator_Equal extends Operator
{
	public function __construct()
	{
		$this->id = OPER_EQUAL;
		$this->precedence = 40;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() === $right->calculate();
	}
}
/**
 * น้อยกว่า (<)
 */
class Operator_LessThan extends Operator
{
	public function __construct()
	{
		$this->id = OPER_LESS_THAN;
		$this->precedence = 40;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() < $right->calculate();
	}
}
/**
 * มากกว่า(>)
 */
class Operator_GreaterThan extends Operator
{
	public function __construct()
	{
		$this->id = OPER_GREATER_THAN;
		$this->precedence = 40;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() > $right->calculate();
	}
}
/**
 * น้อยกว่าหรือเท่ากับ (<=)
 */
class Operator_LessThanOrEqual extends Operator
{
	public function __construct()
	{
		$this->id = OPER_LESS_THAN_OR_EQUAL;
		$this->precedence = 40;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() <= $right->calculate();
	}
}
/**
 * มากกว่าหรือเท่ากับ (>=)
 */
class Operator_GreaterThanOrEqual extends Operator
{
	public function __construct()
	{
		$this->id = OPER_GREATER_THAN_OR_EQUAL;
		$this->precedence = 40;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() >= $right->calculate();
	}
}
/**
 * จำนวนเต็มลบ(-x)
 */
class Operator_Negative extends Operator
{
	public function __construct()
	{
		$this->id = OPER_NEGATIVE;
		$this->precedence = 60;
		$this->affix = OPER_PREFIX;
	}
	
	public function operation($left, $right)
	{
		return - $right->calculate();
	}
}
/**
 * คูณ(*)
 */
class Operator_Multiply extends Operator
{
	public function __construct()
	{
		$this->id = OPER_MULTIPLY;
		$this->precedence = 59;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() * $right->calculate();
	}
}
/**
 * หาร(/)
 */
class Operator_Divide extends Operator
{
	public function __construct()
	{
		$this->id = OPER_DIVIDE;
		$this->precedence = 59;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() / $right->calculate();
	}
}
/**
 * บวก, ต่อสตริง (+)
 */
class Operator_Add extends Operator
{
	public function __construct()
	{
		$this->id = OPER_ADD;
		$this->precedence = 58;
		$this->affix = OPER_INFIX;
	}

	public function operation($left, $right)
	{
		$l = $left->calculate();
		$r = $right->calculate();
		if(is_string($l) && is_string($r))
		{
			return $l.$r;
		}
		else
		{
			return $l+$r;
		}
	}
}
/**
 * ลบ
 */
class Operator_Subtract extends Operator
{
	public function __construct()
	{
		$this->id = OPER_SUBTRACT;
		$this->precedence = 58;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() - $right->calculate();
	}
}
/**
 * มอดุโล (%)
 */
class Operator_Modulo extends Operator
{
	public function __construct()
	{
		$this->id = OPER_MOD;
		$this->precedence = 57;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return $left->calculate() % $right->calculate();
	}
}
/**
 * เรียกฟังก์ชัน
 */
class Operator_FunctionCall extends Operator
{
	public function __construct()
	{
		$this->id = OPER_FUNC_CALL;
		$this->precedence = 80;
		$this->affix = OPER_INFIX;
	}
	
	public function operation($left, $right)
	{
		return call_user_func_array($left->calculate(), $right->calculate_all());
	}
}
/**
 * เรียกดูค่าในอาร์เรย์
 */
class Operator_GetArrayValue extends Operator
{
	public function __construct()
	{
		$this->id = OPER_ARRAY_RETRIEVAL;
		$this->precedence = 100;
		$this->affix = OPER_INFIX;
	}

	public function operation($left, $right)
	{
		if(!($right instanceof VariableExpression)) return null;
		return $left->calculate()[$right->get_var_name()];
	}
}