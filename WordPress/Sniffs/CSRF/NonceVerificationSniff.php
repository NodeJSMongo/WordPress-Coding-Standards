<?php

/**
 * WordPress_Sniffs_CSRF_NonceVerificationSniff.
 *
 * PHP version 5
 *
 * @since 0.5.0
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 */

/**
 * Checks that nonce verification accompanies form processing.
 *
 * @since 0.5.0
 *
 * @category PHP
 * @package  PHP_CodeSniffer
 * @author   J.D. Grimes <jdg@codesymphony.co>
 * @link     https://developer.wordpress.org/plugins/security/nonces/ Nonces on Plugin Developer Handbook
 */
class WordPress_Sniffs_CSRF_NonceVerificationSniff extends WordPress_Sniff {

	/**
	 * Superglobals to give an error for when not accompanied by an nonce check.
	 *
	 * @since 0.5.0
	 *
	 * @var array
	 */
	public $errorForSuperGlobals = array( '$_POST', '$_FILE' );

	/**
	 * Superglobals to give a warning for when not accompanied by an nonce check.
	 *
	 * If the variable is also in the error list, that takes precedence.
	 *
	 * @since 0.5.0
	 *
	 * @var array
	 */
	public $warnForSuperGlobals = array( '$_GET', '$_REQUEST' );

	/**
	 * Custom list of functions which verify nonces.
	 *
	 * @since 0.5.0
	 *
	 * @var array
	 */
	public $customNonceVerificationFunctions = array();

	/**
	 * List of the functions which verify nonces.
	 *
	 * @since 0.5.0
	 *
	 * @var array
	 */
	public static $nonceVerificationFunctions = array(
		'wp_verify_nonce',
		'check_admin_referer',
		'check_ajax_referer',
	);

	/**
	 * Whether the custom functions have been added to the default list yet.
	 *
	 * @since 0.5.0
	 *
	 * @var bool
	 */
	public static $addedCustomFunctions = false;

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register() {

		return array(
			T_VARIABLE,
		);
	}

	/**
	 * Processes this test, when one of its tokens is encountered.
	 *
	 * @param PHP_CodeSniffer_File $phpcsFile The file being scanned.
	 * @param int                  $stackPtr  The position of the current token
	 *                                        in the stack passed in $tokens.
	 *
	 * @return void
	 */
	public function process( PHP_CodeSniffer_File $phpcsFile, $stackPtr ) {

		// Merge any custom functions with the defaults, if we haven't already.
		if ( ! self::$addedCustomFunctions ) {
			self::$nonceVerificationFunctions = array_merge(
				self::$nonceVerificationFunctions
				, $this->customNonceVerificationFunctions
			);

			self::$addedCustomFunctions = true;
		}

		$this->init( $phpcsFile );

		$tokens = $phpcsFile->getTokens();
		$instance = $tokens[ $stackPtr ];

		$superglobals = array_merge(
			$this->errorForSuperGlobals
			, $this->warnForSuperGlobals
		);

		if ( ! in_array( $instance['content'], $superglobals ) ) {
			return;
		}

		if ( $this->has_whitelist_comment( 'CSRF', $stackPtr ) ) {
			return;
		}

		if ( $this->is_assignment( $stackPtr ) ) {
			return;
		}

		if ( $this->is_only_sanitized( $stackPtr ) ) {
			return;
		}

		if ( $this->has_nonce_check( $stackPtr ) ) {
			return;
		}

		// If we're still here, no nonce-verification function was found.
		$severity = ( in_array( $instance['content'], $this->errorForSuperGlobals ) ) ? 0 : 'warning';

		$phpcsFile->addError(
			'Processing form data without nonce verification.'
			, $stackPtr
			, 'NoNonceVerification'
			, array()
			, $severity
		);

	} // end process()

	/**
	 * Check if this token has an associated nonce check.
	 *
	 * @since 0.5.0
	 *
	 * @param int $stackPtr The position of the current token in the stack of tokens.
	 *
	 * @return bool
	 */
	protected function has_nonce_check( $stackPtr ) {

		/**
		 * @var array {
		 *      A cache of the scope that we last checked for nonce verification in.
		 *
		 *      @var string $file  The name of the file.
		 *      @var int    $start The index of the token where the scope started.
		 *      @var int    $end   The index of the token where the scope ended.
		 *      @var bool|int $nonce_check The index of the token where an nonce
		 *                         check was found, or false if none was found.
		 * }
		 */
		static $last;

		$start = 0;
		$end = $stackPtr;

		$tokens = $this->phpcsFile->getTokens();

		// If we're in a function, only look inside of it.
		$f = $this->phpcsFile->getCondition( $stackPtr, T_FUNCTION );
		if ( $f ) {
			$start = $tokens[ $f ]['scope_opener'];
		}

		$in_isset = $this->is_in_isset_or_empty( $stackPtr );

		// We allow for isset( $_POST['var'] ) checks to come before the nonce check.
		// If this is inside an isset(), check after it as well, all the way to the
		// end of the scope.
		if ( $in_isset ) {
			$end = ( 0 === $start ) ? count( $tokens ) : $tokens[ $start ]['scope_closer'];
		}

		// Check if we've looked here before.
		$filename = $this->phpcsFile->getFilename();

		if (
			$filename === $last['file']
			&& $start === $last['start']
		) {

			if ( false !== $last['nonce_check'] ) {
				// If we have already found an nonce check in this scope, we just
				// need to check whether it comes before this token. It is OK if the
				// check is after the token though, if this was only a isset() check.
				return ( $in_isset || $last['nonce_check'] < $stackPtr );
			} elseif ( $end <= $last['end'] ) {
				// If not, we can still go ahead and return false if we've already
				// checked to the end of the search area.
				return false;
			}

			// We haven't checked this far yet, but we can still save work by
			// skipping over the part we've already checked.
			$start = $last['end'];
		} else {
			$last = array(
				'file'  => $filename,
				'start' => $start,
				'end'   => $end,
			);
		}

		// Loop through the tokens looking for nonce verification functions.
		for ( $i = $start; $i < $end; $i++ ) {

			// If this isn't a function name, skip it.
			if ( T_STRING !== $tokens[ $i ]['code'] ) {
				continue;
			}

			// If this is one of the nonce verification functions, we can bail out.
			if ( in_array( $tokens[ $i ]['content'], self::$nonceVerificationFunctions ) ) {
				$last['nonce_check'] = $i;
				return true;
			}
		}

		// We're still here, so no luck.
		$last['nonce_check'] = false;

		return false;
	}

