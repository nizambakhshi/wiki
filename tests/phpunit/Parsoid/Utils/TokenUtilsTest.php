<?php

namespace Test\Parsoid\Utils;

use Parsoid\Tokens\KV;
use Parsoid\Tokens\KVSourceRange;
use Parsoid\Tokens\NlTk;
use Parsoid\Tokens\SelfclosingTagTk;
use Parsoid\Tokens\Token;
use Parsoid\Tokens\TagTk;
use Parsoid\Utils\TokenUtils;

/**
 * @coversDefaultClass Parsoid\Utils\TokenUtils
 */
class TokenUtilsTest extends \PHPUnit\Framework\TestCase {

	const TOKEN_TEST_DATA = [
		[
			'token' => 'string',
			'getTokenType' => 'string',
			'tokensToString' => 'string',
		],
		[
			'token' => [ 'type' => 'NlTk' ],
			'getTokenType' => 'NlTk',
			'tokenTrimTransparent' => true,
		],
		[
			'name' => '<div>',
			'token' => [
				'type' => 'TagTk',
				'name' => 'div',
				'attribs' => [],
			],
			'getTokenType' => 'TagTk',
			'isBlockTag' => true,
		],
		[
			'name' => '<p>',
			'token' => [
				'type' => 'TagTk',
				'name' => 'p',
				'attribs' => [],
			],
			'getTokenType' => 'TagTk',
			'isBlockTag' => true,
			'tagOpensBlockScope' => true,
		],
		[
			'name' => '<td>',
			'token' => [
				'type' => 'TagTk',
				'name' => 'td',
				'attribs' => [],
			],
			'getTokenType' => 'TagTk',
			'isBlockTag' => true,
			'tagClosesBlockScope' => true,
			'isTableTag' => true,
		],
		[
			'name' => 'template token',
			'token' => [
				'type' => 'SelfclosingTagTk',
				'name' => 'template',
				'attribs' => [],
			],
			'getTokenType' => 'SelfclosingTagTk',
			'isTemplateToken' => true,
		],
		[
			'name' => 'html tag token',
			'token' => [
				'type' => 'TagTk',
				'name' => 'div',
				'attribs' => [
					[ 'k' => 'role','v' => 'note' ],
					[ 'k' => 'class', 'v' => 'hatnote navigation-not-searchable' ],
				],
				'dataAttribs' => [
					'stx' => 'html',
				],
			],
			'getTokenType' => 'TagTk',
			'isBlockTag' => true,
			'isHTMLTag' => true,
		],
		[
			'name' => 'DOMFragment',
			'token' => [
				'type' => 'TagTk',
				'name' => 'span',
				'attribs' => [
					[ 'k' => 'data-parsoid', 'v' => '{}' ],
					[ 'k' => 'typeof', 'v' => 'mw:DOMFragment' ],
				],
				'dataAttribs' => [
					'tmp' => [ 'setDSR' => true ],
					'html' => 'mwf13',
					'tagWidths' => [ 8, 9 ]
				],
			],
			'getTokenType' => 'TagTk',
			'isDOMFragmentType' => true,
		],
		[
			'name' => 'SOL-transparent <link>',
			'token' => [
				'type' => 'SelfclosingTagTk',
				'name' => 'link',
				'attribs' => [
					[ 'k' => 'rel', 'v' => 'mw:PageProp/Category' ],
					[ 'k' => 'href', 'v' => './Category:Articles_with_short_description' ],
				],
				'dataAttribs' => [
					'stx' => 'simple',
					'a' => [
						'href' => './Category:Articles_with_short_description',
					],
					'sa' => [
						'href' => 'Category:articles with short description'
					],
				],
			],
			'getTokenType' => 'SelfclosingTagTk',
			'isSolTransparentLinkTag' => true,
		],
		[
			'name' => 'comment',
			'token' => [
				'type' => 'CommentTk',
				'value' => ' THIS IS A COMMENT ',
				'dataAttribs' => [
					'tsr' => [ 2104,2147 ],
				],
			],
			'getTokenType' => 'CommentTk',
		],
		[
			'name' => 'empty line meta token',
			'token' => [
				'type' => 'SelfclosingTagTk',
				'name' => 'meta',
				'attribs' => [
					[ 'k' => 'typeof', 'v' => 'mw:EmptyLine' ],
				],
				'dataAttribs' => [
					'tokens' => [
						[ 'type' => 'NlTk' ],
					],
				],
			],
			'getTokenType' => 'SelfclosingTagTk',
			'isEmptyLineMetaToken' => true,
		],
	];

