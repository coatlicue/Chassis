<?php
namespace Chassis\Parser;

const EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER = 1;
const EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER = 2;
const EB_ERROR_UNKNOWN_OPER_AFFIX = 3;

use Chassis\Intermediate as I;
use Chassis\Intermediate\IOperator;
use Chassis\Intermediate\Operator;
use Chassis\Intermediate\OperatorPolymorphism;
use Chassis\Intermediate\Expression;
use Chassis\Intermediate\Literal;
use Chassis\Intermediate\Closure;
use Chassis\Intermediate\OperatorExpression;
use Chassis\Intermediate\VariableExpression;
use Chassis\Intermediate\ExpressionHole;

include_once __DIR__.'/StateScanner.php';
include_once __DIR__.'/ExecutionParser.php';
include_once __DIR__.'/../Intermediate/Operators.php';
include_once __DIR__.'/../Intermediate/Expression.php';

/**
 * �繷���ͧ�Ѻ�Ծ���������ҧ������ҧ�� ExpressionBuilder
 * @author acer-pc
 *
 */
class ExpressionSandbox extends Expression
{
	/**
	 * ��ͧ��ҧ����Ѻ�� Expression
	 * @var ExpressionHole
	 */
	public $hole;
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\Expression::calculate()
	 */
	public function calculate()
	{
		return $this->child->calculate();
	}
	
	public function __construct()
	{
		$this->hole = new ExpressionHole($this);
	}
}
/**
 * ���ʹ��Ъ���㹡�����ҧ Expression tree
 * @author acer-pc
 *
 */
class ExpressionBuilder
{
	/**
	 * ��ͧ��ҧ�����ѧ�������������Ծ���
	 * @var ExpressionHole
	 */
	protected $available_hole;
	/**
	 * operator expression �������ش���١���������
	 * @var OperatorExpression
	 */
	protected $latest_oper_exp;
	/**
	 * expression sandbox ����� expression tree �����ѧ�١���ҧ���
	 * @var ExpressionSandbox
	 */
	protected $sandbox;
	/**
	 * operator �����ѧ�͡������
	 * @var Operator
	 */
	protected $pending_oper;
	
	public function __construct()
	{
		$this->sandbox = new ExpressionSandbox();
		$this->available_hole = $this->sandbox->hole;
	}
	
