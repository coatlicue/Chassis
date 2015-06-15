<?php
namespace Chassis\Parser;

use Chassis\Intermediate as I;
use Chassis\Intermediate\ExecutionNode;
use Chassis\Intermediate\ExecutionNodeList;
use Chassis\Intermediate\BlockNode;
use Chassis\Intermediate\EvaluationNode;
use Chassis\Intermediate\TextNode;
use Chassis\Intermediate\Identifier;
use Chassis\Intermediate\Context;

include_once __DIR__."/../Intermediate/BlockInstructions.php";
include_once __DIR__."/../Intermediate/ExecutionTree.php";
include_once __DIR__."/../Intermediate/Context.php";
include_once __DIR__."/StateScanner.php";
include_once __DIR__."/ExpressionParser.php";

/**
 * à»ç¹µÑÇªèÇÂã¹¡ÒÃÊÃéÒ§ execution tree
 * @author acer-pc
 *
 */
class ExecutionTreeBuilder
{
	/**
	 * ºÅçÍ¡·Õèà»ç¹ÃÒ¡
	 * @var BlockNode
	 */
	private $root_block;
	/**
	 * ºÅçÍ¡·Õè¡ÓÅÑ§·Ó§Ò¹´éÇÂ
	 * @var BlockNode
	 */
	public $current_block;
	/**
	 * text node ·Õè¡ÓÅÑ§·Ó§Ò¹´éÇÂ
	 * @var TextNode
	 */
	private $current_text;
	/**
	 * context ·Õè¡ÓÅÑ§ãªéÊÃéÒ§ execution tree
	 * @var Context
	 */
	private $context;
	
	public function __construct()
	{
		$this->root_block = new BlockNode(I\BLOCKNODE_ROOT);
		$this->current_block = $this->root_block;
	}
	/**
	 * à¾ÔèÁ¢éÍ¤ÇÒÁ
	 * @param string $text
	 */
	public function add_text($text)
	{
		if($this->current_text === null)
		{
			$this->current_text = new TextNode();
			$this->current_block->add_child($this->current_text);
		}
		$this->current_text->write($text);
	}
	/**
	 * à¾ÔèÁ evaluation node
	 * @param Expression $exp ¹Ô¾¨¹ì
	 * @param int $modifier modifier
	 */
	public function add_evaluation($exp, $modifier)
	{
		$this->current_text = null;
		$this->current_block->add_child(new EvaluationNode($exp, $modifier));
	}
	/**
	 * à¾ÔèÁ block node ãËÁè
	 * @param BlockInstruction $instruction ¤ÓÊÑè§»ÃĞ¨Ó block node
	 */
	public function open_block($instruction)
	{
		$this->current_text = null;
		
		$block = new BlockNode($instruction, $this->context);
		$this->current_block->add_child($block);
		if(!$instruction->no_close) $this->current_block = $block;
	}
	/**
	 * »Ô´ block node áÅĞÂéÍ¹¡ÅÑºä»·Ó§Ò¹¡Ñºâ¹´ã¹ÃĞ´Ñºº¹
	 * @param string $instruction_name
	 */
	public function close_block()
	{
		$this->current_text = null;
		$this->current_block = $this->current_block->parent;
	}
	/**
	 * à¾ÔèÁàÎ´à´ÍÃìãËé¡ÑººÅçÍ¤»Ñ¨¨ØºÑ¹
	 * @param mixed $key
	 * @param mixed $value
	 */
	public function add_block_header($key, $value)
	{
		if($key) $this->current_block->add_header($key, $value);
	}
	/**
	 * ´Ö§ execution tree ·ÕèÊÃéÒ§àÊÃç¨áÅéÇ
	 * @return \Chassis\Parser\BlockNode
	 */
	public function get_tree()
	{
		return $this->root_block;
	}
	/**
	 * ¡ÅÑºä»ÊÙèÊÀÒÇĞàÃÔèÁµé¹
	 */
	public function reset()
	{
		$this->root_block = new BlockNode(I\BLOCKNODE_ROOT);
		$this->current_block = $this->root_block;
		$this->current_text = null;
	}
	
