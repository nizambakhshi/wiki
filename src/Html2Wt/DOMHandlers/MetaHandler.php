<?php
declare( strict_types = 1 );

namespace Parsoid\Html2Wt\DOMHandlers;

use DOMElement;
use DOMNode;
use Parsoid\Html2Wt\SerializerState;
use Parsoid\Utils\DOMDataUtils;
use Parsoid\Utils\PHPUtils;
use Parsoid\Utils\Util;
use Parsoid\Utils\WTUtils;

class MetaHandler extends DOMHandler {

	public function __construct() {
		parent::__construct( false );
	}

	/** @inheritDoc */
	public function handle(
		DOMElement $node, SerializerState $state, bool $wrapperUnmodified = false
	): ?DOMNode {
		$type = $node->getAttribute( 'typeof' ) ?: '';
		$property = $node->getAttribute( 'property' ) ?: '';
		$dp = DOMDataUtils::getDataParsoid( $node );
		$dmw = DOMDataUtils::getDataMw( $node );

		if ( isset( $dp->src ) && preg_match( '#(^|\s)mw:Placeholder(/\w*)?$#D', $type ) ) {
			$this->emitPlaceholderSrc( $node, $state );
			return $node->nextSibling;
		}

		// Check for property before type so that page properties with
		// templated attrs roundtrip properly.
		// Ex: {{DEFAULTSORT:{{echo|foo}} }}
		if ( $property ) {
			preg_match( '#^mw\:PageProp/(.*)$#D', $property, $switchType );
			if ( $switchType ) {
				$out = $switchType[1];
				$cat = preg_match( '/^(?:category)?(.*)/', $out, $catMatch );
				if ( $cat && isset( Util::magicMasqs()[$catMatch[1]] ) ) {
					$contentInfo = $state->serializer->serializedAttrVal( $node, 'content' );
					if ( WTUtils::hasExpandedAttrsType( $node ) ) {
						$out = '{{' . $contentInfo['value'] . '}}';
					} elseif ( isset( $dp->src ) ) {
						// Don't try to squish the 2 lines into one because of
						// backpattern ambiguities if $contentInfo['value'] happens
						// to be a "123" or "$1", for example.
						// See https://www.php.net/manual/en/function.preg-replace.php
						$out = preg_replace( '/^([^:]+:)(.*)$/D', "$1", $dp->src, 1 );
						$out .= $contentInfo['value'] . '}}';
					} else {
						$magicWord = mb_strtoupper( $catMatch[1] );
						$state->getEnv()->log( 'warn', $catMatch[1]
							. " is missing source. Rendering as $magicWord magicword" );
						$out = '{{' . $magicWord . ':' . $contentInfo['value'] . '}}';
					}
				} else {
					$out = $state->getEnv()->getSiteConfig()->getMagicWordWT(
						$switchType[1], $dp->magicSrc ?? '' );
				}
				$state->emitChunk( $out, $node );
			} else {
				( new FallbackHTMLHandler )->handle( $node, $state );
			}
		} elseif ( $type ) {
			switch ( $type ) {
				case 'mw:Includes/IncludeOnly':
					// Remove the dp.src when older revisions of HTML expire in RESTBase
					$state->emitChunk( PHPUtils::coalesce( $dmw->src ?? null, $dp->src ?? null,  '' ), $node );
					break;
				case 'mw:Includes/IncludeOnly/End':
					// Just ignore.
					break;
				case 'mw:Includes/NoInclude':
					$state->emitChunk( PHPUtils::coalesce( $dp->src ?? null, '<noinclude>' ), $node );
					break;
				case 'mw:Includes/NoInclude/End':
					$state->emitChunk( PHPUtils::coalesce( $dp->src ?? null, '</noinclude>' ), $node );
					break;
				case 'mw:Includes/OnlyInclude':
					$state->emitChunk( PHPUtils::coalesce( $dp->src ?? null, '<onlyinclude>' ), $node );
					break;
				case 'mw:Includes/OnlyInclude/End':
					$state->emitChunk( PHPUtils::coalesce( $dp->src ?? null, '</onlyinclude>' ), $node );
					break;
				case 'mw:DiffMarker/inserted':
				case 'mw:DiffMarker/deleted':
				case 'mw:DiffMarker/moved':
				case 'mw:Separator':
					// just ignore it
					break;
				default:
					( new FallbackHTMLHandler )->handle( $node, $state );
			}
		} else {
			( new FallbackHTMLHandler )->handle( $node, $state );
		}
		return $node->nextSibling;
	}

	/** @inheritDoc */
	public function before( DOMElement $node, DOMNode $otherNode, SerializerState $state ): array {
		$type = $node->getAttribute( 'typeof' ) ?: $node->getAttribute( 'property' ) ?:	null;
		if ( $type && preg_match( '#mw:PageProp/categorydefaultsort#', $type ) ) {
			if ( $otherNode->nodeName === 'p'
				&& $otherNode instanceof DOMElement // for static analyizers
				&& ( DOMDataUtils::getDataParsoid( $otherNode )->stx ?? null ) !== 'html'
			) {
				// Since defaultsort is outside the p-tag, we need 2 newlines
				// to ensure that it go back into the p-tag when parsed.
				return [ 'min' => 2 ];
			} else {
				return [ 'min' => 1 ];
			}
		} elseif ( WTUtils::isNewElt( $node )
			// Placeholder metas don't need to be serialized on their own line
			&& ( $node->nodeName !== 'meta'
				|| !preg_match( '#(^|\s)mw:Placeholder(/|$)#D', $node->getAttribute( 'typeof' ) ?: '' ) )
		) {
			return [ 'min' => 1 ];
		} else {
			return [];
		}
	}

	/** @inheritDoc */
	public function after( DOMElement $node, DOMNode $otherNode, SerializerState $state ): array {
		// No diffs
		if ( WTUtils::isNewElt( $node )
			// Placeholder metas don't need to be serialized on their own line
			&& ( $node->nodeName !== 'meta'
				|| !preg_match( '#(^|\s)mw:Placeholder(/|$)#D', $node->getAttribute( 'typeof' ) ?: '' ) )
		) {
			return [ 'min' => 1 ];
		} else {
			return [];
		}
	}

}