	/**
	 * �֧ expression ������ҧ��������
	 * @var Expression
	 */
	public function get_expr()
	{
		if(($exp = $this->sandbox->hole->expression) !== null)
		{
			$ret = $exp;
			$ret->parent = null;
			return $ret;
		}
		else
		{
			return null;
		}
	}
	/**
	 * �����Ծ���ŧ㹪�ͧ��ҧ
	 * @param mixed $exp ����ö�к���
	 * @return ����Դ�����Դ��Ҵ��� �Ф׹��Ҵѧ���
	 * EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER : ��ͧ��õ�Ǵ��Թ��ê�Դ infix ���� postfix
	 * EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER : ��ͧ��ùԾ��� ���͵�Ǵ��Թ��ê�Դ prefix
	 * ����ҡ����դ����Դ��Ҵ �Ф׹��� 0
	 */
	public function add_expression($exp)
	{
		//����� oper ��Դ infix �����ѧ�͡�ö١��� ��������ŧ������
		if($this->pending_oper !== null)
		{
			$prev_oper = $this->pending_oper;
			$this->pending_oper = null;
			if(($r = $this->internal_add_operator($prev_oper->get(I\OPER_INFIX))) !== 0) return $r;
		}
		
		if($this->available_hole !== null)
		{ //����ժ�ͧ��ҧ����Ѻ����Ծ���
			$this->available_hole->fill($exp);
			$this->available_hole = null;
			return 0;
		}
		else
		{ //�������ժ�ͧ��ҧ����
			throw new \Exception(null, EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER);
		}
	}
	/**
	 * ������Ǵ��Թ���
	 * @param Expression $exp
	 * @return ����Դ�����Դ��Ҵ��� �Ф׹��Ҵѧ���
	 * EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER : ��ͧ��õ�Ǵ��Թ��ê�Դ infix ���� postfix
	 * EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER : ��ͧ��ùԾ��� ���͵�Ǵ��Թ��ê�Դ prefix
	 * ����ҡ����դ����Դ��Ҵ �Ф׹��� 0
	 */
	public function add_operator($oper)
	{
		if($oper instanceof OperatorPolymorphism)
		{
			$prefix = $oper->has(I\OPER_PREFIX);
			$infix = $oper->has(I\OPER_INFIX);
			$postfix = $oper->has(I\OPER_POSTFIX);
			if($this->available_hole !== null)
			{
				//��� oper ��Դ�������ö�� prefix �� ����ѧ������ҧ���� �����Ѵ��Դ�� prefix
				if($prefix)
				{
					return $this->internal_add_operator($oper->get(I\OPER_PREFIX));
				}
				else
				{
					throw new \Exception(null, EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER);
				}
			}
			else
			{ //����������ҧ ���ͧ�Ѻ੾�� infix ��� postfix ��ҹ��
				if($infix && $postfix) //����繷�� infix ��� postfix
				{
					$this->pending_oper = $oper;
					return 0;
				}
				else if($infix && !$postfix) //����� infix ���ҧ����
				{
					return $this->internal_add_operator($oper->get(I\OPER_INFIX));
				}
				else if(!$infix && $postfix) //����� postfix ���ҧ����
				{
					return $this->internal_add_operator($oper->get(I\OPER_POSTFIX));
				}
				else //�����������ѡ���ҧ���
				{
					throw new \Exception(null, EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER);
				}
			}
		}
		else if($oper instanceof Operator)
		{
			return $this->internal_add_operator($oper);
		}
	}
	private function internal_add_operator($oper)
	{	
		$exp = new OperatorExpression($oper);
		if($oper->affix === I\OPER_PREFIX)
		{
			if(($r = $this->add_expression($exp)) !== 0) return $r;
			$this->available_hole = $exp->right;
		}	
		else if($oper->affix === I\OPER_INFIX || $oper->affix === I\OPER_POSTFIX)
		{
			//����ѧ�� oper ��Դ postfix ����͡�����ҧ���� ��������ҧ�����
			if($this->pending_oper !== null)
			{
				$prev_oper = $this->pending_oper;
				$this->pending_oper = null;
				if(($r = $this->internal_add_operator($prev_oper->get(I\OPER_POSTFIX))) !== 0) return $r;
			}
			//����ѧ������ҧ���� ���������ö������Ǵ��Թ���������
			if($this->available_hole !== null) 
				throw new \Exception(null, EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER);
			if($this->latest_oper_exp !== null)
			{
				//���º��º precedence �Ѻ��Ǵ��Թ��á�͹˹��
				$previous_oper_exp = $this->latest_oper_exp;
				compare_precedence:
				//��� precedence �ͧ��Ǵ��Թ��á�͹˹�ҹ���դ���ҡ���� ������ postfix operator
				if($previous_oper_exp->operator->precedence >= $oper->precedence
					|| $previous_oper_exp->operator->affix === I\OPER_POSTFIX)
				{
					//��ҹԾ����鹺��� OperatorExpression
					if($previous_oper_exp->parent instanceof OperatorExpression)
					{
						//����͹�������º��º precedence �Ѻ operator ��鹺��ա
						$previous_oper_exp = $previous_oper_exp->parent;
						goto compare_precedence;
					}
					else
					{ //��ҹԾ����鹺�������� OperatorExpression ����������͹���仪�鹺��ա
						//��Ѻ���Ծ����͹˹�ҡѺ�Ծ�������������������
						$previous_oper_exp->swap($exp);
						//�ӹԾ����͹˹���������ᢹ��ҧ���¢ͧ�Ծ������� (��Ѻ���͵���áѹ)
						$previous_oper_exp->left->fill($exp);
						//����¹���͵���á�Ѻ�׹
						$exp = $previous_oper_exp;
					}
				}
				else
				{ //��� precedence ���¡���
					//��ᢹ��Ңͧ�Ծ����͹˹����������Ѻᢹ���¢ͧ�Ծ�������
					$exp->left->fill($previous_oper_exp->right->expression);
					//����Ծ���������ҷ��ᢹ��Ңͧ�Ծ����������˹�͢��仨ҡ�Ծ����͹˹��
					$previous_oper_exp->right->fill($exp);
				}
			}
			else 
			{ //�������� operator expression ��͹˹�ҹ��
				//�� expression ������١�ͧ sandbox �������ᢹ���¢ͧ�Ծ�������
				$exp->left->fill($this->sandbox->hole->expression);
				//�ӹԾ�������仵�����١�ͧ sandbox ᷹
				$this->sandbox->hole->fill($exp);
			}
			
			if($oper->affix === I\OPER_INFIX)
			{
				//��ҹԾ��������� infix operator ����� available hole ��ѧ��ͧ��ҧ�ҧ��ҹ��Ңͧ operator
				$this->available_hole = $exp->right;
			}
		}
		else
		{
			throw new \Exception(null, EB_ERROR_UNKNOWN_OPER_AFFIX);
		}
		$this->latest_oper_exp = $exp;
		return 0;
	}
	/**
	 * ���� operator �����ѧ�͡������
	 */
	public function flush()
	{
		if($this->pending_oper !== null)
		{
			$prev_oper = $this->pending_oper;
			$this->pending_oper = null;
			return $this->internal_add_operator($prev_oper->get(I\OPER_POSTFIX));
		}
	}
	/**
	 * ��Ѻ价��ʶҹ��������
	 */
	public function reset()
	{
		$this->sandbox->hole->fill(null);
		$this->latest_oper_exp = null;
		$this->available_hole = $this->sandbox->hole;
	}
	