	public static function _test()
	{
		$if = new BlockInstruction();
		$if->name = "if";
		
		$for = new BlockInstruction();
		$for->name = "for";
		
		//----test #1 : build execution tree from "abc{"555"}{123.456}{@if}{"666"}{@for}cde{/@for}fg{/@if}hij"
		$B = new ExecutionTreeBuilder();
		$B->add_text("a");
		$B->add_text("bc");
		$B->add_evaluation(new Literal("555", LITERAL_STRING), 0);
		$B->add_evaluation(new Literal(123.456, LITERAL_NUMBER), 1);
		$B->open_block($if);
		$B->add_evaluation(new Literal("666", LITERAL_STRING), 0);
		$B->open_block($for);
		$B->add_text("cde");
		$B->close_block("for");
		$B->add_text("fg");
		$B->close_block("if");
		$B->add_text("hij");
		$E = $B->get_tree();
		$B->reset();
		
		$E1 = $E->children->list[0]; //abc
		$E2 = $E->children->list[1]; //{"555"}
		$E3 = $E->children->list[2]; //{123.456}
		$E4 = $E->children->list[3]; //{@if}...
		$E41 = $E4->children->list[0]; //666
		$E42 = $E4->children->list[1]; //{@for}...
		$E421 = $E42->children->list[0]; //cde
		$E43 = $E4->children->list[2]; //fg
		$E5 = $E->children->list[4]; //fg
		
		assert($E1 instanceof TextNode
				&& $E1->text === "abc", "#1.1");
		assert($E2 instanceof EvaluationNode
				&& $E2->expression instanceof Literal
				&& $E2->expression->calculate() === "555", "#1.2");
		assert($E3 instanceof EvaluationNode
				&& $E3->expression instanceof Literal
				&& $E3->expression->calculate() === 123.456, "#1.3");
		assert($E4 instanceof BlockNode, "#1.4");
		assert($E41 instanceof EvaluationNode
				&& $E41->expression instanceof Literal
				&& $E41->expression->calculate() === "666", "#1.5");
		assert($E42 instanceof BlockNode, "#1.6");
		assert($E421 instanceof TextNode
				&& $E421->text === "cde", "#1.7");
		assert($E43 instanceof TextNode
				&& $E43->text === "fg", "#1.8");
		assert($E5 instanceof TextNode
				&& $E5->text === "hij", "#1.9");
	}
}
/**
 * implement ãËé¡Ñº Scanner ·Õè¨ĞãªéÍèÒ¹ªèÍ§ÇèÒ§ÀÒÂã¹ keyword ¢Í§á·ç¡
 * Scanner ´Ñ§¡ÅèÒÇä´éá¡è IdentifierScanner áÅĞ ExpressionScanner
 * ¤ÅÒÊ·Õè implement interface µÑÇ¹Õé ¨ĞÊÒÁÒÃ¶µÃÇ¨ÊÍºä´éÇèÒÍÂÙèã¹ ground state ËÃ×ÍäÁè
 * @author acer-pc
 *
 */
interface IBlankScanner
{
	public function in_ground_state();
}

const VS_ERROR_ILLEGAL_CHAR = 41;
/**
 * ¤ÅÒÊ¹ÕéãªéÊÓËÃÑºÍèÒ¹ª×èÍµÑÇá»Ã
 * @author acer-pc
 *
 */
class IdentifierScanner extends Scanner implements IBlankScanner
{
	private $name = "";
	private $first = true;
	
	protected function _scan()
	{
		if($this->state === SC_STATE_WORKING)
		{
			if(preg_match("/^[A-Za-z_".($this->first ? "0-9" : "")."]$/", ($c = $this->get_current_char())))
			{
				$this->name .= $this->get_current_char();
			}
			else 
			{
				$this->suicide(new Error($this, VS_ERROR_ILLEGAL_CHAR));
			}
			$this->first = false;
		}
	}
	
