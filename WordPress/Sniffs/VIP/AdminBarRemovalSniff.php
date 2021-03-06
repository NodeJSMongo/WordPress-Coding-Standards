<?php
/**
 * WordPress Coding Standard.
 *
 * @package WPCS\WordPressCodingStandards
 * @link    https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards
 * @license https://opensource.org/licenses/MIT MIT
 */

/**
 * Discourages removal of the admin bar.
 *
 * @link    https://vip.wordpress.com/documentation/vip/code-review-what-we-look-for/#removing-the-admin-bar
 *
 * @package WPCS\WordPressCodingStandards
 *
 * @since   0.3.0
 * @since   0.11.0 Extends the WordPress_Sniff class.
 */
class WordPress_Sniffs_VIP_AdminBarRemovalSniff extends WordPress_Sniff {

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register() {
		return array(
			T_STRING,
			T_CONSTANT_ENCAPSED_STRING,
			T_DOUBLE_QUOTED_STRING,
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
		$tokens = $phpcsFile->getTokens();

		if ( in_array( $this->strip_quotes( $tokens[ $stackPtr ]['content'] ), array( 'show_admin_bar' ), true ) ) {
			$phpcsFile->addError( 'Removal of admin bar is prohibited.', $stackPtr, 'RemovalDetected' );
		}
	} // End process().

} // End class.
