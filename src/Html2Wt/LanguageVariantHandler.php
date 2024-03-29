<?php
declare( strict_types = 1 );

/**
 * Serializes language variant markup, like `-{ ... }-`.
 * @module
 */

namespace Parsoid\Html2Wt;

use Parsoid\Utils\DOMDataUtils;
use Parsoid\Config\WikitextConstants;
use Parsoid\Html2Wt\ConstrainedText\LanguageVariantText;
use DOMElement;
use Parsoid\Utils\Util;
use Wikimedia\Assert\Assert;

class LanguageVariantHandler {
	/**
	 * @param array $a
	 * @return array
	 */
	private static function expandSpArray( array $a ): array {
		$result = [];
		if ( is_array( $a ) ) {
			foreach ( $a as $el ) {
				if ( gettype( $el ) === 'integer' ) {
					for ( $i = 0;  $i < $el;  $i++ ) {
						$result[] = '';
					}
				} else {
					$result[] = $el;
				}
			}
		}
		return $result;
	}

	/**
	 * Helper function: serialize a DOM string
	 * @param SerializerState $state
	 * @param string|bool $t
	 * @param array|null $opts
	 * @return ConstrainedText\ConstrainedText|string
	 */
	private static function ser( SerializerState $state, string $t, ?array $opts ) {
		$options = array_merge( [
				'env' => $state->getEnv(),
				'onSOL' => false
			], $opts ?? []
		);
		return $state->serializer->serializeHTML( $options, $t );
	}

	/**
	 * Helper function: protect characters not allowed in language names
	 * @param string $l
	 * @return string
	 */
	private static function protectLang( string $l ): string {
		if ( preg_match( '/^[a-z][-a-z]+$/D', $l ) ) {
			return $l;
		}
		return '<nowiki>' . Util::escapeWtEntities( $l ) . '</nowiki>';
	}

	/**
	 * Helper function: combine the three parts of the -{ }- string
	 * @param string $flagStr
	 * @param string $bodyStr
	 * @param string|bool $useTrailingSemi
	 * @return string
	 */
	private static function combine( string $flagStr, string $bodyStr, $useTrailingSemi ): string {
		if ( !empty( $flagStr ) || preg_match( '/\|/', $bodyStr ) ) {
			$flagStr .= '|';
		}
		if ( $useTrailingSemi !== false ) {
			$bodyStr .= ';' . $useTrailingSemi;
		}

		return $flagStr . $bodyStr;
	}

	/**
	 * Canonicalize combinations of flags.
	 * $originalFlags should be [ 'flag' => <integer position>, ... ]
	 * @param array $originalFlags
	 * @param array $flSp
	 * @param array $flags
	 * @param bool $noFilter
	 * @param string|null $protectFunc
	 * @return string
	 */
	private static function sortedFlags(
		array $originalFlags, array $flSp, array $flags, bool $noFilter,
		?string $protectFunc
	): string {
		$filterInternal = function ( $f ) use ( $noFilter ) {
			// Filter out internal-use-only flags
			if ( $noFilter ) {
				return true;
			}
			return ( $f[0] ?? null ) !== '$';
		};
		$flags = array_filter( $flags, $filterInternal );

		$sortByOriginalPosition = function ( $a, $b ) use ( $originalFlags ) {
			$ai = $originalFlags[$a] ?? -1;
			$bi = $originalFlags[$b] ?? -1;
			return $ai - $bi;
		};
		usort( $flags, $sortByOriginalPosition );

		$insertOriginalWhitespace = function ( $f ) use ( $originalFlags, $protectFunc, $flSp ) {
			// Reinsert the original whitespace around the flag (if any)
			$i = $originalFlags[ $f ] ?? null;
			if ( !empty( $protectFunc ) ) {
				$p = call_user_func_array( [ 'self', $protectFunc ], [ $f ] );
			} else {
				$p = $f;
			}
			if ( $i !== null && ( 2 * $i + 1 ) < count( $flSp ) ) {
				return $flSp[ 2 * $i ] + $p + $flSp[ 2 * $i + 1 ];
			}
			return $p;
		};
		$flags = array_map( $insertOriginalWhitespace, $flags );
		$s = implode( ';', $flags );

		if ( 2 * count( $originalFlags ) + 1 === count( $flSp ) ) {
			if ( count( $flSp ) > 1 || strlen( $s ) ) {
				$s .= ';';
			}
			$s .= $flSp[ 2 * count( $originalFlags ) ];
		}
		return $s;
	}