	protected function _summarize()
	{
		if($this->name === "")
		{
			return null;
		}
		else 
		{
			return new Identifier($this->name);
		}
	}
	
	public function in_ground_state()
	{
		return true;
	}
	
	public function reset()
	{
		parent::reset();
		$this->name = "";
		$this->first = true;
	}
}

const KN_TYPE_ROOT = 1;
const KN_TYPE_KEYWORD = 2;
const KN_TYPE_BLANK = 3;
const KN_TYPE_TERMINATION = 4;

const KN_BLANK_EXP = 1;
const KN_BLANK_VAR = 2;

class KeywordNode
{
	/**
	 * ª¹Ô´¢Í§â¹´ ä´éá¡è KN_TYPE_ROOT, KN_TYPE_KEYWORD áÅĞ KN_TYPE_BLANK
	 * @var int
	 */
	public $type;
	/**
	 * ÃĞºØ key ¢Í§ header ·Õè¨ĞãËéà¾ÔèÁ¤èÒ àÁ×èÍÍèÒ¹à¨Í keyword ¹Õé
	 * ËÒ¡¡ÓË¹´ª¹Ô´à»ç¹ KN_TYPE_KEYWORD ¨Ğà¾ÔèÁ¤èÒ true Å§ã¹ header
	 * ËÒ¡¡ÓË¹´ª¹Ô´à»ç¹ KN_TYPE_BLANK ¨Ğà¾ÔèÁ¤èÒ·ÕèÍèÒ¹ä´éÅ§ã¹ header
	 * @var mixed
	 */
	public $target_header;
	/**
	 * ª¹Ô´¢Í§ªèÍ§ÇèÒ§ (ã¹¡Ã³Õ·ÕèÃĞºØª¹Ô´¢Í§â¹´à»ç¹ KN_TYPE_BLANK)
	 * ä´éá¡è KN_BLANK_EXP áÅĞ KN_BLANK_VAR
	 * @var int
	 */
	public $blank_type;
	/**
	 * ¤ÕÂìàÇÔÃì´ (¡Ã³Õ·ÕèÃĞºØª¹Ô´à»ç¹ KN_TYPE_KEYWORD)
	 * @var string
	 */
	public $keyword_str;
	/**
	 * ÍÒÃìàÃÂìà¡çºâ¹´ÅÙ¡
	 * @var array
	 */
	public $children = [];
	/**
	 * â¹´áÁè
	 * @var KeywordNode
	 */
	public $parent;
	
	/**
	 * 
	 * @param KeywordNode $node
	 */
	public function add($type, $keyword_str = null, $blank_type = null, $target_header = null)
	{
		if(($ret = $this->search($type, $keyword_str)) === false)
		{
			$ret = new self($type,$keyword_str,$blank_type,$target_header);
			array_push($this->children, $ret);
			$ret->parent = $this;
		}
		return $ret;
	}
	/**
	 * ¤é¹ËÒâ¹´ÅÙ¡·ÕèÃĞºØ
	 * @param int $type ª¹Ô´¢Í§â¹´ÅÙ¡
	 * @param string $keyword_str ¢éÍ¤ÇÒÁ¢Í§¤ÕÂìàÇÔÃì´ ã¹¡Ã³Õ·Õè¡ÓË¹´ª¹Ô´à»ç¹ keyword ãËéÃĞºØ¾ÒÃÒÁÔàµÍÃì¹Õé´éÇÂ
	 * @return ¶éÒ¾º ¤×¹¤èÒ KeywordNode ¶éÒäÁè¾º ¤×¹¤èÒ false
	 */
	public function search($type, $keyword_str = null)
	{
		foreach ($this->children as $c)
		{
			if($c->type === $type && $c->keyword_str === $keyword_str)
			{
				return $c;
			}
		}
		return false;
	}
	/**
	 * 
	 * @param int $type ª¹Ô´¢Í§â¹´
	 * @param string $keyword_str ¤ÕÂìàÇÔÃì´ ¡Ã³Õ·Õè¡ÓË¹´ª¹Ô´à»ç¹ KN_TYPE_KEYWORD
	 * @param int $blank_type ª¹Ô´¢Í§ªèÍ§ÇèÒ§ ¡Ã³Õ·Õè¡ÓË¹´ª¹Ô´à»ç¹ KN_TYPE_BLANK
	 * @param string $target_header ¤ÕÂì¢Í§àÎ´à´ÍÃì ·Õè¨ĞãËéãÊè¤èÒÅ§ä»àÁ×èÍÍèÒ¹à¨Í¤ÕÂìàÇÔÃì´µÑÇ¹Õé
	 */
	public function __construct($type, $keyword_str = null, $blank_type = null, $target_header = null)
	{
		$this->type = $type;
		$this->keyword_str = $keyword_str;
		$this->blank_type = $blank_type;
		$this->target_header = $target_header;
	}
	
