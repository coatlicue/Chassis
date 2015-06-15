<?php
namespace Chassis\Intermediate;

include_once __DIR__.'/Operators.php';
include_once __DIR__.'/Identifier.php';

const LITERAL_NUMBER = 0;
const LITERAL_STRING = 1;
const LITERAL_BOOLEAN = 2;

/**
 * �Ծ���
 * @author acer-pc
 *
 */
abstract class Expression
{
	/**
	 * ���ͧ expression ��ǹ��
	 * @var Expression
	 */
	public $parent;
	/**
	 * ������ӹǳ���
	 */
	public abstract function calculate();
}
/**
 * �� "��ͧ��ҧ" ����Ѻ����Ծ���ŧ�������ҡ�����ҧ�� ExpressionBuilder
 * @author acer-pc
 *
 */
class ExpressionHole
{
	/**
	 * Expression ���١���ŧ㹪�ͧ��ҧ����
	 * @var Expression
	 */
	public $expression = null;
	/**
	 * Operator Expression �������Ңͧ�ٹ��
	 * @var OperatorExpression
	 */
	public $owner;
	/**
	 * ����Ծ���ŧ���
	 * @param Expression $exp �Ծ������ͧ������ŧ�
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
	 * @param OperatorExpression $owner ��Ңͧ�ͧ hole ���
	 */
	public function __construct($owner)
	{
		$this->owner = $owner;
	}
}
/**
 * �繵��᷹�ͧ literal (���� string, number, boolean)
 * @author acer-pc
 *
 */
class Literal extends Expression
{
	/**
	 * ��Ңͧ literal ���
	 * @var mixed
	 */
	private $value;
	/**
	 * ��Դ�ͧ literal ���
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
	 * @param string $literal ��Ңͧ literal ��ٻ�ͧʵ�ԧ
	 * @param int $type ��Դ�ͧ literal ���� LITERAL_NUMBER, LITERAL_STRING, LITERAL_BOOLEAN
	 */
	public function __construct($literal, $type)
	{
		switch($type)
		{
			case LITERAL_NUMBER:
				//����ըش�ȹ���
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
	 * ���͵����
	 * @var Variable
	 */
	private $var;
	/**
	 * ����¡�âͧ modifier
	 * @var array
	 */
	public $modifier = [];

	public function calculate($base = null)
	{
		if($base === null || !is_array($base))
		{
			//���¡�ٵ���èҡ current context
		}
		else
		{
			return $base[$this->get_var_name()];
		}
	}

	/**
	 *
	 * @param string $name ���͵����
	 * @param string $modifier modifier
	 */
	public function __construct($name, $modifier = "")
	{
		$this->var = new Identifier($name);
		$this->modifier = $modifier;
	}
	/**
	 * �֧���͵���� ���ͤ������ ����кص������
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
	 * �� expression ����� ���
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
	 * �ӹǳ expression �ء������¡��
	 * @return �׹��Ҽš�äӹǳ ���§����ӴѺ�ͧ expression ����
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
	 * �����Ծ���ŧ���¡��
	 * @param Expression $expr �Ծ����������
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
	 * �� "��ͧ��ҧ" ����Ѻ��Ƕ١���Թ��÷ҧ��ҹ����
	 * @var ExpressionHole
	 */
	public $left;
	/**
	 * �� "��ͧ��ҧ" ����Ѻ��Ƕ١���Թ��÷ҧ��ҹ���
	 * @var ExpressionHole
	 */
	public $right;
	/**
	 * �纵�Ǵ��Թ��û�ШӹԾ�����
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
	 * ��Ѻ�����šѺ operator expression �ա���
	 * (���ѧ! ����Ѻ������㹵������� ���͵���������١��Ѻ����)
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
