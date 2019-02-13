<?php
/**
 * Stand-alone XMLSerializer for DOM3 documents
 *
 * The output is identical to standard XHTML5 DOM serialization, as given by
 * http://www.w3.org/TR/html-polyglot/
 * and
 * https://html.spec.whatwg.org/multipage/syntax.html#serialising-html-fragments
 * except that we may quote attributes with single quotes, *only* where that would
 * result in more compact output than the standard double-quoted serialization.
 * @module
 */

namespace Parsoid\Wt2Html;

use DOMAttr;
use DOMComment;
use DOMDocument;
use DOMDocumentFragment;
use DOMElement;
use DOMNode;
use DOMText;
use Parsoid\Utils\DOMUtils;
use Parsoid\Utils\WTUtils;
use Wikimedia\Assert\Assert;

/**
 * Stand-alone XMLSerializer for DOM3 documents.
 *
 * The output is identical to standard XHTML5 DOM serialization, as given by
 * http://www.w3.org/TR/html-polyglot/
 * and
 * https://html.spec.whatwg.org/multipage/syntax.html#serialising-html-fragments
 * except that we may quote attributes with single quotes, *only* where that would
 * result in more compact output than the standard double-quoted serialization.
 */
class XMLSerializer {

	/** HTML5 void elements */
	private static $emptyElements = [
		'area' => true,
		'base' => true,
		'basefont' => true,
		'bgsound' => true,
		'br' => true,
		'col' => true,
		'command' => true,
		'embed' => true,
		'frame' => true,
		'hr' => true,
		'img' => true,
		'input' => true,
		'keygen' => true,
		'link' => true,
		'meta' => true,
		'param' => true,
		'source' => true,
		'track' => true,
		'wbr' => true,
	];

	/** HTML5 elements with raw (unescaped) content */
	private static $hasRawContent = [
		'style' => true,
		'script' => true,
		'xmp' => true,
		'iframe' => true,
		'noembed' => true,
		'noframes' => true,
		'plaintext' => true,
		'noscript' => true
	];

	/**
	 * Elements that strip leading newlines
	 * http://www.whatwg.org/specs/web-apps/current-work/multipage/the-end.html#html-fragment-serialization-algorithm
	 * @namespace
	 * @private
	 */
	private static $newlineStrippingElements = [
		'pre' => true,
		'textarea' => true,
		'listing' => true
	];

	private static $entityEncodings = [
		'<' => '&lt;',
		'&' => '&amp;',
		'"' => '&quot;',
		"'" => '&apos;',
	];

	/**
	 * HTML entity encoder helper. Replaces calls to the entities npm module.
	 * Only supports the few entities we'll actually need: <&'"
	 * @param string $raw Input string
	 * @param string $whitelist String with the characters that should be encoded
	 * @return string
	 */
	private static function encodeHtmlEntities( $raw, $whitelist ) {
		$encodings = array_intersect_key( self::$entityEncodings, array_flip( str_split( $whitelist ) ) );
		return strtr( $raw, $encodings );
	}