	/**
	 * ÊÃéÒ§ KeywordNode ¨Ò¡ÃÒÂ¡ÒÃ¢Í§ÅÓ´Ñº¢Í§¤ÕÂìàÇÔÃì´·Õè¡ÓË¹´
	 * ËÒ¡à»ç¹ªèÍ§ÇèÒ§ª¹Ô´¹Ô¾¨¹ì ãËéÃĞºØ #exp ËÒ¡à»ç¹ªèÍ§ÇèÒ§ª¹Ô´µÑÇá»Ã ãËéÃĞºØ #var
	 * ÅÓ´Ñº¢Í§¤ÕÂìàÇÔÃì´ ãËéÃĞºØã¹ÃÙ» keyword1.targetheader1 keyword2.targetheader2 ËÃ×ÍäÁèµéÍ§ÃĞºØ targetheader ¡çä´é
	 * @param array $keyword_seqs
	 * @return KeywordNode
	 */
	public static function compile(array $keyword_seqs)
	{
		$root = new KeywordNode(KN_TYPE_ROOT);
		foreach($keyword_seqs as $seq)
		{
			$keywords = preg_split("/ +/", $seq);
			$parent = $root;
			foreach ($keywords as $keyword)
			{
				$split = explode(".", $keyword);
				$keyword_code = $split[0];
				if($keyword_code === "") continue;
				$target_header = array_key_exists(1, $split) ? $split[1] : null;
				switch($keyword_code)
				{
					case "#exp":
						$child = $parent->add(KN_TYPE_BLANK, null, KN_BLANK_EXP, $target_header);
						break;
					case "#var":
						$child = $parent->add(KN_TYPE_BLANK, null, KN_BLANK_VAR, $target_header);
						break;
					default:
						$child = $parent->add(KN_TYPE_KEYWORD, $keyword_code, null, $target_header);
						break;
				}
				$parent = $child;
			}
			$parent->add(KN_TYPE_TERMINATION);
		}
		return $root;
	}
	
	public static function _test_compile()
	{
		$n = self::compile(["a.x #exp.y b c.z", "a.x d"]);
		$a = $n->search(KN_TYPE_KEYWORD, "a");
		$blank = $a->search(KN_TYPE_BLANK);
		$b = $blank->search(KN_TYPE_KEYWORD, "b");
		$c = $b->search(KN_TYPE_KEYWORD, "c");
		$d = $a->search(KN_TYPE_KEYWORD, "d");
		
		assert($a !== false && $a->target_header === "x", "#1");
		assert($blank !== false && $blank->blank_type === KN_BLANK_EXP && $blank->target_header === "y", "#2");
		assert($b !== false && $b->target_header === null , "#3");
		assert($c !== false && $c->search(KN_TYPE_TERMINATION) !== false && $c->target_header === "z", "#4");
		assert($d !== false, "#5");
	}
}