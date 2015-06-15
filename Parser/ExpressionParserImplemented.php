<?php
namespace Chassis\Parser;

use Chassis\Parser as P;
use Chassis\Parser\ExpressionBuilder;
use Chassis\Parser\OPER_PREFIX;
use Chassis\Parser\OPER_INFIX;
use Chassis\Parser\StateScanner;
use Chassis\Parser\State;
use Chassis\Parser\OperatorPolymorphism;
use Chassis\Parser\Operator;
use Chassis\Parser\STATE_PRE_INTERMEDIATE;
use Chassis\Parser\Transition;
use Chassis\Parser\LITERAL_STRING;
use Chassis\Parser\LITERAL_BOOLEAN;
use Chassis\Parser\LITERAL_NUMBER;
use Chassis\Parser\ExpectationTreeNode;
use Chassis\Parser\SC_STATE_WORKING;
use Chassis\Parser\SC_STATE_FINALIZING;
use Chassis\Parser\Error;
use Chassis\Parser\EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER;
use Chassis\Parser\EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER;
use Chassis\Parser\SC_STATE_DEAD;
use Chassis\Parser\IBlankScanner;
use Chassis\Parser\Implementation\OPER_FUNC_CALL;
use Chassis\Intermediate as I;
use Chassis\Intermediate\Expression;
use Chassis\Intermediate\Literal;
use Chassis\Intermediate\Closure;
use Chassis\Intermediate\OperatorExpression;
use Chassis\Intermediate\VariableExpression;

include_once __DIR__."/ExpressionParser.php";
include_once __DIR__."/ScannerDriver.php";
include_once __DIR__."/ExecutionParser.php";
include_once __DIR__."/../Intermediate/Operators.php";
include_once __DIR__."/../Intermediate/Expression.php";

class OperList
{
	public static $list = [];
	public static $oper_str = [];
}

OperList::$list['NOT'] = new I\Operator_Not();
OperList::$list['AND'] = new I\Operator_And();
OperList::$list['OR'] = new I\Operator_Or();

OperList::$list['EQUAL'] = new I\Operator_Equal();
OperList::$list['LESS_THAN'] = new I\Operator_LessThan();
OperList::$list['GREATER_THAN'] = new I\Operator_GreaterThan();
OperList::$list['LESS_THAN_OR_EQUAL'] = new I\Operator_LessThanOrEqual();
OperList::$list['GREATER_THAN_OR_EQUAL'] = new I\Operator_GreaterThanOrEqual();

OperList::$list['NEGATIVE'] = new I\Operator_Negative();
OperList::$list['MULTIPLY'] = new I\Operator_Multiply();
OperList::$list['DIVIDE'] = new I\Operator_Divide();
OperList::$list['ADD'] = new I\Operator_Add();
OperList::$list['SUBTRACT'] = new I\Operator_Subtract();
OperList::$list['MOD'] = new I\Operator_Modulo();

OperList::$list['FUNC_CALL'] = new I\Operator_FunctionCall();

OperList::$list['ARRAY_RETRIEVAL'] = new I\Operator_GetArrayValue();

OperList::$oper_str['!'] = OperList::$list['NOT'];
OperList::$oper_str['&&'] = OperList::$list['AND'];
OperList::$oper_str['||'] = OperList::$list['OR'];
OperList::$oper_str['=='] = OperList::$list['EQUAL'];
OperList::$oper_str['<'] = OperList::$list['LESS_THAN'];
OperList::$oper_str['>'] = OperList::$list['GREATER_THAN'];
OperList::$oper_str['<='] = OperList::$list['LESS_THAN_OR_EQUAL'];
OperList::$oper_str['>='] = OperList::$list['GREATER_THAN_OR_EQUAL'];
OperList::$oper_str['-'] = new I\OperatorPolymorphism(OperList::$list['NEGATIVE'], OperList::$list['SUBTRACT']);
OperList::$oper_str['*'] = OperList::$list['MULTIPLY'];
OperList::$oper_str['/'] = OperList::$list['DIVIDE'];
OperList::$oper_str['+'] = OperList::$list['ADD'];
OperList::$oper_str['%'] = OperList::$list['MOD'];
OperList::$oper_str['.'] = OperList::$list['ARRAY_RETRIEVAL'];