	/**
	 * Serialize an HTML DOM3 node to XHTML. The XHTML and associated information will be fed
	 * step-by-step to the callback given in $accum.
	 * @param DOMNode $node
	 * @param array $options See {@link XMLSerializer::serialize()}
	 * @param callable $accum function( $bit, $node, $flag )
	 *   - $bit: (string) piece of HTML code
	 *   - $node: (DOMNode) ??
	 *   - $flag: (string|null) 'start' or 'end' (??)
	 * @return void
	 */
	private function serializeToString( DOMNode $node, array $options, callable $accum ) {
		$child = null;
		switch ( $node->nodeType ) {
			case XML_ELEMENT_NODE:
				/** @var DOMElement $node */
				$child = $node->firstChild;
				$attrs = $node->attributes;
				// DOMNamedNodeMap did not implement Countable until PHP 7.2
				$len = $attrs->length;
				$nodeName = $node->tagName;
				$localName = $node->localName;
				$accum( '<' . $localName, $node );
				for ( $i = 0;  $i < $len;  $i++ ) {
					/** @var DOMAttr $attr */
					$attr = $attrs->item( $i );

					if ( $options['smartQuote']
						// More double quotes than single quotes in value?
						&& substr_count( $attr->value, '"' ) > substr_count( $attr->value, "'" )
					) {
						// use single quotes
						$accum( ' ' . $attr->name . "='"
							. self::encodeHtmlEntities( $attr->value, "<&'" ) . "'",
							$node );
					} else {
						// use double quotes
						$accum( ' ' . $attr->name . '="'
							. self::encodeHtmlEntities( $attr->value, '<&"' ) . '"',
							$node );
					}
				}
				if ( $child || !isset( self::$emptyElements[ $nodeName ] ) ) {
					$accum( '>', $node, 'start' );
					// if is cdata child node
					if ( isset( self::$hasRawContent[ $nodeName ] ) ) {
						// TODO: perform context-sensitive escaping?
						// Currently this content is not normally part of our DOM, so
						// no problem. If it was, we'd probably have to do some
						// tag-specific escaping. Examples:
						// * < to \u003c in <script>
						// * < to \3c in <style>
						// ...
						if ( $child ) {
							$accum( $child->data, $node );
						}
					} else {
						if ( $child && isset( self::$newlineStrippingElements[ $localName ] )
							&& $child->nodeType === XML_TEXT_NODE && preg_match( '/^\n/', $child->data )
						) {
							/* If current node is a pre, textarea, or listing element,
							 * and the first child node of the element, if any, is a
							 * Text node whose character data has as its first
							 * character a U+000A LINE FEED (LF) character, then
							 * append a U+000A LINE FEED (LF) character. */
							$accum( "\n", $node );
						}
						while ( $child ) {
							self::serializeToString( $child, $options, $accum );
							$child = $child->nextSibling;
						}
					}
					$accum( '</' . $localName . '>', $node, 'end' );
				} else {
					$accum( '/>', $node, 'end' );
				}
				return;

			case XML_DOCUMENT_NODE:
			case XML_DOCUMENT_FRAG_NODE:
				/** @var DOMDocument|DOMDocumentFragment $node */
				$child = $node->firstChild;
				while ( $child ) {
					self::serializeToString( $child, $options, $accum );
					$child = $child->nextSibling;
				}
				return;

			case XML_TEXT_NODE:
				/** @var DOMText $node */
				$accum( self::encodeHtmlEntities( $node->data, '<&' ), $node );
				return;

			case XML_COMMENT_NODE:
				// According to
				// http://www.w3.org/TR/DOM-Parsing/#dfn-concept-serialize-xml
				// we could throw an exception here if node.data would not create
				// a "well-formed" XML comment.  But we use entity encoding when
				// we create the comment node to ensure that node.data will always
				// be okay; see DOMUtils.encodeComment().
				/** @var DOMComment $node */
				$accum( '<!--' . $node->data . '-->', $node );
				return;

			default:
				$accum( '??' . $node->nodeName, $node );
		}
	}

