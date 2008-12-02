<?php
/**
 * PHP pretty printer that follows the following rules:
 * 
 * Comments:
 *   # style comments are converted to // style
 *   Multi-line // comments are converted doc-style
 *   Doc-style comments start with /* or * on each line
 *   All comments have 2 blank lines if in main code or in a class
 *   All comments have 1 blank line otherwise (in a function)
 *   Blank lines are removed if the comment is the first thing in a code block
 * 
 * Functions:
 *   Parenthesis and spacing:  a();  b(1, 2);
 *   Functions have two spaces above them unless there is a comment
 *   The first function in a class has no spacing if nothing precedes it
 * 
 * Variables:
 *   Variables and spacing:  $a += 1 && $b .= 'string';  &$a;
 *   Strings are single-quoted if possible
 *   Heredocs are eliminated
 *   Each element of an array is on its own line
 *   'var' is replaced with 'public'
 * 
 * Classes:
 *   All methods get marked with public, private, protected
 *   All variables get marked with public, private, protected
 * 
 * Switches:
 *   Newline after 'break' when followed by 'case' or 'default'
 *   1 indent for case and default, 2 for code beneath
 * 
 * Language:
 *   Start tags are all in lower case
 *   '<?=' is replaced by '<?php echo'
 *   Indentation is done with tabs
 *   Open braces on same line as function/switch/etc.
 *   'else' on same line as close braceA
 *   Spacing for 'for' lines:  for ($a; $b; $c) {
 */