const EP_ERROR_ILLEGAL_SYMBOL = 1;
const EP_ERROR_EXPECTED_STR_DELIMITER = 2;
const EP_ERROR_EXPECTED_CLOSE_PARENTHESIS = 3;
const EP_ERROR_EXPECTED_CLOSE_CURLY_BRACE = 4;
const EP_ERROR_REQUIRED_EXP_OR_PREFIX_OPER = 5;
const EP_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER = 6;

class ExclusiveExpressionBuilder extends ExpressionBuilder
{
	public function add_expression($exp)
	{
		//ถ้า closure ถูกเพิ่มมา โดยที่ไม่มีช่องว่างสำหรับนิพจน์ แสดงว่าเป็นการเรียกฟังก์ชัน
		if($this->available_hole === null && $exp instanceof Closure)
		{
			if(($r = parent::add_operator(OperList::$list['FUNC_CALL'])) !== 0) return $r;
		}
		return parent::add_expression($exp);
	}
}

class ExpressionScanner extends StateScanner implements IBlankScanner
{
	private $exp_builder;

	private $ground;
	private $str_read;
	private $closure_read;
	private $id_read;
	private $number_read;
	private $id_exp_read;

	public function __construct($parent)
	{
		$this->exp_builder = new ExclusiveExpressionBuilder();

		$this->ground = new State();
		$this->str_read = new State();
		$this->closure_read = new State();
		$this->id_read = new State();
		$this->number_read = new State();
		$this->oper_read = new State();
		$this->id_exp_read = new State();

		$this->initial_state = $this->ground;

		//-----declaring central variable for every state.-----
		$tmp_str = "";
		$sub_exp_scanner = null; //ตัวสแกนนิพจน์ย่อย **เมื่อใช้งานเสร็จแล้วไม่ต้อง reset และ initialize**
		$use_sub_exp_scanner = function() use (&$sub_exp_scanner)
		{
			if($sub_exp_scanner === null)
			{
				$sub_exp_scanner = new ExpressionScanner($this);
			}
			else
			{
				$sub_exp_scanner->reset();
			}
			$sub_exp_scanner->initialize();
		};

		//-----constructing the ground state.-----
		$whitespaces = [" " => "ws", "\n" => "ws", "\r\n" => "ws"];
		$str_delimiter = ["\"" => "str", "'" => "str"];
		$operators = OperList::$oper_str;

		$this->ground->expectation_tree =
		ExpectationTreeNode::create(array_merge($whitespaces, $str_delimiter, $operators));
		$this->ground->intermediate_mode = STATE_PRE_INTERMEDIATE;
		$this->ground->operation = function($transition, $exp_result)
		{
			if($this->state === P\SC_STATE_WORKING)
			{
				//expectation tag.
				$exp_tag = $exp_result['tag'];
				$exp_sym = $exp_result['symbol'];
				if($exp_tag === "ws") //detecting whitespace.
				{
					//no action
				}
				elseif($exp_tag === "str") //detecting string delimiter.
				{
					$this->ground->next_transition = new Transition($this->str_read);
				}
				elseif($exp_tag instanceof I\Operator 
						|| $exp_tag instanceof I\OperatorPolymorphism) //detecting operator.
				{
					$this->_add_operator($exp_tag);
				}
				elseif($exp_sym === "(") //closure
				{
					$this->ground->next_transition = new Transition($this->closure_read);
				}
				elseif($exp_sym === "{") //เป็นชื่อตัวแปรที่ถูกระบุโดย expression (เช่น var1 มีค่าเท่ากับ {"var1"})
				{
					$this->ground->next_transition = new Transition($this->id_exp_read);
				}
				elseif(preg_match("/^[A-Za-z_]+$/", $exp_sym)) //identifier
				{
					$this->ground->next_transition = new Transition($this->id_read);
				}
				elseif(preg_match("/^[0-9]$/", $exp_sym)) //number
				{
					$this->ground->next_transition = new Transition($this->number_read);
				}
				else
				{
					$this->suicide(new Error($this, EP_ERROR_ILLEGAL_SYMBOL));
				}
			}
			elseif ($this->state === P\SC_STATE_FINALIZING)
			{
				$this->exp_builder->flush();
			}
		};

		//----- string read state -----
		$str_delimiter = "";
		$str_escaping = false;
		$str_escaping_guide = new StringEscapingGuide();
		$this->str_read->operation = function($transition)
		use (&$tmp_str, &$str_delimiter, &$str_escaping, &$str_escaping_guide)
		{
			if($this->state === P\SC_STATE_WORKING)
			{
				if($transition->first)
				{
					$str_delimiter = $this->get_current_char();
				}
				else if($str_escaping)
				{
					if($e = $str_escaping_guide->feed($this->get_current_char(), $this->peek_ahead()))
					{
						$str_escaping = false;
						$tmp_str .= $e;
					}
				}
				else if($this->get_current_char() === $str_delimiter)
				{
					$this->_add_expression(new Literal($tmp_str, I\LITERAL_STRING));
						
					$this->str_read->next_transition = new Transition($this->ground);
					$tmp_str = "";
				}
				else if($this->get_current_char() === "\\")
				{
					$str_escaping = true;
				}
				else
				{
					$tmp_str .= $this->get_current_char();
				}
			}
			else
			{
				$this->suicide(new Error($this, EP_ERROR_EXPECTED_STR_DELIMITER));
			}
		};

		//----- identifier read state-----
		$this->id_read->operation = function($transition)
		use (&$tmp_str)
		{
			$tmp_str .= $this->get_current_char();
			//peek at the next char. if it isn't letter, digit or underscore, end this state.
			if(!preg_match("/^[A-Za-z0-9_]$/", $this->peek_ahead()))
			{
				if(strtolower($tmp_str) === "true" || strtolower($tmp_str) === "false")
				{
					$this->_add_expression(new Literal($tmp_str, I\LITERAL_BOOLEAN));
				}
				else
				{
					$this->exp_builder->add_expression(new VariableExpression($tmp_str));
				}
				$this->id_read->next_transition = new Transition($this->ground);
				$tmp_str = "";
			}
		};

		//-----number read state-----
		$after_dot = false;
		$this->number_read->operation = function($transition) use (&$tmp_str, &$after_dot)
		{
			$tmp_str .= ($c = $this->get_current_char());
			if($c === ".") $after_dot = true;
			if(!preg_match("/^[0-9".(!$after_dot ? preg_quote(".") : "")."]$/", $this->peek_ahead()))
			{
				$this->_add_expression(new Literal($tmp_str, I\LITERAL_NUMBER));
				$this->number_read->next_transition = new Transition($this->ground);
				$tmp_str = "";
			}
		};

		//----- closure read state -----
		$closure = null;
		//เอาไว้เพิ่ม exp ใหม่ที่อ่านได้ลงใน closure
		$add_exp = function() use (&$sub_exp_scanner, &$closure)
		{
			$sub_exp_scanner->finalize();
			//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
			if($sub_exp_scanner->state === P\SC_STATE_DEAD)
			{
				$this->suicide($sub_exp_scanner->error);
			}
			else
			{
				$new_exp = $sub_exp_scanner->summarize();
				if($new_exp !== null) $closure->add_expr($new_exp);
			}
		};
		$this->closure_read->operation = function($transition)
		use (&$sub_exp_scanner, &$closure, &$add_exp, $use_sub_exp_scanner)
		{
			$c = "";
			if($this->state === P\SC_STATE_WORKING)
			{
				if($transition->first)
				{
					$closure = new Closure();
					$use_sub_exp_scanner();
				}
				elseif ($sub_exp_scanner->in_ground_state()
						&& (($c = $this->get_current_char()) === ",") || $c === ")")
				{
					switch ($c)
					{
						case ",":
							$add_exp();
							$sub_exp_scanner->reset();
							$sub_exp_scanner->initialize();
							break;
						case ")":
							$add_exp();
							$this->_add_expression($closure);
								
							$closure = null;
								
							$this->closure_read->next_transition = new Transition($this->ground);
							break;
					}
				}
				else
				{
					$sub_exp_scanner->advance_here();
					//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
					if($sub_exp_scanner->state === P\SC_STATE_DEAD)
					{
						$this->suicide($sub_exp_scanner->error);
					}
				}
			}
			else
			{
				$sub_exp_scanner->finalize();
				//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
				if($sub_exp_scanner->state === P\SC_STATE_DEAD)
				{
					$this->suicide($sub_exp_scanner->error);
				}
				else
				{
					$this->suicide(new Error($this, EP_ERROR_EXPECTED_CLOSE_PARENTHESIS));
				}
			}
		};

		//-----expression identifier read state-----
		$this->id_exp_read->operation = function($transition)
		use(&$sub_exp_scanner, &$use_sub_exp_scanner)
		{
			if($this->state === P\SC_STATE_WORKING)
			{
				if($transition->first)
				{
					$use_sub_exp_scanner();
				}
				elseif($this->get_current_char() === "}"
						&& $sub_exp_scanner->in_ground_state())
				{ //เมื่อพบตัวอักษร } ให้หยุดการอ่านนิพจน์ และเพิ่ม VariableExpression ลงใน expressionBuilder
					$sub_exp_scanner->finalize();
					$this->_add_expression(
							new VariableExpression($sub_exp_scanner->summarize()));
					$this->id_exp_read->next_transition = new Transition($this->ground);
				}
				else
				{ //อ่านต่อไป
					$sub_exp_scanner->advance_here();
					//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
					if($sub_exp_scanner->state === P\SC_STATE_DEAD)
					{
						$this->suicide($sub_exp_scanner->error);
					}
				}
			}
			else
			{
				$this->suicide(new Error($this, EP_ERROR_EXPECTED_CLOSE_CURLY_BRACE));
			}
		};

		parent::__construct($parent);
	}