	/**
	 * Check if a token is inside of an isset() or empty() statement.
	 *
	 * @since 0.5.0
	 *
	 * @param int $stackPtr The index of the token in the stack.
	 *
	 * @return bool Whether the token is inside an isset() or empty() statement.
	 */
	protected function is_in_isset_or_empty( $stackPtr ) {

		if ( ! isset( $this->tokens[ $stackPtr ]['nested_parenthesis'] ) ) {
			return false;
		}

		end( $this->tokens[ $stackPtr ]['nested_parenthesis'] );
		$open_parenthesis = key( $this->tokens[ $stackPtr ]['nested_parenthesis'] );
		reset( $this->tokens[ $stackPtr ]['nested_parenthesis'] );

		return in_array( $this->tokens[ $open_parenthesis - 1 ]['code'], array( T_ISSET, T_EMPTY ) );
	}

	/**
	 * Check if something is only being sanitized.
	 *
	 * @since 0.5.0
	 *
	 * @param int $stackPtr The index of the token in the stack.
	 *
	 * @return bool Whether the token is only within a sanitization.
	 */
	protected function is_only_sanitized( $stackPtr ) {

		// If it isn't being sanitized at all.
		if ( ! $this->is_sanitized( $stackPtr ) ) {
			return false;
		}

		// If this isn't set, we know the value must have only been casted, because
		// is_sanitized() would have returned false otherwise.
		if ( ! isset( $this->tokens[ $stackPtr ]['nested_parenthesis'] ) ) {
			return true;
		}

		// At this point we're expecting the value to have not been casted. If it
		// was, it wasn't *only* casted, because it's also in a function.
		if ( $this->is_safe_casted( $stackPtr ) ) {
			return false;
		}

		// The only parentheses should belong to the sanitizing function. If there's
		// more than one set, this isn't *only* sanitization.
		return ( count( $this->tokens[ $stackPtr ]['nested_parenthesis'] ) === 1 );
	}

	/**
	 * Check if something is being casted to a safe value.
	 *
	 * @since 0.5.0
	 *
	 * @param int $stackPtr The index of the token in the stack.
	 *
	 * @return bool Whether the token being casted.
	 */
	protected function is_safe_casted( $stackPtr ) {

		// Get the last non-empty token.
		$prev = $this->phpcsFile->findPrevious(
			PHP_CodeSniffer_Tokens::$emptyTokens
			, $stackPtr - 1
			, null
			, true
		);

		// Check if it is a safe cast.
		return in_array(
			$this->tokens[ $prev ]['code']
			, array( T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST )
		);
	}

	/**
	 * Check if something is being sanitized.
	 *
	 * @since 0.5.0
	 *
	 * @param int $stackPtr The index of the token in the stack.
	 *
	 * @return bool Whether the token being sanitized.
	 */
	protected function is_sanitized( $stackPtr ) {

		// First we check if it is being casted to a safe value.
		if ( $this->is_safe_casted( $stackPtr ) ) {
			return true;
		}

		// If this isn't within a function call, we know already that it's not safe.
		if ( ! isset( $this->tokens[ $stackPtr ]['nested_parenthesis'] ) ) {
			return false;
		}

		// Get the function that it's in.
		end( $this->tokens[ $stackPtr ]['nested_parenthesis'] );
		$function_opener = $this->tokens[ $stackPtr ]['nested_parenthesis'];
		$functionPtr = key( $function_opener ) - 1;
		$function = $this->tokens[ $functionPtr ];

		// If it is just being unset, the value isn't used at all, so it's safe.
		if ( T_UNSET === $function['code'] ) {
			return true;
		}

		// If this isn't a call to a function, it sure isn't sanitizing function.
		if ( T_STRING !== $function['code'] ) {
			return false;
		}

		$functionName = $function['content'];

		// Arrays might be sanitized via array_map().
		if ( 'array_map' === $functionName ) {

			// Get the first parameter (name of function being used on the array).
			$mapped_function = $this->phpcsFile->findNext(
				PHP_CodeSniffer_Tokens::$emptyTokens
				, $function_opener + 1
				, $function_opener['parenthesis_closer']
				, true
			);

			// If we're able to resolve the function name, do so.
			if ( $mapped_function && T_CONSTANT_ENCAPSED_STRING === $this->tokens[ $mapped_function ]['code'] ) {
				$functionName = trim( $this->tokens[ $mapped_function ]['content'], '\'' );
			}
		}

		// Check if this is a sanitizing function.
		return in_array( $functionName, WordPress_Sniffs_XSS_EscapeOutputSniff::$sanitizingFunctions );
	}

} // end class
