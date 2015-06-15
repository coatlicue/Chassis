<?php
namespace Chassis\Parser;

use Chassis\Parser\BlockInstruction;
use Chassis\Parser\KeywordNode;
use Chassis\Parser\KN_TYPE_TERMINATION;
use Chassis\Parser\ExpressionScanner;
use Chassis\Intermediate as I;
use Chassis\Intermediate\ExecutionNode;
use Chassis\Intermediate\ExecutionNodeList;
use Chassis\Intermediate\BlockNode;
use Chassis\Intermediate\EvaluationNode;
use Chassis\Intermediate\TextNode;
use Chassis\Intermediate\OperatorExpression;
use Chassis\Intermediate\VariableExpression;
use Chassis\Intermediate\Literal;
use Chassis\Intermediate\Closure;
use Chassis\Intermediate\Identifier;
use Chassis;

include_once __DIR__."/ExecutionParser.php";
include_once __DIR__."/ExpressionParserImplemented.php";
include_once __DIR__."/StateScanner.php";
include_once __DIR__."/../Intermediate/Operators.php";
include_once __DIR__."/../Intermediate/ExecutionTree.php";

class BlockInstructions
{
	public static $list = [];
}

BlockInstructions::$list['if'] = new I\BlockInstruction_If();
BlockInstructions::$list['else'] = new I\BlockInstruction_Else();
BlockInstructions::$list['elseif'] = new I\BlockInstruction_ElseIf();
BlockInstructions::$list['for'] = new I\BlockInstruction_For();
BlockInstructions::$list['foreach'] = new I\BlockInstruction_ForEach();
BlockInstructions::$list['test_noclose'] = new I\BlockInstruction_Test_NoClose();

const EXS_EVAL = 1;
const EXS_CLOSE_TAG = 2;

const EXS_ERROR_REQUIRED_EVAL_DELIMITER = 21;
const EXS_ERROR_REQUIRED_KEYWORD = 22; //�Դ�������ͼ����������кؤ�������촢ͧ��
const EXS_ERROR_UNEXPECTED_CURLY_BRACE = 23; //�红������ͼ�����кؾ���������ͧ�����ú (�� {@for i from x} <- ����� to)

class ExecutionScanner extends StateScanner
{
	/**
	 * 
	 * @var ExecutionTreeBuilder
	 */
	public $exec_builder;
	
	public $state_ground;
	private $state_eval_read;
	private $state_tag_read;
	
	public function __construct($parent)
	{
		$this->exec_builder = new ExecutionTreeBuilder();
		
		$this->state_ground = new State();
		$this->state_ground->intermediate_mode = STATE_PRE_INTERMEDIATE;
		$this->state_ground->expectation_tree = new ExpectationTreeNode(ETN_TYPE_INITIATION);
		$this->state_ground->expectation_tree->add_str("{", EXS_EVAL);
		
		$open_tag = $this->state_ground->expectation_tree->add_str("{@", null, false)
			->add_list(BlockInstructions::$list);
		
		$this->state_ground->operation = [$this, "_ground_state"];
		$this->initial_state = $this->state_ground;
		
		$this->state_eval_read = new ExecutionScanner_evalReadState($this);
		
		$this->state_tag_read = new ExecutionScanner_tagReadState($this);
		
		parent::__construct($parent);
	}
	