	public function _summarize()
	{
		return $this->exp_builder->get_expr();
	}

	/**
	 * ตรวจสอบว่าอยู่ใน ground state หรือไม่
	 * @return boolean
	 */
	public function in_ground_state()
	{
		return $this->get_next_state() === $this->ground;
	}
	/**
	 * (non-PHPdoc)
	 * @see \Chassis\Parser\Scanner::reset()
	 */
	public function reset()
	{
		parent::reset();
		$this->exp_builder->reset();
	}
	/**
	 * แปลง error ที่แจ้งมาจาก ExpressionBuilder ให้เป็น error ในรูปที่จะแจ้งออกไปหาผู้ใช้
	 * @param int $err
	 */
	private function _create_exp_builder_error($err)
	{
		switch($err)
		{
			case P\EB_ERROR_REQUIRED_EXP_OR_PREFIX_OPER:
				$err = EP_ERROR_REQUIRED_EXP_OR_PREFIX_OPER;
				break;
			case P\EB_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER:
				$err = EP_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER;
				break;
		}
		return new Error($this, $err);
	}
	/**
	 * เพิ่มนิพจน์ลงใน ExpressionBuilder
	 * @param Expression $exp
	 */
	private function _add_expression($exp)
	{
		try
		{
			$this->exp_builder->add_expression($exp);
		}
		catch(\Exception $e)
		{
			$this->suicide($this->_create_exp_builder_error($e->getCode()));
		}
	}
	/**
	 * เพิ่มตัวดำเนินการลงไปบน ExpressionBuilder
	 * @param Operator $oper
	 */
	private function _add_operator($oper)
	{
		try
		{
			$this->exp_builder->add_operator($oper);
		}
		catch(\Exception $e)
		{
			$this->suicide($this->_create_exp_builder_error($e->getCode()));
		}
	}