	public static function _test()
	{
		$l1 = new Literal(1, LITERAL_NUMBER);
		$l2 = new Literal(2, LITERAL_NUMBER);
		$l3 = new Literal(3, LITERAL_NUMBER);
		$l4 = new Literal(4, LITERAL_NUMBER);
		$l5 = new Literal(5, LITERAL_NUMBER);
		
		$o1 = new Operator(1, I\OPER_INFIX, 1);
		$o2 = new Operator(2, I\OPER_INFIX, 2);
		$o3 = new Operator(3, I\OPER_INFIX, 3);
		$o4 = new Operator(4, I\OPER_INFIX, 4);
		
		//----test case #1 : grouping test
		//build exp tree from "l1 o3 l2 o2 l3 o4 l4 o1 l5"----
		//----expected: "(((l1 o3 l2) o2 (l3 o4 l4)) o1 l5)"
		$B = new ExpressionBuilder();
		$B->add_expression($l1);
		$B->add_operator($o3);
		$B->add_expression($l2);
		$B->add_operator($o2);
		$B->add_expression($l3);
		$B->add_operator($o4);
		$B->add_expression($l4);
		$B->add_operator($o1);
		$B->add_expression($l5);
		$E = $B->get_expr();
		
		//traverse through the expression tree.
		assert($E->operator->id === 1 && $E->right->expression->calculate() === 5, "#1.1");
		$E_left = $E->left->expression;
		assert($E_left->operator->id === 2, "#1.2");
		$E_left_left = $E_left->left->expression;
		$E_left_right = $E_left->right->expression;
		assert($E_left_left->operator->id === 3 
				&& $E_left_left->left->expression->calculate() === 1
				&& $E_left_left->right->expression->calculate() === 2, "#1.3");
		assert($E_left_right->operator->id === 4 
				&& $E_left_right->left->expression->calculate() === 3
				&& $E_left_right->right->expression->calculate() === 4, "#1.4");
		
		//-----test case #2: grouping prefix/postfix operator-----
		//case: !@!1#$$ where precedence : ! > # > $ > @
		//expect: !(@((((!1)#)$)$))
		
		$oo1 = new Operator("!", I\OPER_PREFIX, 4);
		$oo2 = new Operator("#", I\OPER_POSTFIX, 3);
		$oo3 = new Operator("$", I\OPER_POSTFIX, 2);
		$oo4 = new Operator("@", I\OPER_PREFIX, 1);
		
		$l = new Literal(1, LITERAL_NUMBER);
		
		$E = new ExpressionBuilder();
		$E->add_operator($oo1); //add !
		//test add illegal operator.
		$r = $E->add_operator($oo2);
		assert($r === EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER, "#2.1");
		$E->add_operator($oo4); //add @
		$E->add_operator($oo1); //add !
		$E->add_expression($l); //add 1
		$E->add_operator($oo2); //add #
		//test add illegal operator.
		$r = $E->add_operator($oo1);
		assert($r === EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER, "#2.2");
		$E->add_operator($oo3); //add $
		$E->add_operator($oo3); //add $
		$E = $E->get_expr();
		
		//expect: !(@((((!1)#)$)$))
		assert($E->operator->id === "!", "#2.3");
		$E = $E->right->expression;
		assert($E->operator->id === "@", "#2.4");
		$E = $E->right->expression;
		assert($E->operator->id === "$", "#2.5");
		$E = $E->left->expression;
		assert($E->operator->id === "$", "#2.6");
		$E = $E->left->expression;
		assert($E->operator->id === "#", "#2.7");
		$E = $E->left->expression;
		assert($E->operator->id === "!", "#2.8");
		$E = $E->right->expression;
		assert($E->calculate() === 1, "#2.9");
		
		//-----test case #3: add operators with mixed affix type.-----
		//case: -2+3!+4^5*6 where - < + < * < ^ < !
		//expect: -((2+(3!))+((4^5)*6))
		
		$l2 = new Literal(2, LITERAL_NUMBER);
		$l3 = new Literal(3, LITERAL_NUMBER);
		$l4 = new Literal(4, LITERAL_NUMBER);
		$l5 = new Literal(5, LITERAL_NUMBER);
		$l6 = new Literal(6, LITERAL_NUMBER);
		
		$o1 = new Operator("-", I\OPER_PREFIX, 1);
		$o2 = new Operator("+", I\OPER_INFIX, 2);
		$o3 = new Operator("*", I\OPER_INFIX, 3);
		$o4 = new Operator("^", I\OPER_INFIX, 4);
		$o5 = new Operator("!", I\OPER_POSTFIX, 5);
		
		//-2+3!+4^5*6
		$B = new ExpressionBuilder();
		$B->add_operator($o1);
		$B->add_expression($l2);
		$B->add_operator($o2);
		$B->add_expression($l3);
		$B->add_operator($o5);
		$B->add_operator($o2);
		$B->add_expression($l4);
		$B->add_operator($o4);
		$B->add_expression($l5);
		$B->add_operator($o3);
		$B->add_expression($l6);
		$E = $B->get_expr();
		
		//-((2+(3!))+((4^5)*6))
		assert($E->operator->id === "-", "#3.1");
		$E = $E->right->expression;
		assert($E->operator->id === "+", "#3.2");
		$E1 = $E->left->expression;
		$E2 = $E->right->expression;
		assert($E1->operator->id === "+" && $E1->left->expression->calculate() === 2, "#3.3");
		assert($E2->operator->id === "*" && $E2->right->expression->calculate() === 6, "#3.4");
		$E12 = $E1->right->expression;
		$E21 = $E2->left->expression;
		assert($E21->operator->id === "^"
				&& $E21->left->expression->calculate() === 4
				&& $E21->right->expression->calculate() === 5, "#3.4");
		assert($E12->operator->id === "!"
				&& $E12->left->expression->calculate() === 3, "#3.5");
		
		//-----test case #4: polymorphism test-----
		//case: -2-3- where: -(post) > -(in) > -(pre)
		//expect: -(2-(3-))
		$o1 = new Operator(1, I\OPER_PREFIX, 1);
		$o2 = new Operator(2, I\OPER_INFIX, 2);
		$o3 = new Operator(3, I\OPER_POSTFIX, 3);
		$o = new OperatorPolymorphism($o1, $o2, $o3);
		
		$B = new ExpressionBuilder();
		$B->add_operator($o);
		$B->add_expression($l2);
		$B->add_operator($o);
		$B->add_expression($l3);
		$B->add_operator($o);
		$B->flush();
		$E = $B->get_expr();
		
		//focus: -*(2-(3-))
		assert($E->operator->id === 1, "#4.1");
		$E2 = $E->right->expression;
		//focus: -(2-*(3-))
		assert($E2->operator->id === 2, "#4.2");
		$E21 = $E2->left->expression;
		$E22 = $E2->right->expression;
		//focus: -(2*-(3-*))
		assert($E21->calculate() === 2 && $E22->operator->id === 3, "#4.3");
		$E221 = $E22->left->expression;
		//focus: -(2-(3*-))
		assert($E221->calculate() === 3, "#4.4");
	}
}