	/**
	 * 
	 * @param Transition $transition
	 * @param array $exp_result
	 */
	public function _ground_state($transition, $exp_result)
	{
		if($this->state === SC_STATE_WORKING)
		{
			if($exp_result['succeed'])
			{
				$tag = $exp_result['tag'];
				if($tag instanceof I\BlockInstruction)
				{
					if($this->exec_builder->current_block->block_instruction !== I\BLOCKNODE_ROOT)
					{
						$tag_name = $this->exec_builder->current_block->block_instruction->name;
						$this->state_ground->expectation_tree->remove_list(["{/@$tag_name}"]);
					}
					$this->exec_builder->open_block($tag);
					if(!$tag->no_close)
					{
						$this->state_ground->expectation_tree->add_str("{/@$tag->name}", EXS_CLOSE_TAG);
					}
					//�觵����ѧ tag read state �������ʹ tag_read_transfer ������͡��� keyword node �ͧ block instruction �����ҹ���� next_keyword_node
					$this->state_tag_read->current_keyword_node = $tag->keyword_tree;
					$this->state_ground->next_transition = new Transition($this->state_tag_read);
				}
				elseif($tag === EXS_EVAL)
				{
					$this->state_ground->next_transition = new Transition($this->state_eval_read);
				}
				elseif($tag === EXS_CLOSE_TAG)
				{
					$tag_name = $this->exec_builder->current_block->block_instruction->name;
					$this->state_ground->expectation_tree->remove_list(["{/@$tag_name}"]);
					$this->exec_builder->close_block();
					if($this->exec_builder->current_block->block_instruction !== I\BLOCKNODE_ROOT)
					{	
						$tag_name = $this->exec_builder->current_block->block_instruction->name;
						$this->state_ground->expectation_tree->add_str("{/@$tag_name}", EXS_CLOSE_TAG);
					}
				}
			}
			else
			{
				$this->exec_builder->add_text($exp_result['symbol']);
			}
		}
	}
	
	protected function _summarize()
	{
		return $this->exec_builder->get_tree();
	}
	/**
	 * ��§ҹ�����Դ��Ҵ
	 * @param Error $err
	 */
	public function report_error($err)
	{
		$this->suicide($err);
	}
	
	public function reset()
	{
		parent::reset();
		$this->exec_builder->reset();
	}
	
	public static function _test()
	{
		$d = new ScannerDriver();
		$s = new ExecutionScanner($d);
		
		//----test #1 : parse text and evaluation----
		$d->str = "abcd{123 + 456}efg{'hij' #n}klmn";
		$e = $d->start();
		$c1 = $e->children->list[0];
		$c2 = $e->children->list[1];
		$c3 = $e->children->list[2];
		$c4 = $e->children->list[3];
		$c5 = $e->children->list[4];
		
		assert($c1 instanceof TextNode && $c1->text === "abcd", "#1.1");
		assert($c2 instanceof EvaluationNode 
				&& $c2->expression instanceof OperatorExpression
				&& $c2->expression->operator->id === I\OPER_ADD, "#1.2");
		assert($c3 instanceof TextNode && $c3->text === "efg", "#1.3");
		assert($c4 instanceof EvaluationNode
				&& $c4->expression instanceof Literal
				&& $c4->expression->type === I\LITERAL_STRING
				&& $c4->expression->calculate() === "hij", "#1.4");
		assert($c5 instanceof TextNode && $c5->text === "klmn", "#1.5");
		
		//----test #2 : parse text and block----
		$d->str = "abc{@for i from 1 to 5 + 2 step 2}de{@if x}f{/@if}{/@for}gh{@foreach key : value in 'rete}t e r'}i{/@foreach}";
		$e = $d->start();
		
		$for = $e->children->list[1];
		assert($for->headers["counter"] instanceof Identifier
				&& $for->headers["counter"]->get_name() === "i", "#2.1");
		assert($for->headers['start'] instanceof Literal
				&& $for->headers['start']->calculate() === 1, "#2.2");
		assert($for->headers['end'] instanceof OperatorExpression
				&& $for->headers['end']->calculate() === 7, "#2.3");
		assert($for->headers['step'] instanceof Literal
				&& $for->headers['step']->calculate() === 2, "#2.4");
		
		$if = $for->children->list[1];
		assert($if->headers['cond'] instanceof VariableExpression
				&& $if->headers['cond']->get_var_name() === "x", "#2.5");
		
		$foreach = $e->children->list[3];
		assert($foreach->headers["var1"] instanceof Identifier
				&& $foreach->headers["var1"]->get_name() === "key", "#2.6");
		assert($foreach->headers["var2"] instanceof Identifier
				&& $foreach->headers["var2"]->get_name() === "value", "#2.7");
		assert($foreach->headers["array"] instanceof Literal
				&& $foreach->headers["array"]->calculate() === "rete}t e r", "#2.8");
		
		//----test #3 : using non-close tag----
		$d->str = "abc{@test_noclose}def";
		$r = $d->start();
		$c1 = $r->children->list[0];
		$c2 = $r->children->list[1];
		$c3 = $r->children->list[2];
		
		assert($c1 instanceof TextNode && $c1->text === "abc", "#3.1");
		assert($c2 instanceof BlockNode, "#3.2");
		assert($c3 instanceof TextNode && $c3->text === "def", "#3.3");
		
		//----test #4 : empty blank error----
		$d->str = "{@if}...{/@if}";
		$r = $d->start();
		assert($r === false && $d->last_error->code === EXS_ERROR_UNEXPECTED_CURLY_BRACE, "#4.1");
		//----test #5 : use keyword as identifier----
		$d->str = "{@for   from from 1 to 3}";
		$r = $d->start();
		assert($r->children->list[0]->headers['counter']->get_name() === "from", "#5.1");
	}
}

