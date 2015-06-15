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
 * เป็นที่รองรับนิพจน์ในระหว่างการสร้างโดย ExpressionBuilder
 * @author acer-pc
 *
 */
class ExpressionSandbox extends Expression
{
	/**
	 * ช่องว่างสำหรับเก็บ Expression
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
 * คลาสนี้จะช่วยในการสร้าง Expression tree
 * @author acer-pc
 *
 */
class ExpressionBuilder
{
	/**
	 * ช่องว่างที่กำลังเว้นไว้ให้เติมนิพจน์
	 * @var ExpressionHole
	 */
	protected $available_hole;
	/**
	 * operator expression ตัวล่าสุดที่ถูกเพิ่มเข้ามา
	 * @var OperatorExpression
	 */
	protected $latest_oper_exp;
	/**
	 * expression sandbox ที่เก็บ expression tree ที่กำลังถูกสร้างขึ้น
	 * @var ExpressionSandbox
	 */
	protected $sandbox;
	/**
	 * operator ที่กำลังรอการเพิ่ม
	 * @var Operator
	 */
	protected $pending_oper;
	
	public function __construct()
	{
		$this->sandbox = new ExpressionSandbox();
		$this->available_hole = $this->sandbox->hole;
	}
	
	/**
	 * ดึง expression ที่สร้างเสร็จแล้ว
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
	 * เพิ่มนิพจน์ลงในช่องว่าง
	 * @param mixed $exp สามารถระบุเป็น
	 * @return ถ้าเกิดความผิดพลาดขึ้น จะคืนค่าดังนี้
	 * EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER : ต้องการตัวดำเนินการชนิด infix หรือ postfix
	 * EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER : ต้องการนิพจน์ หรือตัวดำเนินการชนิด prefix
	 * ถ้าหากไม่มีความผิดพลาด จะคืนค่า 0
	 */
	public function add_expression($exp)
	{
		//ถ้ามี oper ชนิด infix ที่กำลังรอการถูกเติม ก็ให้เติมลงไปได้เลย
		if($this->pending_oper !== null)
		{
			$prev_oper = $this->pending_oper;
			$this->pending_oper = null;
			if(($r = $this->internal_add_operator($prev_oper->get(I\OPER_INFIX))) !== 0) return $r;
		}
		
		if($this->available_hole !== null)
		{ //ถ้ามีช่องว่างสำหรับเติมนิพจน์
			$this->available_hole->fill($exp);
			$this->available_hole = null;
			return 0;
		}
		else
		{ //ถ้าไม่มีช่องว่างแล้ว
			throw new \Exception(null, EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER);
		}
	}
	/**
	 * เพิ่มตัวดำเนินการ
	 * @param Expression $exp
	 * @return ถ้าเกิดความผิดพลาดขึ้น จะคืนค่าดังนี้
	 * EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER : ต้องการตัวดำเนินการชนิด infix หรือ postfix
	 * EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER : ต้องการนิพจน์ หรือตัวดำเนินการชนิด prefix
	 * ถ้าหากไม่มีความผิดพลาด จะคืนค่า 0
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
				//ถ้า oper ชนิดนี้สามารถเป็น prefix ได้ และยังมีรูว่างอยู่ ก็ให้จัดชนิดเป็น prefix
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
			{ //ถ้ารูไม่ว่าง จะรองรับเฉพาะ infix และ postfix เท่านั้น
				if($infix && $postfix) //ถ้าเป็นทั้ง infix และ postfix
				{
					$this->pending_oper = $oper;
					return 0;
				}
				else if($infix && !$postfix) //ถ้าเป็น infix อย่างเดียว
				{
					return $this->internal_add_operator($oper->get(I\OPER_INFIX));
				}
				else if(!$infix && $postfix) //ถ้าเป็น postfix อย่างเดียว
				{
					return $this->internal_add_operator($oper->get(I\OPER_POSTFIX));
				}
				else //ถ้าไม่ได้เป็นสักอย่างเลย
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
			//ถ้ายังมี oper ชนิด postfix ที่รอการสร้างอยู่ ก็ให้สร้างได้เลย
			if($this->pending_oper !== null)
			{
				$prev_oper = $this->pending_oper;
				$this->pending_oper = null;
				if(($r = $this->internal_add_operator($prev_oper->get(I\OPER_POSTFIX))) !== 0) return $r;
			}
			//ถ้ายังมีรูว่างอยู่ จะไม่สามารถเพิ่มตัวดำเนินการใหม่ได้
			if($this->available_hole !== null) 
				throw new \Exception(null, EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER);
			if($this->latest_oper_exp !== null)
			{
				//เปรียบเทียบ precedence กับตัวดำเนินการก่อนหน้า
				$previous_oper_exp = $this->latest_oper_exp;
				compare_precedence:
				//ถ้า precedence ของตัวดำเนินการก่อนหน้านี้มีค่ามากกว่า หรือเป็น postfix operator
				if($previous_oper_exp->operator->precedence >= $oper->precedence
					|| $previous_oper_exp->operator->affix === I\OPER_POSTFIX)
				{
					//ถ้านิพจน์ชั้นบนเป็น OperatorExpression
					if($previous_oper_exp->parent instanceof OperatorExpression)
					{
						//เลื่อนขึ้นไปเปรียบเทียบ precedence กับ operator ชั้นบนอีก
						$previous_oper_exp = $previous_oper_exp->parent;
						goto compare_precedence;
					}
					else
					{ //ถ้านิพจน์ชั้นบนไม่ได้เป็น OperatorExpression ก็จะไม่เลื่อนขึ้นไปชั้นบนอีก
						//สลับที่นิพจน์ก่อนหน้ากับนิพจน์ที่จะเพิ่มเข้ามาใหม่
						$previous_oper_exp->swap($exp);
						//นำนิพจน์ก่อนหน้าไปเติมที่แขนข้างซ้ายของนิพจน์ใหม่ (สลับชื่อตัวแปรกัน)
						$previous_oper_exp->left->fill($exp);
						//เปลี่ยนชื่อตัวแปรกลับคืน
						$exp = $previous_oper_exp;
					}
				}
				else
				{ //ถ้า precedence น้อยกว่า
					//นำแขนขวาของนิพจน์ก่อนหน้ามาเติมให้กับแขนซ้ายของนิพจน์ใหม่
					$exp->left->fill($previous_oper_exp->right->expression);
					//เติมนิพจน์ใหม่เข้าที่แขนขวาของนิพจน์ที่อยู่เหนือขึ้นไปจากนิพจน์ก่อนหน้า
					$previous_oper_exp->right->fill($exp);
				}
			}
			else 
			{ //ถ้าไม่มี operator expression ก่อนหน้านี้
				//นำ expression ที่เป็นลูกของ sandbox มาใส่ที่แขนซ้ายของนิพจน์ใหม่
				$exp->left->fill($this->sandbox->hole->expression);
				//นำนิพจน์ใหม่ไปตั้งเป็นลูกของ sandbox แทน
				$this->sandbox->hole->fill($exp);
			}
			
			if($oper->affix === I\OPER_INFIX)
			{
				//ถ้านิพจน์ใหม่เป็น infix operator ให้ชี้ available hole ไปยังช่องว่างทางด้านขวาของ operator
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
	 * เพิ่ม operator ที่กำลังรอการเพิ่ม
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
	 * กลับไปที่สถานะเริ่มต้น
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
 * คลาสนี้จะเป็นตัวนำทางในการ escape string
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
	 * ป้อนตัวอักษร
	 * @param string $current_char ตัวอักษรปัจจุบัน
	 * @param string $next_char ตัวอักษรถัดไป
	 * @return คืนค่า false เมื่อยังไม่สามารถ escape ได้ ต้องการตัวอักษรถัดไป, คืนค่า string ตัวอักษร หาก escape สำเร็จแล้ว
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