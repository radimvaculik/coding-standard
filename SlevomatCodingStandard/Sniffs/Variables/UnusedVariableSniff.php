<?php declare(strict_types = 1);

namespace SlevomatCodingStandard\Sniffs\Variables;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\PropertyHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;
use const T_AND_EQUAL;
use const T_AS;
use const T_BITWISE_AND;
use const T_CLOSE_SHORT_ARRAY;
use const T_CLOSURE;
use const T_COMMA;
use const T_CONCAT_EQUAL;
use const T_DEC;
use const T_DIV_EQUAL;
use const T_DO;
use const T_DOUBLE_ARROW;
use const T_DOUBLE_COLON;
use const T_EQUAL;
use const T_FOR;
use const T_FOREACH;
use const T_INC;
use const T_LIST;
use const T_MINUS_EQUAL;
use const T_MOD_EQUAL;
use const T_MUL_EQUAL;
use const T_OBJECT_OPERATOR;
use const T_OPEN_TAG;
use const T_OR_EQUAL;
use const T_PLUS_EQUAL;
use const T_POW_EQUAL;
use const T_SL_EQUAL;
use const T_SR_EQUAL;
use const T_STATIC;
use const T_USE;
use const T_VARIABLE;
use const T_WHILE;
use const T_XOR_EQUAL;
use function array_keys;
use function array_merge;
use function array_reverse;
use function count;
use function in_array;
use function sprintf;

class UnusedVariableSniff implements Sniff
{

	public const CODE_UNUSED_VARIABLE = 'UnusedVariable';