class ExecutionScanner_evalReadState extends State
{
	/**
	 * �к���� ��й����ѧ��ҹ modifier �����������
	 * @var boolean
	 */
	private $reading_modifier = false;
	/**
	 * modifier �����ҹ��
	 * @var string
	 */
	private $modifier = "";
	/**
	 * The expression scanner.
	 * @var ExpressionScanner
	 */
	private $exp_scanner;
	/**
	 * Scanner �������Ңͧ state ���
	 * @var ExecutionScanner $scanner
	 */
	private $scanner;
	
	public function __construct($scanner)
	{
		$this->scanner = $scanner;
		$this->operation = [$this, "state"];
	}
	
	public function state($transition)
	{
		if($this->scanner->state === SC_STATE_WORKING)
		{
			if($transition->first)
			{
				if($this->exp_scanner === null)
				{
					$this->exp_scanner = new ExpressionScanner($this->scanner);
				}
				else
				{
					$this->exp_scanner->reset();
				}
				$this->exp_scanner->initialize();
			}
			else	
			{
				if($this->scanner->get_current_char() == " ")
				{
					//no action.
				}
				elseif($this->scanner->get_current_char() === "}" && $this->exp_scanner->in_ground_state())
				{
					$this->exp_scanner->finalize();
					$exp = $this->exp_scanner->summarize();
			
					//�Ѵ��� modifier
					$mods = explode(",", $this->modifier);
					$mod_flag = 0;
					foreach ($mods as $m)
					{
						switch ($m)
						{
							case "n":
								$mod_flag |= I\EVALNODE_NO_HTML_ESCAPE;
								break;
						}
					}
				
					//�ѹ�֡������
					$this->scanner->exec_builder->add_evaluation($exp, $mod_flag);
			
					//�觡�Ѻ��ѧ ground state
					$this->next_transition = new Transition($this->scanner->state_ground);
				}
				elseif($this->reading_modifier)
				{
					$this->modifier .= $this->scanner->get_current_char();
				}
				elseif($this->scanner->get_current_char() === "#" && $this->exp_scanner->in_ground_state())
				{
					$this->reading_modifier = true;
				}
				else 
				{
					$this->exp_scanner->advance_here();
					if($this->exp_scanner->state === SC_STATE_DEAD)
					{
						//����ѧ ground state ��Ṻ error 仴���
						$this->scanner->report_error($this->exp_scanner->error);
					}
				}
			}
		}
		else
		{
			$this->scanner->report_error(new Error($this->scanner, EXS_ERROR_REQUIRED_EVAL_DELIMITER));
		}
	}
}

class ExecutionScanner_tagReadState extends State
{
	/**
	 * ExpressionScanner ����������
	 * @var ExpressionScanner
	 */
	private $blank_exp_scanner;
	/**
	 * IdentifierScanner ����������
	 * @var IdentifierScanner
	 */
	private $blank_var_scanner;
	
	/**
	 * Scanner �����������ҹ��ͧ�����
	 * @var IBlankScanner
	 */
	private $blank_scanner;
	/**
	 * �纵��˹觢ͧ space ����ش
	 * @var int
	 */
	private $last_space_pos;
	/**
	 * keyword node �Ѩ�غѹ�����ѧ�ӧҹ����
	 * @var KeywordNode
	 */
	public $current_keyword_node;
	/**
	 * ʵ�ԧ��������Ԩ�ó���� �繤���������������
	 * @var string
	 */
	private $stored_keyword;
	
	/**
	 * execution scanner �������Ңͧ state ���
	 * @var ExecutionScanner
	 */
	private $parent_scanner;
	