	public function provideTokens() {
		foreach ( self::TOKEN_TEST_DATA as $k => $t ) {
			$t['name'] = $t['name'] ?? "Token Test #$k";
			$t['token'] = Token::getToken( $t['token'] );
			yield $t['name'] => [ $t ];
		}
	}

	/**
	 * @covers ::getTokenType
	 * @dataProvider provideTokens
	 */
	public function testGetTokenType( $testCase ) {
		$this->assertEquals(
			$testCase['getTokenType'] ?? 'unknown',
			TokenUtils::getTokenType( $testCase['token'] )
		);
	}

	/**
	 * @covers ::isBlockTag
	 * @dataProvider provideTokens
	 */
	public function testIsBlockTag( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isBlockTag'] ?? false,
			is_string( $token ) ? false :
			TokenUtils::isBlockTag( $token->getName() )
		);
	}

	/**
	 * @covers ::tagOpensBlockScope
	 * @dataProvider provideTokens
	 */
	public function testTagOpensBlockScope( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['tagOpensBlockScope'] ?? false,
			is_string( $token ) ? false :
			TokenUtils::tagOpensBlockScope( $token->getName() )
		);
	}

	/**
	 * @covers ::tagClosesBlockScope
	 * @dataProvider provideTokens
	 */
	public function testTagClosesBlockScope( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['tagClosesBlockScope'] ?? false,
			is_string( $token ) ? false :
			TokenUtils::tagClosesBlockScope( $token->getName() )
		);
	}

	/**
	 * @covers ::isTemplateToken
	 * @dataProvider provideTokens
	 */
	public function testIsTemplateToken( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isTemplateToken'] ?? false,
			TokenUtils::isTemplateToken( $token )
		);
	}

	/**
	 * @covers ::isHTMLTag
	 * @dataProvider provideTokens
	 */
	public function testIsHTMLTag( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isHTMLTag'] ?? false,
			TokenUtils::isHTMLTag( $token )
		);
	}

	/**
	 * @covers ::isDOMFragmentType
	 * @dataProvider provideTokens
	 */
	public function testIsDOMFragmentType( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isDOMFragmentType'] ?? false,
			( $token instanceof TagTk || $token instanceof SelfclosingTagTk ) ?
			TokenUtils::isDOMFragmentType(
				$token->getAttribute( 'typeof' ) ?? ''
			) : false
		);
	}

	/**
	 * @covers ::isTableTag
	 * @dataProvider provideTokens
	 */
	public function testIsTableTag( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isTableTag'] ?? false,
			TokenUtils::isTableTag( $token )
		);
	}

	/**
	 * @covers ::isSolTransparentLinkTag
	 * @dataProvider provideTokens
	 */
	public function testIsSolTransparentLinkTag( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isSolTransparentLinkTag'] ?? false,
			TokenUtils::isSolTransparentLinkTag( $token )
		);
	}

	/**
	 * @covers ::isEntitySpanToken
	 * @dataProvider provideTokens
	 */
	public function testIsEntitySpanToken( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isEntitySpanToken'] ?? false,
			TokenUtils::isEntitySpanToken( $token )
		);
	}

	/**
	 * @covers ::isEmptyLineMetaToken
	 * @dataProvider provideTokens
	 */
	public function testIsEmptyLineMetaToken( $testCase ) {
		$token = $testCase['token'];
		$this->assertEquals(
			$testCase['isEmptyLineMetaToken'] ?? false,
			TokenUtils::isEmptyLineMetaToken( $token )
		);
	}

	/**
	 * @covers ::tokensToString
	 * @dataProvider provideTokens
	 */
	public function testTokensToString( $testCase ) {
		$tokens = [ 'abc', $testCase['token'], 'def', new NlTk( null ) ];
		$this->assertEquals(
			'abc' . ( $testCase['tokensToString'] ?? '' ) . 'def',
			TokenUtils::tokensToString( $tokens )
		);
	}

	/**
	 * @covers ::kvToHash
	 * @covers ::tokenTrim
	 * @dataProvider provideTokens
	 */
	public function testKvToHash( $testCase ) {
		$k = [ '  key', $testCase['token'], 'ABC  ' ];
		$v = [ ' vaLUE', $testCase['token'], new NlTk( null ) ];
		$kExpect = 'key' . ( $testCase['tokensToString'] ?? '' ) . 'abc';
		$vExpect = 'vaLUE' . ( $testCase['tokensToString'] ?? '' );
		$srcOffsets = new KVSourceRange( 0, 10, 11, 20 );
		$expected = [];
		$expected[$kExpect] = $vExpect;
		$this->assertEquals(
			$expected,
			TokenUtils::kvToHash( [ new KV( $k, $v, $srcOffsets ) ], true )
		);
		// and now w/o converting to string (but still trimming whitespace)
		$expected[$kExpect] = [ 'vaLUE', $testCase['token'], '' ];
		if ( $testCase['tokenTrimTransparent'] ?? false ) {
			$expected[$kExpect][1] = '';
		}
		$this->assertEquals(
			$expected,
			TokenUtils::kvToHash( [ new KV( $k, $v, $srcOffsets ) ], false )
		);
	}

	/**
	 * @covers ::convertOffsets()
	 * @dataProvider provideConvertOffsets
	 */
	public function testConvertOffsets( $str, $from, $to, $input, $expect ) {
		$offsets = [];
		foreach ( $input as &$v ) {
			$offsets[] = &$v;
		}
		unset( $v );

		TokenUtils::convertOffsets( $str, $from, $to, $offsets );
		$this->assertSame( $expect, $offsets, "$from → $to" );
	}

	public static function provideConvertOffsets() {
		# Ensure that we have char from each UTF-8 class here.
		$str = "foo bár \u{1F4A9}\u{1F4A9} baz A\u{0531}\u{4EBA}\u{1F4A9}";
		$offsets = [
			# 0th offset must be zero, 1st should be length of string
			'byte' => [ 0, 32, 4, 13, 9, 18, 21, 22, 23, 25, 28 ],
			'char' => [ 0, 19, 4,  9, 8, 11, 14, 15, 16, 17, 18 ],
			'ucs2' => [ 0, 22, 4, 10, 8, 13, 16, 17, 18, 19, 20 ],
		];
		foreach ( $offsets as $from => $input ) {
			foreach ( $offsets as $to => $expect ) {
				yield "$from → $to" => [ $str, $from, $to, $input, $expect ];
			}
		}

		yield "Passing 0 offsets doesn't error" => [ $str, 'byte', 'char', [], [] ];

		yield "No error if we run out of offsets before EOS"
			=> [ $str, 'byte', 'char', [ 0, 9 ], [ 0, 8 ] ];

		foreach ( $offsets as $from => $input ) {
			foreach ( $offsets as $to => $expect ) {
				yield "Out of bounds offsets, $from → $to"
					=> [ $str, $from, $to, [ -10, 500 ], [ $expect[0], $expect[1] ] ];
			}
		}

		yield "Rounding bytes"
			=> [ "💩💩💩", 'byte', 'byte', [ 0, 1, 2, 3, 4, 5 ], [ 0, 4, 4, 4, 4, 8 ] ];
		yield "Rounding ucs2"
			=> [ "💩💩💩", 'ucs2', 'ucs2', [ 0, 1, 2, 3, 4 ], [ 0, 2, 2, 4, 4 ] ];
	}

}
