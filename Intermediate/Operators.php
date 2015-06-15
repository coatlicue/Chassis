<?php
namespace Chassis\Intermediate;

use Chassis\Parser;
use Chassis\Parser\VariableExpression;
include_once __DIR__."/../Parser/ExpressionParser.php";

const OPER_PREFIX = 1;
const OPER_INFIX = 2;
const OPER_POSTFIX = 3;

/**
 * ���ʹ���繵��᷹�ͧ��Ǵ��Թ���
 * @author acer-pc
 *
 */
abstract class Operator
{
	/**
	 * �纤�ҷ���кت�Դ�ͧ��Ǵ��Թ���
	 * @var int
	 */
	public $id;
	/**
	 * �纤�ҷ���к���ҵ�Ǵ��Թ��ù���ͧ�ҧ�ç��ǹ㴢ͧ��Ƕ١���Թ��� ����
	 * OPER_PREFIX : �ҧ��ҹ˹��
	 * OPER_INFIX : �ҧ�ç��ҧ
	 * OPER_POSTFIX : �ҧ��ҹ��ѧ
	 * @var int
	 */
	public $affix;
	/**
	 * �к� precedence �ͧ��Ǵ��Թ���
	 * @var int
	 */
	public $precedence;
	/**
	 * ��˹���÷ӧҹ�ͧ��Ǵ��Թ���
	 * @param Expression $left
	 * @param Expression $right
	 */
	public abstract function operation($left, $right);
}

/**
 * �繤��ʷ���� operator �������� ��� �觵����Դ affix
 * @author acer-pc
 *
 */
class OperatorPolymorphism
{
	/**
	 * �纪�Դ affix �Ѻ���Ѻ oper
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
	 * ��Ǩ�ͺ��� 㹡��������� operator ��Դ����к��������
	 * @param int $affix
	 * @return bool
	 */
	public function has($affix)
	{
		return array_key_exists($affix, $this->opers);
	}
	/**
	 * �֧ operator ���¡�÷���ժ�Դ affix ����к�
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
 * ���ʸ (!)
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
 * ��� (&&)
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
 * ���� (||)
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
 * ��ҡѺ (==)
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
 * ���¡��� (<)
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
 * �ҡ����(>)
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
 * ���¡���������ҡѺ (<=)
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
 * �ҡ����������ҡѺ (>=)
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
 * �ӹǹ���ź(-x)
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
 * �ٳ(*)
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
 * ���(/)
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
 * �ǡ, ���ʵ�ԧ (+)
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
 * ź
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
 * �ʹ��� (%)
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
 * ���¡�ѧ��ѹ
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
 * ���¡�٤�����������
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