	/**
	 * @return mixed[]
	 */
	public function register(): array
	{
		return [
			T_VARIABLE,
		];
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.TypeHintDeclaration.MissingParameterTypeHint
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $variablePointer
	 */
	public function process(File $phpcsFile, $variablePointer): void
	{
		if (!$this->isAssignment($phpcsFile, $variablePointer)) {
			return;
		}

		$tokens = $phpcsFile->getTokens();

		if (in_array($tokens[$variablePointer - 1]['code'], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
			// Property
			return;
		}

		$variableName = $tokens[$variablePointer]['content'];

		if ($this->isUsedInForLoopCondition($phpcsFile, $variablePointer, $variableName)) {
			return;
		}

		if ($this->isUsedInLoop($phpcsFile, $variablePointer, $variableName)) {
			return;
		}

		$scopeOwnerPointer = TokenHelper::findPrevious($phpcsFile, T_OPEN_TAG, $variablePointer - 1);
		foreach (array_reverse($tokens[$variablePointer]['conditions'], true) as $conditionPointer => $conditionTokenCode) {
			if (in_array($conditionTokenCode, TokenHelper::$functionTokenCodes, true)) {
				$scopeOwnerPointer = $conditionPointer;
				break;
			}
		}
		$scopeCloserPointer = $tokens[$scopeOwnerPointer]['code'] === T_OPEN_TAG ? count($tokens) - 1 : $tokens[$scopeOwnerPointer]['scope_closer'];

		if (in_array($tokens[$scopeOwnerPointer]['code'], TokenHelper::$functionTokenCodes, true)) {
			if ($this->isStaticVariable($phpcsFile, $scopeOwnerPointer, $variableName)) {
				return;
			}

			if ($this->isParameterPassedByReference($phpcsFile, $scopeOwnerPointer, $variableName)) {
				return;
			}

			if ($tokens[$scopeOwnerPointer]['code'] === T_CLOSURE) {
				if ($this->isInheritedVariablePassedByReference($phpcsFile, $scopeOwnerPointer, $variableName)) {
					return;
				}
			}
		}

		$isInSameScope = function (int $pointer) use ($variablePointer, $tokens): bool {
			foreach (array_reverse($tokens[$pointer]['conditions'], true) as $conditionPointer => $conditionTokenCode) {
				if ($tokens[$conditionPointer]['level'] <= $tokens[$variablePointer]['level']) {
					break;
				}

				if ($conditionTokenCode === T_CLOSURE) {
					return false;
				}
			}

			return true;
		};

		for ($i = $variablePointer + 1; $i <= $scopeCloserPointer; $i++) {
			if ($tokens[$i]['code'] !== T_VARIABLE) {
				continue;
			}

			if (!$isInSameScope($i)) {
				continue;
			}

			if ($tokens[$i]['content'] === $variableName) {
				return;
			}
		}

		$phpcsFile->addError(
			sprintf('Unused variable %s.', $variableName),
			$variablePointer,
			self::CODE_UNUSED_VARIABLE
		);
	}

	private function isAssignment(File $phpcsFile, int $variablePointer): bool
	{
		$tokens = $phpcsFile->getTokens();

		$nextPointer = TokenHelper::findNextEffective($phpcsFile, $variablePointer + 1);
		if (in_array($tokens[$nextPointer]['code'], [
			T_EQUAL,
			T_PLUS_EQUAL,
			T_MINUS_EQUAL,
			T_MUL_EQUAL,
			T_DIV_EQUAL,
			T_POW_EQUAL,
			T_MOD_EQUAL,
			T_AND_EQUAL,
			T_OR_EQUAL,
			T_XOR_EQUAL,
			T_SL_EQUAL,
			T_SR_EQUAL,
			T_CONCAT_EQUAL,
		], true)) {
			if ($tokens[$nextPointer]['code'] === T_EQUAL) {
				if (PropertyHelper::isProperty($phpcsFile, $variablePointer)) {
					return false;
				}

				if (isset($tokens[$variablePointer]['nested_parenthesis'])) {
					$parenthesisOpenerPointer = array_reverse(array_keys($tokens[$variablePointer]['nested_parenthesis']))[0];
					if (isset($tokens[$parenthesisOpenerPointer]['parenthesis_owner'])) {
						$parenthesisOwnerPointer = $tokens[$parenthesisOpenerPointer]['parenthesis_owner'];
						if (in_array($tokens[$parenthesisOwnerPointer]['code'], TokenHelper::$functionTokenCodes, true)) {
							// Parameter with default value
							return false;
						}
					}
				}
			}

			return true;
		}

		$parenthesisOwnerPointer = null;
		$parenthesisOpenerPointer = null;
		if (isset($tokens[$variablePointer]['nested_parenthesis'])) {
			$parenthesisOpenerPointer = array_reverse(array_keys($tokens[$variablePointer]['nested_parenthesis']))[0];
			if (isset($tokens[$parenthesisOpenerPointer]['parenthesis_owner'])) {
				$parenthesisOwnerPointer = $tokens[$parenthesisOpenerPointer]['parenthesis_owner'];
			}
		}

		if (in_array($tokens[$nextPointer]['code'], [T_INC, T_DEC], true)) {
			if ($parenthesisOwnerPointer === null) {
				return true;
			}

			return $tokens[$parenthesisOwnerPointer]['code'] !== T_FOR;
		}

		if ($parenthesisOwnerPointer !== null && $tokens[$parenthesisOwnerPointer]['code'] === T_FOREACH) {
			$pointerBeforeVariable = TokenHelper::findPreviousEffective($phpcsFile, $variablePointer - 1);
			return in_array($tokens[$pointerBeforeVariable]['code'], [T_AS, T_DOUBLE_ARROW], true);
		}

		if ($parenthesisOpenerPointer !== null) {
			$pointerBeforeParenthesisOpener = TokenHelper::findPreviousEffective($phpcsFile, $parenthesisOpenerPointer - 1);
			if ($tokens[$pointerBeforeParenthesisOpener]['code'] === T_LIST) {
				return true;
			}
		}

		$possibleShortListCloserPointer = TokenHelper::findNextExcluding(
			$phpcsFile,
			array_merge(TokenHelper::$ineffectiveTokenCodes, [T_VARIABLE, T_COMMA]),
			$variablePointer + 1
		);
		if ($tokens[$possibleShortListCloserPointer]['code'] === T_CLOSE_SHORT_ARRAY) {
			return $tokens[TokenHelper::findNextEffective($phpcsFile, $possibleShortListCloserPointer + 1)]['code'] === T_EQUAL;
		}

		return false;
	}

	private function isUsedInForLoopCondition(File $phpcsFile, int $variablePointer, string $variableName): bool
	{
		$tokens = $phpcsFile->getTokens();

		if (!isset($tokens[$variablePointer]['nested_parenthesis'])) {
			return false;
		}

		$parenthesisOpenerPointer = array_reverse(array_keys($tokens[$variablePointer]['nested_parenthesis']))[0];
		if (!isset($tokens[$parenthesisOpenerPointer]['parenthesis_owner'])) {
			return false;
		}

		$parenthesisOwnerPointer = $tokens[$parenthesisOpenerPointer]['parenthesis_owner'];
		if ($tokens[$parenthesisOwnerPointer]['code'] !== T_FOR) {
			return false;
		}

		for ($i = $parenthesisOpenerPointer + 1; $i < $tokens[$parenthesisOwnerPointer]['parenthesis_closer']; $i++) {
			if ($i === $variablePointer) {
				continue;
			}

			if ($tokens[$i]['code'] !== T_VARIABLE) {
				continue;
			}

			if ($tokens[$i]['content'] !== $variableName) {
				continue;
			}

			return true;
		}

		return false;
	}

	private function isUsedInLoop(File $phpcsFile, int $variablePointer, string $variableName): bool
	{
		$tokens = $phpcsFile->getTokens();

		$loopPointer = null;
		foreach (array_reverse($tokens[$variablePointer]['conditions'], true) as $conditionPointer => $conditionTokenCode) {
			if (in_array($conditionTokenCode, TokenHelper::$functionTokenCodes, true)) {
				break;
			}

			if (!in_array($conditionTokenCode, [T_FOREACH, T_FOR, T_DO, T_WHILE], true)) {
				continue;
			}

			$loopPointer = $conditionPointer;

			if (in_array($tokens[$loopPointer]['code'], [T_FOR, T_FOREACH], true)) {
				continue;
			}

			$loopConditionPointer = $tokens[$loopPointer]['code'] === T_DO
				? TokenHelper::findNextEffective($phpcsFile, $tokens[$loopPointer]['scope_closer'] + 1)
				: $loopPointer;

			$variableUsedInLoopConditionPointer = TokenHelper::findNextContent(
				$phpcsFile,
				T_VARIABLE,
				$variableName,
				$tokens[$loopConditionPointer]['parenthesis_opener'] + 1,
				$tokens[$loopConditionPointer]['parenthesis_closer']
			);
			if ($variableUsedInLoopConditionPointer !== null && $variableUsedInLoopConditionPointer !== $variablePointer) {
				return true;
			}
		}

		if ($loopPointer === null) {
			return false;
		}

		for ($i = $tokens[$loopPointer]['scope_opener'] + 1; $i < $tokens[$loopPointer]['scope_closer']; $i++) {
			if ($tokens[$i]['code'] !== T_VARIABLE) {
				continue;
			}

			if ($tokens[$i]['content'] !== $variableName) {
				continue;
			}

			if (!$this->isAssignment($phpcsFile, $i)) {
				return true;
			}
		}

		return false;
	}

	private function isStaticVariable(File $phpcsFile, int $functionPointer, string $variableName): bool
	{
		$tokens = $phpcsFile->getTokens();

		for ($i = $tokens[$functionPointer]['scope_opener'] + 1; $i < $tokens[$functionPointer]['scope_closer']; $i++) {
			if ($tokens[$i]['code'] !== T_VARIABLE) {
				continue;
			}
			if ($tokens[$i]['content'] !== $variableName) {
				continue;
			}

			$pointerBeforeParameter = TokenHelper::findPreviousEffective($phpcsFile, $i - 1);
			if ($tokens[$pointerBeforeParameter]['code'] === T_STATIC) {
				return true;
			}
		}

		return false;
	}

	private function isParameterPassedByReference(File $phpcsFile, int $functionPointer, string $variableName): bool
	{
		$tokens = $phpcsFile->getTokens();

		for ($i = $tokens[$functionPointer]['parenthesis_opener'] + 1; $i < $tokens[$functionPointer]['parenthesis_closer']; $i++) {
			if ($tokens[$i]['code'] !== T_VARIABLE) {
				continue;
			}
			if ($tokens[$i]['content'] !== $variableName) {
				continue;
			}

			$pointerBeforeParameter = TokenHelper::findPreviousEffective($phpcsFile, $i - 1);
			if ($tokens[$pointerBeforeParameter]['code'] === T_BITWISE_AND) {
				return true;
			}
		}

		return false;
	}

	private function isInheritedVariablePassedByReference(File $phpcsFile, int $functionPointer, string $variableName): bool
	{
		$tokens = $phpcsFile->getTokens();

		$usePointer = TokenHelper::findNextEffective($phpcsFile, $tokens[$functionPointer]['parenthesis_closer'] + 1);
		if ($tokens[$usePointer]['code'] !== T_USE) {
			return false;
		}

		$useParenthesisOpener = TokenHelper::findNextEffective($phpcsFile, $usePointer + 1);
		for ($i = $useParenthesisOpener + 1; $i < $tokens[$useParenthesisOpener]['parenthesis_closer']; $i++) {
			if ($tokens[$i]['code'] !== T_VARIABLE) {
				continue;
			}
			if ($tokens[$i]['content'] !== $variableName) {
				continue;
			}

			$pointerBeforeInheritedVariable = TokenHelper::findPreviousEffective($phpcsFile, $i - 1);
			if ($tokens[$pointerBeforeInheritedVariable]['code'] === T_BITWISE_AND) {
				return true;
			}
		}

		return false;
	}

}