const STRESC_MODE_NONE = 0;
const STRESC_MODE_HEX = 1;
const STRESC_MODE_OCT = 2;
/**
 * ���ʹ����繵�ǹӷҧ㹡�� escape string
 * @author acer-pc
 *
 */
class StringEscapingGuide
{
	/**
	 * current mode of escaping (STRESC_HEX or STRESC_OCT or STRESC_NONE)
	 * @var int
	 */
	private $mode = 0;
	/**
	 * read string
	 * @var string
	 */
	private $str = "";

	const hex_pattern = "/^[A-Fa-f0-9]$/";
	const oct_pattern = "/^[0-7]$/";

	/**
	 * ��͹����ѡ��
	 * @param string $current_char ����ѡ�ûѨ�غѹ
	 * @param string $next_char ����ѡ�öѴ�
	 * @return �׹��� false ������ѧ�������ö escape �� ��ͧ��õ���ѡ�öѴ�, �׹��� string ����ѡ�� �ҡ escape ���������
	 */
	public function feed($current_char, $next_char)
	{
		$this->str .= $current_char;
		if($this->mode === STRESC_MODE_HEX)
		{
			if(!preg_match(self::hex_pattern, $next_char) || strlen($this->str) === 3)
			{
				return $this->summarize();
			}
			else
			{
				return false;
			}
		}
		elseif($this->mode === STRESC_MODE_OCT)
		{
			if(!preg_match(self::oct_pattern, $next_char) || strlen($this->str) === 3)
			{
				return $this->summarize();
			}
			else
			{
				return false;
			}
		}
		elseif($current_char === "x" && preg_match(self::hex_pattern, $next_char))
		{
			$this->mode = STRESC_MODE_HEX;
			return false;
		}
		elseif(preg_match(self::oct_pattern, $current_char)
				&& preg_match(self::oct_pattern, $next_char))
		{
			$this->mode = STRESC_MODE_OCT;
			return false;
		}
		else
		{
			return $this->summarize();
		}
	}

	private function summarize()
	{
		$ret = stripcslashes("\\".$this->str);
		$this->str = "";
		$this->mode = STRESC_MODE_NONE;
		return $ret;
	}

	public static function _test()
	{
		//----test #1 : escape non hex/oct string----
		$esc = new StringEscapingGuide();
		assert($esc->feed("n", "x") === "\n", "#1.1");
		assert($esc->feed("t", "x") === "\t", "#1.2");
		//----test #2 : escape hex string----
		//case : \x0A
		$esc->feed("x", "0");
		$esc->feed("0", "B");
		$r = $esc->feed("B", "C");
		assert($r === "\x0B", "#2.1");
		//case : \x2k -> (\x2)
		$esc->feed("x", "2");
		$r = $esc->feed("2", "k");
		assert($r === "\x2", "#2.2");
		//----test #3 : escape oct string----
		//case : \072
		$esc->feed("0", "7");
		$esc->feed("7", "2");
		$r = $esc->feed("2", "5");
		assert($r === "\072", "#3.1");
		//case : \32e -> (\32)
		$esc->feed("3", "2");
		$r = $esc->feed("2", "e");
		assert($r === "\32", "#2.2");
	}
}