	/**
	 * @param array $a
	 * @param $value
	 * @return bool
	 */
	private static function has( array $a, $value ): bool {
		return isset( $a[$value] );
	}

	/**
	 * @param array $a
	 * @param $value
	 */
	private static function add( array &$a, $value ) {
		if ( !isset( $a[$value] ) ) {
			$a[$value] = true;
		}
	}

	/**
	 * @param array $a
	 * @param $value
	 */
	private static function delete( array &$a, $value ) {
		$key = array_search( $value, array_keys( $a ) );
		if ( $key !== false ) {
			array_splice( $a, $key, 1 );
		}
	}

	/**
	 * @param $originalFlags
	 * @param $flags
	 * @param $f
	 */
	private static function maybeDeleteFlag( $originalFlags, &$flags, $f ) {
		if ( !isset( $originalFlags[$f] ) ) {
			self::delete( $flags, $f );
		}
	}

	/**
	 * LanguageVariantHandler
	 * @param SerializerState $state
	 * @param DOMElement $node
	 * @return void
	 */
	public static function handleLanguageVariant( SerializerState $state, DOMElement $node ): void {
		$dataMWV = DOMDataUtils::getJSONAttribute( $node, 'data-mw-variant', [] );
		$dp = DOMDataUtils::getDataParsoid( $node );
		$flSp = self::expandSpArray( $dp->flSp ?? [] );
		$textSp = self::expandSpArray( $dp->tSp ?? [] );
		$trailingSemi = false;
		$text = null;
		$flags = [];
		$originalFlags = [];
		if ( isset( $dp->fl ) ) {
			foreach ( $dp->fl as $key => $val ) {
				if ( !isset( $originalFlags[ $key ] ) ) {   // was $val
					$originalFlags[ $val ] = $key;
				}
			}
		}

		$result = '$E|'; // "error" flag

		// Backwards-compatibility: `bidir` => `twoway` ; `unidir` => `oneway`
		if ( isset( $dataMWV->bidir ) ) {
			$dataMWV->twoway = $dataMWV->bidir;
			unset( $dataMWV->bidir );
		}
		if ( isset( $dataMWV->unidir ) ) {
			$dataMWV->oneway = $dataMWV->undir;
			unset( $dataMWV->unidir );
		}

		foreach ( get_object_vars( $dataMWV ) as $key => $val ) {
			if ( isset( WikitextConstants::$LCNameMap[$key] ) ) {
				self::add( $flags, WikitextConstants::$LCNameMap[$key] );
			}
		}

		// Tweak flag set to account for implicitly-enabled flags.
		if ( $node->tagName !== 'meta' ) {
			self::add( $flags, '$S' );
		}
		if ( !self::has( $flags, '$S' ) && !self::has( $flags, 'T' ) && !isset( $dataMWV->filter ) ) {
			self::add( $flags, 'H' );
		}
		if ( count( $flags ) === 1 && self::has( $flags, '$S' ) ) {
			self::maybeDeleteFlag( $originalFlags, $flags, '$S' );
		} elseif ( self::has( $flags, 'D' ) ) {
			// Weird: Only way to hide a 'describe' rule is to write -{D;A|...}-
			if ( self::has( $flags, '$S' ) ) {
				if ( self::has( $flags, 'A' ) ) {
					self::add( $flags, 'H' );
				}
				self::delete( $flags, 'A' );
			} else {
				self::add( $flags, 'A' );
				self::delete( $flags, 'H' );
			}
		} elseif ( self::has( $flags, 'T' ) ) {
			if ( self::has( $flags, 'A' ) && !self::has( $flags, '$S' ) ) {
				self::delete( $flags, 'A' );
				self::add( $flags, 'H' );
			}
		} elseif ( self::has( $flags, 'A' ) ) {
			if ( self::has( $flags, '$S' ) ) {
				self::maybeDeleteFlag( $originalFlags, $flags, '$S' );
			} elseif ( self::has( $flags, 'H' ) ) {
				self::maybeDeleteFlag( $originalFlags, $flags, 'A' );
			}
		} elseif ( self::has( $flags, 'R' ) ) {
			self::maybeDeleteFlag( $originalFlags, $flags, '$S' );
		} elseif ( self::has( $flags, '-' ) ) {
			self::maybeDeleteFlag( $originalFlags, $flags, 'H' );
		}

		if ( isset( $dataMWV->filter ) && $dataMWV->filter->l ) {
			// "Restrict possible variants to a limited set"
			$text = self::ser( $state, $dataMWV->filter->t, [ 'protect' => '/\}-/' ] );
			Assert::invariant( count( $flags ) === 0, 'Error in language variant flags' );
			$result = self::combine(
				self::sortedFlags(
					$originalFlags, $flSp, $dataMWV->filter->l, true,
					'protectLang'
				),
				$text, false
			);
		} else { /* no trailing semi */
			if ( isset( $dataMWV->disabled ) || isset( $dataMWV->name ) ) {
				// "Raw" / protect contents from language converter
				$text = self::ser( $state, ( $dataMWV->disabled ?? $dataMWV->name )->t,
					[ 'protect' => '/\}-/' ] );
				if ( !preg_match( '/[:;|]/', $text ) ) {
					self::maybeDeleteFlag( $originalFlags, $flags, 'R' );
				}
				$result = self::combine(
					self::sortedFlags(
						$originalFlags, $flSp, array_keys( $flags ), false, null
					),
					$text, false
				);
			} elseif ( isset( $dataMWV->twoway ) ) {
				// Two-way rules (most common)
				if ( count( $textSp ) % 3 === 1 ) {
					$trailingSemi = $textSp[ count( $textSp ) - 1 ];
				}
				$b = isset( $dataMWV->twoway[ 0 ] ) && $dataMWV->twoway[ 0 ]->l === '*' ?
					array_slice( $dataMWV->twoway, 0, 1 ) :
					$dataMWV->twoway ?? false;
				$text = implode( ';',
					array_map( function ( $rule, $idx ) use ( $state, $textSp ) {
							$text = self::ser( $state, $rule->t, [ 'protect' => '/;|\}-/' ] );
							if ( $rule->l === '*' ) {
								$trailingSemi = false;
								return $text;
							}
							$length = ( 3 * ( $idx + 1 ) ) - ( 3 * $idx );
							$ws = ( 3 * $idx + 2 < count( $textSp ) ) ?
							array_slice( $textSp, 3 * $idx, $length ) :
								[ ( $idx > 0 ) ? ' ' : '', '', '' ];
							return $ws[ 0 ] . self::protectLang( $rule->l ) . $ws[ 1 ] . ':' . $ws[ 2 ] . $text;
					}, $b, range( 0, count( $b ) - 1 )
					)
				);
				// suppress output of default flag ('S')
				self::maybeDeleteFlag( $originalFlags, $flags, '$S' );
				$result = self::combine(
					self::sortedFlags(
						$originalFlags, $flSp, array_keys( $flags ), false, null
					),
					$text, $trailingSemi
				);
			} elseif ( isset( $dataMWV->oneway ) ) {
				// One-way rules (uncommon)
				if ( count( $textSp ) % 4 === 1 ) {
					$trailingSemi = $textSp[ count( $textSp ) - 1 ];
				}
				$text = implode( ';',
					array_map( function ( $rule, $idx ) use ( $state, $textSp ) {
							$from = self::ser( $state, $rule->f, [ 'protect' => '/:|;|=>|\}-/' ] );
							$to = self::ser( $state, $rule->t, [ 'protect' => '/;|\}-/' ] );
							$length = ( 4 * ( $idx + 1 ) ) - ( 4 * $idx );
							$ws = ( 4 * $idx + 3 < count( $textSp ) ) ?
								array_slice( $textSp, 4 * $idx, $length ) :
								[ '', '', '', '' ];
							return $ws[ 0 ] . $from . '=>' . $ws[ 1 ] . self::protectLang( $rule->l ) .
								$ws[ 2 ] . ':' . $ws[ 3 ] . $to;
					}, $dataMWV->oneway, range( 0, count( $dataMWV->oneway ) - 1 )
					)
				);
				$result = self::combine(
					self::sortedFlags(
						$originalFlags, $flSp, array_keys( $flags ), false, null
					),
					$text, $trailingSemi
				);
			}
		}
		$state->emitChunk( new LanguageVariantText( '-{' . $result . '}-', $node ), $node );
	}

}