	public function __construct($parent)
	{
		$this->parent_scanner = $parent;
		$this->operation = [$this, "operate"];
	}
	
	/**
	 * @todo refractor the code.
	 * @param Transition $transition
	 */
	public function operate($transition)
	{
		if($transition->first) return;
		$c = $this->parent_scanner->get_current_char();
		if($c === " " || $c === "\r" || $c === "\n")
		{
			$this->last_space_pos = $this->parent_scanner->position;
			//skip
		}
		else
		{
			if($this->blank_scanner !== null && !$this->blank_scanner->in_ground_state())
			{
				$this->blank_scanner->advance_here();
				if($this->blank_scanner->state === SC_STATE_DEAD)
				{
					$this->parent_scanner->report_error($this->blank_scanner->error);
				}
			}
			elseif($c === "}")
			{
				$this->_save_blank_data();
				if($this->current_keyword_node->search(KN_TYPE_TERMINATION))
				{
					$this->next_transition = new Transition($this->parent_scanner->state_ground);
				}
				else
				{
					$this->parent_scanner->report_error(new Error($this->parent_scanner, EXS_ERROR_UNEXPECTED_CURLY_BRACE));
				}
			}
			else
			{
				$this->stored_keyword .= $c;
				$p = $this->parent_scanner->peek_ahead();
				if($p === " " || $p === "\r" || $p === "\n" || $p === "}")
				{
					//����;��Ѻ whitespace ���͵�Ǩ� (}) ������ keyword �����ҹ��令�������������ԧ������� �������ա����������繪�ͧ��ҧ
					$node = $this->current_keyword_node->search(KN_TYPE_KEYWORD, $this->stored_keyword);
					$this->stored_keyword = "";
					if($node)
					{
						$this->parent_scanner->exec_builder->add_block_header($node->target_header, true);
						$this->_save_blank_data();
						$this->current_keyword_node = $node;
					}
					else
					{
						if($this->blank_scanner)
						{
							$this->blank_scanner->advance_here();
							if($this->blank_scanner->state === SC_STATE_DEAD)
							{
								$this->parent_scanner->report_error($this->blank_scanner->error);
								return;
							}
						}
						elseif($node = $this->current_keyword_node->search(KN_TYPE_BLANK))
						{
							$this->current_keyword_node = $node;
							$this->_use_blank_scanner($node->blank_type, $this->last_space_pos);
							$this->blank_scanner->advance_here();
							if($this->blank_scanner->state === SC_STATE_DEAD)
							{
								$this->parent_scanner->report_error($this->blank_scanner->error);
								return;
							}
						}
						else
						{
							$this->parent_scanner->report_error(new Error($this->parent_scanner, EXS_ERROR_REQUIRED_KEYWORD));
						}
					}
				}
			}
		}
	}
	
	/**
	 * ����ҹ blank scanner
	 * @param int $type ��Դ�ͧ blank
	 */
	private function _use_blank_scanner($type, $position)
	{
		switch($type)
		{
			case KN_BLANK_EXP:
				if($this->blank_exp_scanner === null)
				{
					$this->blank_exp_scanner = new ExpressionScanner($this->parent_scanner);
				}
				else
				{
					$this->blank_exp_scanner->reset();
				}
				$this->blank_exp_scanner->initialize();
				$this->blank_scanner = $this->blank_exp_scanner;
				break;
			case KN_BLANK_VAR:
				if($this->blank_var_scanner === null)
				{
					$this->blank_var_scanner = new IdentifierScanner($this->parent_scanner);
				}
				else
				{
					$this->blank_var_scanner->reset();
				}
				$this->blank_var_scanner->initialize();
				$this->blank_scanner = $this->blank_var_scanner;
				break;
		}
	
		$this->blank_scanner->position = $position;
	}
	/**
	 * �ѹ�֡������� blank �����ҹ��
	 */
	private function _save_blank_data()
	{
		if($this->blank_scanner)
		{
			$this->blank_scanner->finalize();
			if($this->blank_scanner->state === SC_STATE_DEAD)
			{
				$this->parent_scanner->report_error($this->blank_scanner->error);
				return;
			}
				$this->parent_scanner->exec_builder->add_block_header
					($this->current_keyword_node->target_header, $this->blank_scanner->summarize());
				$this->blank_scanner = null;
		}
	}
}