	public static function _test()
	{
		$driver = new P\ScannerDriver();
		$sc = new ExpressionScanner($driver);

		//----case 1: parse literals-----
		$driver->str = "\"abcdefg\"";
		$e = $driver->start();
		assert($e instanceof Literal
		&& $e->type === I\LITERAL_STRING
		&& $e->calculate() === "abcdefg", "#1.1 parse literals");

		$driver->str = "'hijklmnop'";
		$e = $driver->start();
		assert($e instanceof Literal
		&& $e->type === I\LITERAL_STRING
		&& $e->calculate() === "hijklmnop", "#1.2 parse literals");

		$driver->str = "123";
		$e = $driver->start();
		assert($e instanceof Literal
		&& $e->type === I\LITERAL_NUMBER
		&& $e->calculate() === 123, "#1.3 parse literals");

		$driver->str = "123.456";
		$e = $driver->start();
		assert($e instanceof Literal
		&& $e->type === I\LITERAL_NUMBER
		&& $e->calculate() === 123.456, "#1.4 parse literals");

		$driver->str = "true";
		$e = $driver->start();
		assert($e instanceof Literal
		&& $e->type === I\LITERAL_BOOLEAN
		&& $e->calculate() === true, "#1.5 parse literals");

		$driver->str = "false";
		$e = $driver->start();
		assert($e instanceof Literal
		&& $e->type === I\LITERAL_BOOLEAN
		&& $e->calculate() === false, "#1.6 parse literals");

		//----case 2: test operator grouping of the same precedence-----
		//"1+2+3+4" -> "((1+2)+3)+4"
		$driver->str = "1+2+3+4";
		$e = $driver->start();
		assert($e instanceof OperatorExpression
		&& $e->operator->id === I\OPER_ADD
		&& $e->right->expression->calculate() === 4, "#2.1 oper grouping");

		$e = $e->left->expression;
		assert($e instanceof OperatorExpression
		&& $e->operator->id === I\OPER_ADD
		&& $e->right->expression->calculate() === 3, "#2.2 oper grouping");

		$e = $e->left->expression;
		assert($e instanceof OperatorExpression
		&& $e->operator->id === I\OPER_ADD
		&& $e->right->expression->calculate() === 2
		&& $e->left->expression->calculate() === 1, "#2.3 oper grouping");

		//----case 3: test operator grouping of different precedence (only infixs)----
		//"1*2+3/4%5-6*7-8" -> "(((1*2)+(3/4))%((5-(6*7))-8))"
		$driver->str = "1*2+3/4%5-6*7-8";

		$e = $driver->start();
		assert($e instanceof OperatorExpression
		&& $e->operator->id === I\OPER_MOD
		, "#3.1 oper grouping");

		$e1 = $e->left->expression;
		$e11 = $e1->left->expression;
		$e111 = $e11->left->expression;
		$e112 = $e11->right->expression;
		$e12 = $e1->right->expression;
		$e122 = $e12->right->expression;
		$e121 = $e12->left->expression;

		$e2 = $e->right->expression;
		$e21 = $e2->left->expression;
		$e211 = $e21->left->expression;
		$e212 = $e21->right->expression;
		$e2121 = $e212->left->expression;
		$e2122 = $e212->right->expression;
		$e22 = $e2->right->expression;

		//"(((1*2)+(3/4))%((5-(6*7))-8))"
		assert($e1->operator->id === I\OPER_ADD, "#3.2 oper grouping");
		assert($e11->operator->id === I\OPER_MULTIPLY
		&& $e111->calculate() === 1
		&& $e112->calculate() === 2, "#3.3 oper grouping");
		assert($e12->operator->id === I\OPER_DIVIDE
		&& $e121->calculate() === 3
		&& $e122->calculate() === 4, "#3.4 oper grouping");
		assert($e2->operator->id === I\OPER_SUBTRACT
		&& $e22->calculate() === 8, "#3.5 oper grouping");
		assert($e21->operator->id === I\OPER_SUBTRACT
		&& $e211->calculate() === 5, "#3.6 oper grouping");
		assert($e212->operator->id === I\OPER_MULTIPLY
		&& $e2121->calculate() === 6
		&& $e2122->calculate() === 7, "#3.7 oper grouping");

		//----case #4 : test operator polymorphism----
		//-1-2 -> (-1)-2
		$driver->str = "-1-2";
		$e = $driver->start();
		$e1 = $e->left->expression;
		$e12 = $e1->right->expression;
		$e2 = $e->right->expression;

		assert($e->operator->id === I\OPER_SUBTRACT, "#4.1 oper polymorphism");
		assert($e2->calculate() === 2, "#4.2 oper polymorphism");
		assert($e1->operator->id === I\OPER_NEGATIVE, "#4.3 oper polymorphism");
		assert($e12->calculate() === 1, "#4.4 oper polymorphism");

		//----case #5 : test identifier parsing----//
		//id1 && id2
		$driver->str = "id1 && id2";
		$e = $driver->start();
		$e1 = $e->left->expression;
		$e2 = $e->right->expression;

		assert($e->operator->id === I\OPER_AND, "#5.1 identifier parsing");
		assert($e1 instanceof VariableExpression
		&& $e1->get_var_name() === "id1", "#5.2 identifier parsing");
		assert($e2 instanceof VariableExpression
		&& $e2->get_var_name() === "id2", "#5.3 identifier parsing");

		//----case #6 : test closure parsing----
		//(1,2.3,"456",true,false,var1,(1,2,3))
		$driver->str = "(1,2.3,\"456\", tRue,faLsE , _var1 ,( 1 ,2, 3))";
		$e = $driver->start();
		assert($e instanceof Closure, "#6.1 closure parsing");
		assert($e->expr_list[0] instanceof Literal
		&& $e->expr_list[0]->calculate() === 1, "#6.2 closure parsing");
		assert($e->expr_list[1] instanceof Literal
		&& $e->expr_list[1]->calculate() === 2.3, "#6.3 closure parsing");
		assert($e->expr_list[2] instanceof Literal
		&& $e->expr_list[2]->calculate() === "456", "#6.4 closure parsing");
		assert($e->expr_list[3] instanceof Literal
		&& $e->expr_list[3]->calculate() === true, "#6.5 closure parsing");
		assert($e->expr_list[4] instanceof Literal
		&& $e->expr_list[4]->calculate() === false, "#6.6 closure parsing");
		assert($e->expr_list[5] instanceof VariableExpression
		&& $e->expr_list[5]->get_var_name() === "_var1", "#6.7 closure parsing");
		assert($e->expr_list[6]->expr_list[0] instanceof Literal
		&& $e->expr_list[6]->expr_list[0]->calculate() === 1, "#6.8 closure parsing");
		assert($e->expr_list[6]->expr_list[1] instanceof Literal
		&& $e->expr_list[6]->expr_list[1]->calculate() === 2, "#6.9 closure parsing");
		assert($e->expr_list[6]->expr_list[2] instanceof Literal
		&& $e->expr_list[6]->expr_list[2]->calculate() === 3, "#6.10 closure parsing");

		//----test #7 : function calling----
		//a(b,c)
		$driver->str = "a(b,c)";
		$e = $driver->start();
		$e1 = $e->left->expression;
		$e2 = $e->right->expression;

		assert($e instanceof OperatorExpression, "#7.1 function calling");
		assert($e1->get_var_name() === "a", "#7.2 function calling");
		assert($e2->expr_list[0]->get_var_name() === "b", "#7.3 function calling");
		assert($e2->expr_list[1]->get_var_name() === "c", "#7.4 function calling");

		//----test #8 : name variable by expression----
		//{"234"}
		$driver->str = '{"2{3}4"}';
		$e = $driver->start();
		assert($e instanceof VariableExpression
		&& $e->get_var_name() === "2{3}4", "#8.1 name variable by expression");

		$driver->str = '{12}';
		$e = $driver->start();
		assert($e instanceof VariableExpression
		&& $e->get_var_name() === 12, "#8.2 name variable by expression");

		//----test #9 : error detection test----
		//123#456 : illegal char
		$driver->str = "123#456";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_ILLEGAL_SYMBOL
		, "#9.1 illegal char");

