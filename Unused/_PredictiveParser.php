<?php
use Chassis\Parser\Scanner;
include 'Parser/Scanner.php';

const TERMINAL_END = 1; //represents $

class Nonterminal
{
	public $right_hand_sides = [];
	public $grammar;
	
	public $follow;
	public $first;
	public $nullable;
	
	public function __construct()
	{
	}
	
	public function add_rhs(array $rhs)
	{
		array_push($this->right_hand_sides, $rhs);
	}
	
	public function follow()
	{
		$follow = [];
		$this->grammar->walk_productions(function($N, $rhs) use (&$follow)
		{
			for($i=0; $i<count($rhs); $i++)
			{
				//ค้นหา nonterminal ตัวนี้ ใน right hand side ของ production
				if($rhs[$i] === $this)
				{
					//ถ้ายังมี symbol ตัวถัดไป
					if($i+1 < count($rhs))
					{
						$follow = array_merge($follow, first([$rhs[$i+1]]));
						//ถ้า symbol ตัวถัดไปเป็น nullable ได้ และ nonterminal ที่เป็นเจ้าของ production ไม่ใช้ตัวมันเอง
						if(nullable([$rhs[$i+1]]) && $this !== $N)
						{
							$follow = array_merge($follow, $N->follow());
						}
					}
					else if($this !== $N) //ถ้าไม่มี symbol ตัวถัดไป และ nonterminal ที่เป็นเจ้าของ production ไม่ใช้ตัวมันเอง
					{
						$follow = array_merge($follow, $N->follow());
					}
				}
			}
		});
		return $this->follow = array_unique($follow);
	}
	
	public function first()
	{
		return $this->first = first([$this]);
	}
	
	public function nullable()
	{
		return $this->nullable = nullable([$this]);
	}
}

class ContextFreeGrammar
{
	public $nonterminals = [];
	public $start_nonterminal;
	
	/**
	 * 
	 * @return Nonterminal
	 */
	public function new_nonterminal()
	{
		$N = new Nonterminal();
		$N->grammar = $this;
		array_push($this->nonterminals, $N);
		return $N;
	}
	
	/**
	 * iterate over every production in this grammar.
	 * @param callable $callback takes (nonterminal, right hand side of the nonterminal)
	 */
	public function walk_productions(callable $callback)
	{
		foreach($this->nonterminals as $N)
		{
			foreach($N->right_hand_sides as $rhs)
			{
				$callback($N, $rhs);
			}
		}
	}
}

function nullable(array $symbol_seq)
{
	if(($c = count($symbol_seq)) === 0)
	{
		return true;
	}
	else if($c === 1)
	{
		$s = $symbol_seq[0];
		if($s instanceof Nonterminal)
		{
			$res = false;
			foreach($s->right_hand_sides as $rhs)
			{
				$res = $res || nullable($rhs);
				if($res) break;
			}
			return $res;
		}
		else if($s === "")
		{
			return true;
		}
		else
		{
			return false;
		}
	}
	else
	{
		$first = array_shift($symbol_seq);
		return nullable([$first]) && nullable($symbol_seq);
	}
}

function first(array $symbol_seq)
{
	if(($c = count($symbol_seq)) === 0)
	{
		return [];
	}
	else if($c === 1)
	{
		$s = $symbol_seq[0];
		if($s instanceof Nonterminal)
		{
			$res = [];
			foreach($s->right_hand_sides as $rhs)
			{
				
				$res = array_unique(array_merge($res, first($rhs)));
			}
			return $res;
		}
		else if($s === "")
		{
			return [];
		}
		else
		{
			return [$s];
		}
	}
	else
	{
		$first = array_shift($symbol_seq);
		if(nullable([$first]))
		{
			return array_unique(array_merge(first([$first]), first($symbol_seq)));
		}
		else
		{
			return first([$first]);	
		}
	}
}

$G = new ContextFreeGrammar();

$N_ = $G->new_nonterminal();
$N = $G->new_nonterminal();
$A = $G->new_nonterminal();
$B = $G->new_nonterminal();
$C = $G->new_nonterminal();

$N_->add_rhs([$N, TERMINAL_END]);
$N->add_rhs([$A, $B]);
$N->add_rhs([$B, $A]);
$A->add_rhs(['a']);
$A->add_rhs([$C, $A, $C]);
$B->add_rhs(['b']);
$B->add_rhs([$C, $B, $C]);
$C->add_rhs(['a']);
$C->add_rhs(['b']);

assert($B->follow() === false);
?>