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

const EXPSC_STATE_GROUND = 0;
const EXPSC_STATE_STR_READ = 1;
const EXPSC_STATE_CLOSURE_READ = 2;
const EXPSC_STATE_ID_READ = 3;
const EXPSC_STATE_NUMBER_READ = 4;
const EXPSC_STATE_ID_EXP_READ = 5;

class ExpressionScanner extends StateScanner implements IBlankScanner
{
	public $exp_builder;
	public $exp_scanner_reuse;

	public function __construct($parent)
	{
		$this->exp_builder = new ExclusiveExpressionBuilder();
		$this->exp_scanner_reuse = new ScannerReuseService(function() { return new ExpressionScanner($this); });

		$this->add_state(EXPSC_STATE_GROUND, new ExpressionScanner_GroundState());
		$this->add_state(EXPSC_STATE_STR_READ, new ExpressionScanner_StrReadState());
		$this->add_state(EXPSC_STATE_ID_READ, new ExpressionScanner_IdReadState());
		$this->add_state(EXPSC_STATE_NUMBER_READ, new ExpressionScanner_NumReadState());
		$this->add_state(EXPSC_STATE_CLOSURE_READ, new ExpressionScanner_ClosureReadState());
		$this->add_state(EXPSC_STATE_ID_EXP_READ, new ExpressionScanner_IdExpReadState());

		$this->initial_state = EXPSC_STATE_GROUND;

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
		return $this->get_next_state() === EXPSC_STATE_GROUND;
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
	public function _add_expression($exp)
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
	public function _add_operator($oper)
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

abstract class ExpressionScannerState extends State
{
	/**
	 * เก็บสตริงที่อ่านได้
	 * @var string
	 */
	protected $consumed;
	
	public function enter($transition, $exp_result)
	{
		if($transition->first)
		{
			$this->consumed = "";
		}
		parent::enter($transition, $exp_result);
	}
}

class ExpressionScanner_GroundState extends ExpressionScannerState
{
	const WHITESPACE = 1;
	const STR_DELIMITER = 2;

	public function __construct()
	{
		$whitespaces = [" " => self::WHITESPACE, "\n" => self::WHITESPACE, "\r\n" => self::WHITESPACE];
		$str_delimiter = ["\"" => self::STR_DELIMITER, "'" => self::STR_DELIMITER];
		$operators = OperList::$oper_str;

		$this->expectation_tree = ExpectationTreeNode::create(array_merge($whitespaces, $str_delimiter, $operators));
		$this->intermediate_mode = STATE_PRE_INTERMEDIATE;
	}

	public function operation($transition, $exp_result)
	{
		if($this->scanner->state === P\SC_STATE_WORKING)
		{
			//expectation tag.
			$exp_tag = $exp_result['tag'];
			$exp_sym = $exp_result['symbol'];
			if($exp_tag === self::WHITESPACE) //detecting whitespace.
			{
				//no action
			}
			elseif($exp_tag === self::STR_DELIMITER) //detecting string delimiter.
			{
				$this->next_transition = new Transition(EXPSC_STATE_STR_READ);
			}
			elseif($exp_tag instanceof I\Operator
					|| $exp_tag instanceof I\OperatorPolymorphism) //detecting operator.
			{
				$this->scanner->_add_operator($exp_tag);
			}
			elseif($exp_sym === "(") //closure
			{
				$this->next_transition = new Transition(EXPSC_STATE_CLOSURE_READ);
			}
			elseif($exp_sym === "{") //เป็นชื่อตัวแปรที่ถูกระบุโดย expression (เช่น var1 มีค่าเท่ากับ {"var1"})
			{
				$this->next_transition = new Transition(EXPSC_STATE_ID_EXP_READ);
			}
			elseif(preg_match("/^[A-Za-z_]+$/", $exp_sym)) //identifier
			{
				$this->next_transition = new Transition(EXPSC_STATE_ID_READ);
			}
			elseif(preg_match("/^[0-9]$/", $exp_sym)) //number
			{
				$this->next_transition = new Transition(EXPSC_STATE_NUMBER_READ);
			}
			else
			{
				$this->scanner->suicide(new Error($this, EP_ERROR_ILLEGAL_SYMBOL));
			}
		}
		else
		{
			$this->scanner->exp_builder->flush();
		}
	}
}
class ExpressionScanner_StrReadState extends ExpressionScannerState
{
	/**
	 * เก็บ delimiter เริ่มต้นของสตริงไว้
	 * @var string
	 */
	public $delimiter = "";
	/**
	 * ระบุว่า ขณะนี้ยังอยู่ในระหว่างการ escape หรือไม่
	 * @var boolean
	 */
	public $escaping = false;
	/**
	 * เก็บตัวนำทางในการ escape
	 * @var StringEscapingGuide
	 */
	public $escaping_guide;

	public function __construct()
	{
		$this->escaping_guide = new StringEscapingGuide();
	}

	public function operation($transition, $exp_result)
	{
		if($this->scanner->state === P\SC_STATE_WORKING)
		{
			if($transition->first) //ถ้าเริ่มทำงานเป็นครั้งแรก
			{
				$this->delimiter = $this->scanner->get_current_char();
			}
			else if($this->escaping)
			{ //ถ้าในขณะนี้กำลัง escape อยู่
				if($e = $this->escaping_guide->feed($this->scanner->get_current_char(), $this->scanner->peek_ahead()))
				{ //เมื่อจบการ escape, ตัว escaping guide จะคืนค่าสตริงออกมา
					$this->escaping = false;
					$this->consumed .= $e;
				}
			}
			else if($this->scanner->get_current_char() === $this->delimiter)
			{ //เมื่อพบกับ delimiter
				$this->scanner->_add_expression(new Literal($this->consumed, I\LITERAL_STRING));

				$this->next_transition = new Transition(EXPSC_STATE_GROUND);
			}
			else if($this->scanner->get_current_char() === "\\")
			{
				$this->escaping = true;
			}
			else
			{
				$this->consumed .= $this->scanner->get_current_char();
			}
		}
		else
		{
			$this->scanner->suicide(new Error($this, EP_ERROR_EXPECTED_STR_DELIMITER));
		}
	}
}

class ExpressionScanner_IdReadState extends ExpressionScannerState
{
	protected function operation($transition, $exp_result)
	{
		$this->consumed .= $this->scanner->get_current_char();
		//peek at the next char. if it isn't letter, digit or underscore, end this state.
		if(!preg_match("/^[A-Za-z0-9_]$/", $this->scanner->peek_ahead()))
		{
			if(strtolower($this->consumed) === "true" || strtolower($this->consumed) === "false")
			{
				$this->scanner->_add_expression(new Literal($this->consumed, I\LITERAL_BOOLEAN));
			}
			else
			{
				$this->scanner->_add_expression(new VariableExpression($this->consumed));
			}
			$this->next_transition = new Transition(EXPSC_STATE_GROUND);
		}
	}
}

class ExpressionScanner_NumReadState extends ExpressionScannerState
{
	/**
	 * ระบุว่า ขณะนี้อยู่หลังจุดทศนิยมหรือไม่
	 * @var boolean
	 */
	public $after_dot = false;

	protected function operation($transition, $exp_result)
	{
		$this->consumed .= ($c = $this->scanner->get_current_char());
		if($c === ".") $after_dot = true;
		if(!preg_match("/^[0-9".(!$this->after_dot ? preg_quote(".") : "")."]$/", $this->scanner->peek_ahead()))
		{
			$this->scanner->_add_expression(new Literal($this->consumed, I\LITERAL_NUMBER));
			$this->next_transition = new Transition(EXPSC_STATE_GROUND);
			$this->after_dot = false;
		}
	}
}

class ExpressionScanner_ClosureReadState extends ExpressionScannerState
{
	/**
	 * เก็บ closure ที่กำลังสร้างขึ้น
	 * @var Closure
	 */
	private $closure;
	/**
	 * เก็บ scanner ที่เอาไว้ใช้ในการอ่าน expression ย่อย
	 * @var ExpressionScanner
	 */
	private $sub_exp_scanner;

	private function add_exp()
	{
		$this->sub_exp_scanner->finalize();
		//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
		if($this->sub_exp_scanner->state === P\SC_STATE_DEAD)
		{
			$this->scanner->suicide($sub_exp_scanner->error);
		}
		else
		{
			$new_exp = $this->sub_exp_scanner->summarize();
			if($new_exp !== null) $this->closure->add_expr($new_exp);
		}
	}

	protected function operation($transition, $exp_result)
	{
		if($this->scanner->state === P\SC_STATE_WORKING)
		{
			$c = null;
			if($transition->first)
			{
				$this->closure = new Closure();
				$this->sub_exp_scanner = $this->scanner->exp_scanner_reuse->use_scanner();
			}
			elseif ($this->sub_exp_scanner->in_ground_state()
					&& (($c = $this->scanner->get_current_char()) === "," 
							|| $c === ")"))
			{
				switch ($c)
				{
					case ",":
						$this->add_exp();
						$this->sub_exp_scanner->reset();
						$this->sub_exp_scanner->initialize();
						break;
					case ")":
						$this->add_exp();
						$this->scanner->_add_expression($this->closure);

						$this->closure = null;
						$this->sub_exp_scanner = null;

						$this->next_transition = new Transition(EXPSC_STATE_GROUND);
						break;
				}
			}
			else
			{
				$this->sub_exp_scanner->advance_here();
				//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
				if($this->sub_exp_scanner->state === P\SC_STATE_DEAD)
				{
					$this->scanner->suicide($this->sub_exp_scanner->error);
				}
			}
		}
		else
		{
			$this->sub_exp_scanner->finalize();
			//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
			if($this->sub_exp_scanner->state === P\SC_STATE_DEAD)
			{
				$this->scanner->suicide($this->sub_exp_scanner->error);
			}
			else
			{
				$this->scanner->suicide(new Error($this, EP_ERROR_EXPECTED_CLOSE_PARENTHESIS));
			}
		}
	}
}

class ExpressionScanner_IdExpReadState extends ExpressionScannerState
{
	private $sub_exp_scanner;

	protected function operation($transition, $exp_result)
	{
		if($this->scanner->state === P\SC_STATE_WORKING)
		{
			if($transition->first)
			{
				$this->sub_exp_scanner = $this->scanner->exp_scanner_reuse->use_scanner();
			}
			elseif($this->scanner->get_current_char() === "}"
					&& $this->sub_exp_scanner->in_ground_state())
			{ //เมื่อพบตัวอักษร } ให้หยุดการอ่านนิพจน์ และเพิ่ม VariableExpression ลงใน expressionBuilder
				$this->sub_exp_scanner->finalize();
				$this->scanner->_add_expression(
						new VariableExpression($this->sub_exp_scanner->summarize()));
				$this->next_transition = new Transition(EXPSC_STATE_GROUND);
			}
			else
			{ //อ่านต่อไป
				$this->sub_exp_scanner->advance_here();
				//ถ้าหาก Scanner ตัวลูกเกิดความผิดพลาดขึ้น ก็ให้หยุดการทำงาน
				if($this->sub_exp_scanner->state === P\SC_STATE_DEAD)
				{
					$this->scanner->suicide($sub_exp_scanner->error);
				}
			}
		}
		else
		{
			$this->scanner->suicide(new Error($this, EP_ERROR_EXPECTED_CLOSE_CURLY_BRACE));
		}
	}
}
?>