class PHP_Beautifier_Filter_beautify extends PHP_Beautifier_Filter {
	protected $aSettings = array();
	protected $sDescription = 'Reprocess and format the code to make it easy on the eyes';
	protected $controlInDo = 0;
	protected $inHereDoc = false;
	protected $modifiers = array();
	static protected $modifierOrder = array(
		'abstract',
		'const',
		'static',
		'private',
		'public',
		'protected'
	);
	static protected $ignoreTokens = array(
		T_ABSTRACT => true,
		T_COMMENT => true,
		T_DOC_COMMENT => true,
		T_FINAL => true,
		T_PRIVATE => true,
		T_PROTECTED => true,
		T_PUBLIC => true,
		T_STATIC => true,
	);
	
	
	/**
	 * Filter constructor
	 */
	public function __construct(PHP_Beautifier$oBeaut, $aSettings) {
		parent::__construct($oBeaut, $aSettings);
		$oBeaut->setIndentChar("\t");
		$oBeaut->setIndentNumber(1);
		
		/* Enable logging with this command */
		
		// $this->addLog();
	}
	
	
	/**
	 * Debug function to turn on logging and log to console
	 */
	protected function addLog() {
		$oLog = PHP_Beautifier_Common::getLog();
		$oLogConsole = Log::factory('console', '', 'php_beautifier', array(
				'stream' => STDERR,
			), PEAR_LOG_DEBUG);
		$oLog->addChild($oLogConsole);
	}
	
	
	/**
	 * Debug function to add in the previous/next token's name
	 */
	protected function addToken($index) {
		if ($index > 0) {
			$token = $this->oBeaut->getNextTokenConstant($index);
		} elseif ($index < 0) {
			$token = $this->oBeaut->getPreviousTokenConstant(- $index);
		} else {
			$token = $this->oBeaut->getToken($this->oBeaut->iCount);
		}

		if (is_array($token)) {
			$token = $token[0];
		}
		
		$out = '[' . $index . ':';
		$name = $this->oBeaut->getTokenName($token);
		
		if ($name) {
			$out .= $name;
		} else {
			$out .= $token;
		}
		
		$out .= ']';
		$this->oBeaut->add($out);
	}
	
	
	/**
	 * Locates the most recent token that is not ignored/removed and that
	 * is not a comment.  Constants can be passed in and they will not be
	 * skipped.
	 */
	protected function findLastGoodToken($count = 1, $doNotSkip = array()) {
		$prevIndex = 1;
		$prevToken = $this->oBeaut->getPreviousTokenConstant($prevIndex);
		$tokens = self::$ignoreTokens;
		
		if (is_array($doNotSkip)) {
			foreach ($doNotSkip as $type) {
				unset($tokens[$type]);
			}
		}
		
		while (isset($tokens[$prevToken]) || -- $count) {
			$prevIndex ++;
			$prevToken = $this->oBeaut->getPreviousTokenConstant($prevIndex);
		}
		
		$this->lastGoodToken = $prevToken;
	}
	
	
	/**
	 * Merge together //-style comments on successive lines into a single
	 * doc-style comment (like this one).
	 * 
	 * I touch internals here because there is no mechanism to delete
	 * tokens out of the oBeaut->aTokens array.
	 */
	protected function merge_comments($sTag) {
		$commentLines = array(
			$sTag
		);
		
		while ($this->oBeaut->isNextTokenConstant(T_COMMENT)) {
			$idx = $this->oBeaut->iCount + 1;
			$token = $this->oBeaut->getToken($idx);
			array_splice($this->oBeaut->aTokens, $idx, 1);
			
			if (is_array($token) && $token[0] != T_WHITESPACE) {
				$comment = $token[1];
				$comment = str_replace('/*', '/ *', $comment);
				$comment = str_replace('*/', '* /', $comment);
				$commentLines[] = $comment;
			}
		}
		
		$first = true;
		
		foreach ($commentLines as &$line) {
			$line = preg_replace('/^[ \\t]*(\\/\\/|#)/', '', $line);
			$line = rtrim($line);
			
			if ($first) {
				$line = '/*' . $line;
				$first = false;
			} elseif (substr($line, 0, 1) == '/') {
				$line = ' * ' . $line;
			} else {
				$line = ' *' . $line;
			}
		}
		
		$line .= ' */';
		return $this->t_doc_comment(implode("\n", $commentLines));
	}
	
	
	/**
	 * Write out the modifiers for the function or variable.
	 * 
	 * This function guarantees that they are in a specific order.
	 */
	private function outputModifiers($sTag) {
		$m = $this->modifiers;
		$this->modifiers = array();
		$thisControl = $this->oBeaut->getControlSeq();
		$prevControl = $this->oBeaut->getControlSeq(1);
		
		if (($thisControl == T_CLASS || $prevControl == T_CLASS) && strtolower($sTag) != 'class') {
			if (! (isset($m['public']) || isset($m['protected']) || isset($m['private']))) {
				$m['public'] = true;
			}
		}
		
		foreach (self::$modifierOrder as $modifier) {
			if (isset($m[$modifier])) {
				$this->oBeaut->add($modifier . ' ');
			}
		}
		
		$this->oBeaut->add($sTag . ' ');
	}
	
	
	/**
	 * Remove whitespace, then guarantee that there are total of
	 * $newlines newlines between the last token and the current one.
	 * 
	 * We touch internals here because oBeaut->removeWhitespace decides
	 * to not trim whitespace at the end of short comments, for whatever
	 * stupid reason.
	 */
	public function pad($newlines) {
		for ($i = count($this->oBeaut->aOut) - 1; $i >= 0; $i --) {
			$cNow = &$this->oBeaut->aOut[$i];
			$cNow = rtrim($cNow);
			
			if (strlen($cNow) == 0) {
				// Delete it since it was only whitespace
				array_pop($this->oBeaut->aOut);
			} else {
				break;
			}
		}
		
		while ($newlines --) {
			$this->oBeaut->addNewLineIndent();
		}
	}
	
	
	/**
	 * Mark an access modifier as "encountered' so that $this->outputModifiers
	 * will write it out
	 */
	public function t_access($sTag) {
		if ($this->oBeaut->getControlSeq() != T_CLASS || $sTag == 'interface' || $sTag == 'const') {
			return PHP_Beautifier_Filter::BYPASS;
		}
		
		$this->modifiers[strtolower($sTag)] = true;
	}
	
	
	// Handles all +=, .=, -=, and similar constructs
	public function t_assigment_pre($sTag) {
		$this->oBeaut->removeWhitespace();
		$this->oBeaut->add(' ' . $sTag . ' ');
	}
	
	
	// Break statements, typically found in loops and switches
	public function t_break($sTag) {
		$this->oBeaut->add($sTag);
		
		if ($this->oBeaut->isNextTokenConstant(T_LNUMBER)) {
			$this->oBeaut->add(' ');
		}
	}
	
	
	// Case statements, found only in switches
	public function t_case($sTag) {
		$this->oBeaut->removeWhitespace();
		$this->oBeaut->decIndent();
		$this->findLastGoodToken();
		
		if ($this->lastGoodToken != '{') {
			$this->findLastGoodToken(2);
			
			if ($this->lastGoodToken == T_BREAK) {
				$this->oBeaut->addNewLine();
			}
		}
		
		$this->oBeaut->addNewLineIndent();
		$this->oBeaut->add($sTag . ' ');
	}
	
	
	/**
	 * Close braces
	 * 
	 * Functionality from the Default filter is copied here because it did
	 * not erase whitespace between a comment at the end of a function and
	 * the closing brace
	 */
	public function t_close_brace($sTag) {
		$this->controlInDo >>= 1;
		
		if ($this->oBeaut->getMode('string_index') || $this->oBeaut->getMode('double_quote')) {
			$this->oBeaut->add($sTag);
			return;
		}
		
		$this->oBeaut->decIndent();
		
		if ($this->oBeaut->getControlSeq() == T_SWITCH) {
			$this->oBeaut->decIndent();
		}
		
		$this->pad(1);
		$this->oBeaut->add($sTag);
		$this->oBeaut->addNewLineIndent();
		$this->oBeaut->addNewLineIndent();
	}
	
	
	// Close PHP tag; handle whitespace
	public function t_close_tag($sTag) {
		$ws = '';
		$token = $this->oBeaut->getToken($this->oBeaut->iCount - 1);

		if (is_array($token) && $token[0] == T_WHITESPACE) {
			$ws .= $token[1];
		}

		if (strpos($ws, "\n") !== false) {
			$this->pad(2);
		} else {
			$this->pad(0);
			$this->oBeaut->add(' ');
		}

		$token = $this->oBeaut->getToken($this->oBeaut->iCount);
		
		if (is_array($token)) {
			$this->oBeaut->add($token[1]);
		} else {
			$this->oBeaut->add('?>');
		}
	}
	
	
	/**
	 * Commas, really only special in arrays and function calls
	 * 
	 * They are really only special in arrays and function calls.
	 * Arrays shouldn't add the newline if the next token is T_COMMENT.
	 */
	public function t_comma($sTag) {
		if ($this->oBeaut->getControlParenthesis() == T_ARRAY) {
			$this->oBeaut->add($sTag);
			
			if (! $this->oBeaut->isNextTokenConstant(T_COMMENT)) {
				$this->oBeaut->addNewLineIndent();
			}
		} else {
			$this->oBeaut->add($sTag . ' ');
		}
	}
	
	
	/**
	 * Comment handler.
	 * 
	 * Can get called for single line doc-style comments plus # and // style
	 * comments.  Converts # style into //.  Converts multi-line // and #
	 * style into doc-style comments.
	 */
	public function t_comment($sTag) {
		if (substr($sTag, 0, 2) == '/*') {
			return $this->t_doc_comment($sTag);
		}
		
		if ($this->oBeaut->isNextTokenConstant(T_COMMENT)) {
			return $this->merge_comments($sTag);
		}
		
		if ($sTag{0} == '#') {
			$sTag = '//' . substr($sTag, 1);
		}
		
		if (substr($sTag, 0, 3) != '// ') {
			$sTag = '// ' . substr($sTag, 2);
		}
		
		$ws = $this->oBeaut->getPreviousWhitespace();
		$sTag = trim($sTag);
		
		if (strpos($ws, "\n") !== false || $this->oBeaut->isPreviousTokenConstant(T_OPEN_TAG, 1)) {
			// Comment on separate line or first line in file
			$this->findLastGoodToken(1, array(
					T_COMMENT,
					T_DOC_COMMENT
				));
			
			if ($this->lastGoodToken != '{' && $this->lastGoodToken != '(') {
				/* Add extra space above comments at main level or between
				 * functions in classes */
				$controlSeq = $this->oBeaut->getControlSeq();
				
				if (! $controlSeq || $controlSeq == T_CLASS) {
					$this->pad(3);
				} else {
					$this->pad(2);
				}
			} else {
				$this->pad(1);
			}
		} else {
			// Same line comment
			$this->oBeaut->removeWhitespace();
			$this->oBeaut->add('  ');
		}
		
		$this->oBeaut->add($sTag);
		$this->oBeaut->addNewLine();
		$this->oBeaut->addIndent();
	}
	
	
	/**
	 * String handler
	 * 
	 * Try to convert double-quoted strings into single-quoted strings.
	 */
	public function t_constant_encapsed_string($sTag) {
		if (substr($sTag, 0, 1) == '\'') {
			$prev = $this->oBeaut->getPreviousTokenConstant();
			
			if ($prev == T_INCLUDE || $prev == T_INCLUDE_ONCE || $prev == T_REQUIRE || $prev == T_REQUIRE_ONCE || $prev == T_ECHO) {
				$this->oBeaut->removeWhitespace();
				$this->oBeaut->add(' ');
				$this->oBeaut->add($sTag);
				return;
			}
			
			return PHP_Beautifier_Filter::BYPASS;
		}
		
		// Detect for things that require double quotes
		$isEscaped = false;
		$singleQuoteString = '';
		$sTag = substr($sTag, 1, strlen($sTag) - 2);
		
		foreach (str_split($sTag) as $char) {
			if ($isEscaped) {
				if (preg_match('/[nrtvf0-9x]/', $char)) {
					return PHP_Beautifier_Filter::BYPASS;
				}
				
				$isEscaped = false;
				
				if ($char == '$' || $char == '"') {
					$singleQuoteString .= $char;
				} elseif ($char == '\\') {
					$singleQuoteString .= '\\\\';
				} else {
					$singleQuoteString = '\\\\' . $char;
				}
			} else {
				if ($char == '$') {
					return PHP_Beautifier_Filter::BYPASS;
				}
				
				if ($char == '\\') {
					$isEscaped = true;
				} elseif ($char == '\'') {
					$singleQuoteString .= '\\\'';
				} else {
					$singleQuoteString .= $char;
				}
			}
		}
		
		$this->oBeaut->add('\'' . $singleQuoteString . '\'');
	}
	
	
	/**
	 * Generic control statement handler
	 * 
	 * Nothing too special, but we need to handle the "do {} while ()" syntax.
	 * 
	 * There is a bug in PHP_Beautifier 0.1.14 where the "do {} while()"
	 * construct is not handled properly with the push/pop of control sequence.
	 */
	public function t_control($sTag) {
		if ($this->controlInDo & 0x01) {
			$this->pad(0);
			$this->oBeaut->add(' ' . $sTag);
			$this->controlInDo ^= 0x01;
			return;
		}
		
		if (strtolower($sTag) == 'do') {
			$this->controlInDo += 0x01;
		}
		
		$this->findLastGoodToken(1, array(
				T_COMMENT,
				T_DOC_COMMENT
			));
		
		if ($this->lastGoodToken != '{' && $this->lastGoodToken != T_COMMENT && $this->lastGoodToken != T_DOC_COMMENT) {
			$this->pad(2);
		} else {
			$this->pad(1);
		}
		
		$this->oBeaut->add($sTag);
	}
	
	
	// The "default" entry in a case statement
	public function t_default($sTag) {
		return $this->t_case($sTag);
	}
	
	
	// Doc-style comment handler.
	public function t_doc_comment($sTag) {
		/* Add extra space above comments at main level or
		 * between functions in classes */
		$controlSeq = $this->oBeaut->getControlSeq();
		$this->findLastGoodToken(1, array(
				T_COMMENT,
				T_DOC_COMMENT
			));
		
		if (! $controlSeq || $controlSeq == T_CLASS) {
			if ($this->lastGoodToken == '{' || $this->lastGoodToken == T_OPEN_TAG) {
				$this->pad(0);
			} else {
				$this->pad(2);
			}
		} else {
			if ($this->lastGoodToken != '{') {
				$this->pad(1);
			} else {
				$this->pad(0);
			}
		}
		
		$sTag = array_map('trim', explode("\n", $sTag));
		
		foreach ($sTag as $line) {
			$this->oBeaut->addNewLineIndent();
			
			if (substr($line, 0, 2) != '/*') {
				if (substr($line, 0, 1) != '*') {
					$line = '* ' . $line;
				} elseif (! preg_match('/^\\*[\\*\\ \\/]/', $line)) {
					$line = '* ' . substr($line, 1);
				}
				
				$line = ' ' . $line;
			}
			
			$this->oBeaut->add($line);
		}
		
		$this->oBeaut->addNewLineIndent();
	}
	
	
	/**
	 * Heredoc content handler and double-quote with variables handler
	 * 
	 * Convert all heredocs to strings
	 */
	public function t_encapsed_and_whitespace($sTag) {
		if (! $this->inHereDoc) {
			return PHP_Beautifier_Filter::BYPASS;
		}
		
		$lines = explode("\n", $sTag);
		$this->oBeaut->incIndent();
		
		foreach ($lines as $key => $line) {
			$line = str_replace('\\', '\\\\', $line);
			$line = str_replace('"', '\\"', $line);
			$this->oBeaut->add($line);
			
			if (isset($lines[$key + 1])) {
				// There is at least one more
				$this->oBeaut->add('\\n');
				
				if (isset($lines[$key + 2]) || $lines[$key + 1] != '') {
					$this->oBeaut->add('" .');
					$this->oBeaut->addNewLineIndent();
					$this->oBeaut->add('"');
				}
			}
		}
		
		$this->oBeaut->decIndent();
	}
	
	
	// Heredoc end tag - convert all heredocs to strings
	public function t_end_heredoc($sTag) {
		$this->inHereDoc = false;
		$this->oBeaut->add('";');
	}
	
	
	// For some reason, for is not handled as a control structure
	public function t_for($sTag) {
		return $this->t_control($sTag);
	}
	
	
	// For some reason, foreach is not handled as a control structure
	public function t_foreach($sTag) {
		return $this->t_control($sTag);
	}
	
	
	// include and require
	public function t_include($sTag) {
		$this->findLastGoodToken(1, array(
				T_COMMENT,
				T_DOC_COMMENT
			));
		
		switch ($this->lastGoodToken) {
			case T_OPEN_TAG:
				$this->pad(2);
				break;

			default:
				$this->pad(1);
		}
		
		$this->oBeaut->add($sTag);
	}
	
	
	// Generic language constructs (function, class, var)
	public function t_language_construct($sTag) {
		switch (strtolower($sTag)) {
			case 'function':
			case 'class':
				$this->findLastGoodToken(1, array(
						T_COMMENT,
						T_DOC_COMMENT
					));
				
				switch ($this->lastGoodToken) {
					case T_COMMENT:
					case T_DOC_COMMENT:
					case '{':
						$this->pad(1);
						break;

					case '}':
					case ';':
						$this->pad(3);
						break;

					default:
						$this->pad(2);
				}
				
				$this->outputModifiers($sTag);
				break;

			case 'var':
				$this->oBeaut->add('public ');
				break;

			default:
				return PHP_Beautifier_Filter::BYPASS;
		}
	}
	
	
	// Open braces, which we only mess with class and function braces
	public function t_open_brace($sTag) {
		$this->controlInDo <<= 1;
		
		switch ($this->oBeaut->getControlSeq()) {
			case T_CLASS:
			case T_FUNCTION:
				break;

			default:
				return PHP_Beautifier_Filter::BYPASS;
		}
		
		$this->oBeaut->removeWhitespace();
		$this->oBeaut->add(' ' . $sTag);
		$this->oBeaut->incIndent();
		$this->oBeaut->addNewLineIndent();
	}
	
	
	// Open PHP tag; force to all lowercase
	public function t_open_tag($sTag) {
		$this->oBeaut->add('<?php');
		$token = $this->oBeaut->getToken($this->oBeaut->iCount);
		$ws = '';
		
		if (is_array($token)) {
			$ws .= $token[1];
		}

		$token = $this->oBeaut->getToken($this->oBeaut->iCount + 1);

		if (is_array($token) && $token[0] == T_WHITESPACE) {
			$ws .= $token[1];
		}
		
		if (strpos($ws, "\n") !== false) {
			$this->pad(2);
		} else {
			$this->oBeaut->add(' ');
		}
	}
	
	
	// Open PHP tag as "<?="; force to lowercase and 'echo' call
	public function t_open_tag_with_echo($sTag) {
		$this->oBeaut->add('<?php echo ');
	}
	
	
	// Handle mathematical operators
	public function t_operator($sTag) {
		$this->oBeaut->removeWhitespace();
		$prevToken = $this->oBeaut->getPreviousTokenContent();
		
		if ($prevToken != '(') {
			$this->oBeaut->add(' ');
		}
		
		$this->oBeaut->add($sTag);
		$nextToken = $this->oBeaut->getNextTokenContent();
		
		if ($nextToken != '(') {
			$this->oBeaut->add(' ');
		}
	}
	
	
	// Close parenthesis with special handling for arrays
	public function t_parenthesis_close($sTag) {
		if ($this->oBeaut->getControlParenthesis() == T_ARRAY) {
			$this->oBeaut->decIndent();
			
			if ($this->oBeaut->getPreviousTokenContent() != '(') {
				$this->pad(1);
			} else {
				$this->pad(0);
			}
			
			$this->oBeaut->add($sTag);
			return;
		}
		
		$this->oBeaut->add($sTag);
		$this->oBeaut->decIndent();
	}
	
	
	// Open parenthesis with special handling for arrays
	public function t_parenthesis_open($sTag) {
		$this->oBeaut->removeWhitespace();
		
		if (! $this->oBeaut->isPreviousTokenConstant(array(
					T_ARRAY,
					T_EMPTY,
					T_EVAL,
					T_EXIT,
					T_INCLUDE,
					T_INCLUDE_ONCE,
					T_ISSET,
					T_REQUIRE,
					T_REQUIRE_ONCE,
					T_STRING,
					T_UNSET,
					T_VARIABLE,
					'(',
				), 1)) {
			$this->oBeaut->add(' ');
		}
		
		$this->oBeaut->add($sTag);
		$this->oBeaut->incIndent();
		
		if ($this->oBeaut->getControlParenthesis() == T_ARRAY && $this->oBeaut->getNextTokenContent() != ')') {
			$this->oBeaut->addNewLineIndent();
		}
	}
	
	
	// Semicolon handler, specifically for 'for'
	public function t_semi_colon($sTag) {
		if ($this->oBeaut->getControlParenthesis() == T_FOR) {
			// The three terms in the head of a for loop are separated by the string "; "
			$this->oBeaut->removeWhitespace();
			$this->oBeaut->add($sTag . ' ');
		} else {
			$this->oBeaut->removeWhitespace();
			$this->oBeaut->add($sTag);
			$this->oBeaut->addNewLineIndent();
		}
	}
	
	
	// Heredoc start tag - convert all heredocs to strings
	public function t_start_heredoc($sTag) {
		$this->inHereDoc = true;
		$this->oBeaut->add('"');
	}
	
	
	// For some reason, try is not handled as a control structure
	public function t_try($sTag) {
		return $this->t_control($sTag);
	}
	
	
	// Variables
	public function t_variable($sTag) {
		if ($this->oBeaut->getControlSeq() != T_CLASS) {
			$prev = $this->oBeaut->getPreviousTokenConstant();
			
			if ($prev == T_STRING && $this->oBeaut->getMode('double_quote')) {
				$this->oBeaut->removeWhiteSpace();
				$this->oBeaut->add(' ');
			} elseif ($prev == '&') {
				$this->oBeaut->removeWhiteSpace();
			} elseif ($prev == '!' || $prev == T_DEC || $prev == T_INC || $prev == T_REQUIRE || $prev == T_INCLUDE || $prev == T_REQUIRE_ONCE || $prev == T_INCLUDE_ONCE || $prev == T_ECHO) {
				$this->oBeaut->removeWhiteSpace();
				$this->oBeaut->add(' ');
			}
			
			$this->oBeaut->add($sTag);
			$next = $this->oBeaut->getNextTokenConstant();
			
			if ($next == T_DEC || $next == T_INC) {
				$this->oBeaut->add(' ');
			}
			
			return;
		}
		
		$this->findLastGoodToken(1, T_COMMENT);
		
		if ($this->lastGoodToken == '}') {
			$this->pad(3);
		} else {
			$this->pad(1);
		}
		
		$this->outputModifiers($sTag);
	}
}
?>