	/**
	 * Add data to an output/memory array (used when serialize() was called with the
	 * captureOffsets flag).
	 * @param array &$out Output array, see {@link self::serialize()} for details on the
	 *   'html' and 'offset' fields. The other fields (positions are 0-based):
	 *   - start: position in the HTML of the end of the opening tag of <body>
	 *   - last: (DOMNode) last "about sibling" of the currently processed element
	 *     (see {@link WTUtils::getAboutSiblings()}
	 *   - uid: the ID of the element
	 * @param string $bit A piece of the HTML string
	 * @param DOMNode $node The DOM node $bit is a part of
	 * @param string|null $flag 'start' when receiving the final part of the opening tag
	 *   of an element, 'end' when receiving the final part of the closing tag of an element
	 *   or the final part of a self-closing element.
	 */
	private static function accumOffsets(
		array &$out, $bit, DOMNode $node, $flag = null
	) {
		if ( DOMUtils::isBody( $node ) ) {
			$out['html'] .= $bit;
			if ( $flag === 'start' ) {
				$out['start'] = mb_strlen( $out['html'], 'UTF-8' );
			} elseif ( $flag === 'end' ) {
				$out['start'] = null;
				$out['uid'] = null;
			}
		} elseif ( !DOMUtils::isElt( $node ) || $out['start'] === null
			|| !DOMUtils::isBody( $node->parentNode )
		) {
			// In case you're wondering, out.start may never be set if body
			// isn't a child of the node passed to serializeToString, or if it
			// is the node itself but options.innerXML is true.
			$out['html'] .= $bit;
			if ( $out['uid'] !== null ) {
				$out['offsets'][$out['uid']]['html'][1] += mb_strlen( $bit, 'UTF-8' );
			}
		} else {
			/** @var DOMElement $node */
			$newUid = $node->getAttribute( 'id' );
			// Encapsulated siblings don't have generated ids (but may have an id),
			// so associate them with preceding content.
			if ( $newUid && $newUid !== $out['uid'] && !$out['last'] ) {
				if ( !WTUtils::isEncapsulationWrapper( $node ) ) {
					$out['uid'] = $newUid;
				} elseif ( WTUtils::isFirstEncapsulationWrapperNode( $node ) ) {
					$about = $node->getAttribute( 'about' );
					$aboutSiblings = WTUtils::getAboutSiblings( $node, $about );
					$out['last'] = end( $aboutSiblings );
					$out['uid'] = $newUid;
				}
			}
			if ( $out['last'] === $node && $flag === 'end' ) {
				$out['last'] = null;
			}
			Assert::invariant( $out['uid'] !== null, 'uid cannot be null' );
			if ( !isset( $out['offsets'][$out['uid']] ) ) {
				$dt = mb_strlen( $out['html'], 'UTF-8' ) - $out['start'];
				$out['offsets'][$out['uid']] = [ 'html' => [ $dt, $dt ] ];
			}
			$out['html'] .= $bit;
			$out['offsets'][$out['uid']]['html'][1] += mb_strlen( $bit, 'UTF-8' );
		}
	}

	/**
	 * Serialize an HTML DOM3 node to an XHTML string.
	 *
	 * @param DOMNode $node
	 * @param array $options
	 *   - smartQuote (bool, default true)
	 *   - innerXML (bool, default false)
	 *   - captureOffsets (bool, default false)
	 * @return array An array with the following data:
	 *   - html: the serialized HTML
	 *   - offsets: the start and end position of each element in the HTML, in a
	 *     [ $uid => [ 'html' => [ $start, $end ] ], ... ] format where $uid is the element's
	 *     Parsoid ID, $start is the 0-based index of the first character of the element and
	 *     $end is the index of the first character of the opening tag of the next sibling element,
	 *     or the index of the last character of the element's closing tag if there is no next
	 *     sibling. The positions are relative to the end of the opening <body> tag
	 *     (the DOCTYPE header is not counted), and only present when the captureOffsets flag is set.
	 */
	public static function serialize( DOMNode $node, array $options = [] ) : array {
		$options += [
			'smartQuote' => true,
			'innerXML' => false,
			'captureOffsets' => false,
		];
		if ( $node instanceof DOMDocument ) {
			$node = $node->documentElement;
		}
		$out = [ 'html' => '', 'offsets' => [], 'start' => null, 'uid' => null, 'last' => null ];
		$accum = $options['captureOffsets']
			? function ( $bit, $node, $flag = null ) use ( &$out ) {
				self::accumOffsets( $out, $bit, $node, $flag );
			}
			: function ( $bit ) use ( &$out ) {
				$out['html'] .= $bit;
			};

		if ( $options['innerXML'] ) {
			for ( $child = $node->firstChild; $child; $child = $child->nextSibling ) {
				self::serializeToString( $child, $options, $accum );
			}
		} else {
			self::serializeToString( $node, $options, $accum );
		}
		// Ensure there's a doctype for documents.
		if ( !$options['innerXML'] && $node->nodeName === 'html' ) {
			$out['html'] = "<!DOCTYPE html>\n" . $out['html'];
		}
		// Drop the bookkeeping
		unset( $out['start'], $out['uid'], $out['last'] );

		return $out;
	}

}