		//"3414 : string with no close delimiter.
		$driver->str = "\"123456'";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_EXPECTED_STR_DELIMITER
		, "#9.2 string with no close delimiter");

		//(234,567 : closure with no close delimiter.
		$driver->str = "(234,567";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_EXPECTED_CLOSE_PARENTHESIS
		, "#9.3 closure with no close delimiter");

		//{123456 : expression-named variable with no close delimiter.
		$driver->str = "{123456";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_EXPECTED_CLOSE_CURLY_BRACE
		, "#9.4 expression-named variable with no close delimiter");

		//+123 : misplaced operator.
		$driver->str = "+123";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_REQUIRED_EXP_OR_PREFIX_OPER
		, "#9.5 misplaced operator.");

		//123 456 : misplaced expression.
		$driver->str = "123 456";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_REQUIRED_INFIX_OR_POSTFIX_OPER
		, "#9.6 misplaced expression.");


		//-+ : misplaced operator.
		$driver->str = "-+";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_REQUIRED_EXP_OR_PREFIX_OPER
		, "#9.7 misplaced operator.");

		//(2#3) : error in closure
		$driver->str = "(2#3)";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_ILLEGAL_SYMBOL
		, "#9.8 error in closure");

		//('wer) error in closure
		$driver->str = "('wer)";
		$e = $driver->start();
		assert($e === false && $driver->last_error->code === EP_ERROR_EXPECTED_STR_DELIMITER
		, "#9.9 error in closure");
		
		//----test #10 : call function with no arguments.----
		$driver->str = "f()";
		$e = $driver->start();
		assert($e instanceof OperatorExpression
				&& $e->operator->id === I\OPER_FUNC_CALL, "#10.1");
		assert($e->right->expression instanceof Closure
				&& count($e->right->expression->expr_list) === 0, "#10.2");
		
		//----test #11 : string escaping----
		//case : "e\nt\x023";
		$driver->str = "\"e\\nt\\x023\"";
		$e = $driver->start();
		assert($e instanceof Literal && $e->type === I\LITERAL_STRING
				&& $e->calculate() === "e\nt\x023", "#11.1");
	}
}
?>