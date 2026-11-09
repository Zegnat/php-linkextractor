<?php
/**
 * The LinkExtractor tries to figure out what resources an HTML document links to.
 *
 * This tries to solve 2 questions:
 * 1. what constitutes a link between an HTML document and another resource, and
 * 2. how to supply them in a useful (resolved) format.
 *
 * @author    Martijn van der Ven <martijn@vanderven.se>
 * @copyright 2017 Martijn van der Ven
 * @license   BSD Zero Clause License
 * @version   0.3.0
 * @link      https://github.com/Zegnat/php-linkextractor
 * @see       https://github.com/w3c/webmention/issues/91 The discussion that prompted this.
 */

declare(strict_types=1);

namespace Zegnat\LinkExtractor;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;

/**
 * LinkExtractor class for finding all linked resources in an HTML document.
 */
class LinkExtractor
{
    /**
     * The HTML5 attributes with a URL value, and the elements they can be found on.
     *
     * @var array<string, list<string>> $urlAttributes
     * @see https://html.spec.whatwg.org/multipage/indices.html#attributes-3 Data source
     **/
    private $urlAttributes = [
        'cite' => ['blockquote', 'del', 'ins', 'q'],
        'href' => ['a', 'area', 'base'],
    ];
    /** @var array<string, list<string>> $nonEmptyUrlAttributes These treat empty values as invalid. */
    private $nonEmptyUrlAttributes = [
        'action' => ['form'],
        'data' => ['object'],
        'formaction' => ['button', 'input'],
        'href' => ['link'],
        'manifest' => ['html'],
        'poster' => ['video'],
        'src' => ['audio', 'embed', 'iframe', 'img', 'input', 'script', 'source', 'track', 'video'],
    ];
    /** @var array<string, list<string>> $spaceSeparatedUrlAttributes */
    private $spaceSeparatedUrlAttributes = [
        'ping' => ['a', 'area'],
    ];
    /**
     * @var array<string, list<string>> $imageCandidateUrlAttributes These hold image candidate strings, parsed by
     *     SrcsetParser.
     **/
    private $imageCandidateUrlAttributes = [
        'imagesrcset' => ['link'],
        'srcset' => ['img', 'source'],
    ];

    /**
     * Characters identified as ASCII whitespace by the Infra Standard.
     *
     * @var string $htmlSpaceCharacters
     * @see https://infra.spec.whatwg.org/#ascii-whitespace
     **/
    private $htmlSpaceCharacters = ' \t\n\f\r';

    /**
     * Pattern capturing the scheme, and everything that follows it, of an absolute URI reference.
     *
     * A relative-path reference can never hold a colon in its first path segment. A reference that opens with the
     * scheme production is therefore always a reference that carries its own scheme.
     *
     * @var string $schemePattern
     * @see https://www.rfc-editor.org/rfc/rfc3986.html#section-3.1 scheme = ALPHA *( ALPHA / DIGIT / "+" / "-" / "." )
     * @see https://www.rfc-editor.org/rfc/rfc3986.html#section-3.3 On the first segment of a relative-path reference.
     **/
    private $schemePattern = '@^([A-Za-z][A-Za-z0-9+.-]*):(.*)@s';

    /** @var \DOMNode|\Dom\Node $root */
    private $root;

    /** @var \DOMXPath|\Dom\XPath $xpath */
    private $xpath;

    /** @var Uri $documentUrl The document URL, without any BASE element applied to it. */
    private $documentUrl;

    /** @var UriInterface $baseUrl */
    private $baseUrl;

    /** @var null|list<string> $extracted */
    private $extracted = null;

    /**
     * Strip the leading and trailing whitespace per the Infra Standard.
     *
     * @param string $string
     *
     * @return string
     *
     * @see https://infra.spec.whatwg.org/#strip-leading-and-trailing-ascii-whitespace
     **/
    private function htmlStripWhitespace(string $string): string
    {
        return \preg_replace(\sprintf('@^[%1$s]*|[%1$s]*$@', $this->htmlSpaceCharacters), '', $string);
    }

    /**
     * Resolve a URL reference against a base URL.
     *
     * A reference that carries its own scheme is already absolute, so the base URL plays no part in it. It still
     * goes through Uri: section 5.2.2 removes the dot segments from its path, and Uri normalises the result the
     * way the rest of this library does. A reference that cannot be parsed as a URI comes back untouched.
     *
     * Uri leans on parse_url(), which recognises an authority where RFC 3986 allows none: it reads “a.com:80” as
     * a host and a port instead of a scheme and a path, hiding the scheme of a reference like “tel:12345”. PHP
     * declined to change that and shipped a compliant parser in 8.5 instead, out of reach of both PHP 7.0 and
     * Guzzle, so the scheme matched here takes precedence over the one Uri reports.
     *
     * @param string       $url
     * @param UriInterface $baseUrl The URL to resolve against.
     *
     * @return string
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986.html#section-5.2.2 The transform references algorithm.
     * @see https://bugs.php.net/bug.php?id=70588 parse_url() reading “tel:12345” as a host and a port.
     * @see https://wiki.php.net/rfc/url_parsing_api The compliant parser PHP 8.5 added instead.
     **/
    private function resolveUrl(string $url, UriInterface $baseUrl): string
    {
        try {
            if (\preg_match($this->schemePattern, $url, $reference) !== 1) {
                return \strval(UriResolver::resolve($baseUrl, new Uri($url)));
            }
            $uri = new Uri($url);
            if ($uri->getScheme() === '') {
                // Uri found no scheme, but the pattern did: rebuild the URI from the matched scheme.
                $uri = (new Uri($reference[2]))->withScheme($reference[1]);
            }
            return \strval($uri->withPath(UriResolver::removeDotSegments($uri->getPath())));
        } catch (\InvalidArgumentException $e) {
            return $url;
        }
    }

    /**
     * Pick the XPath processor to query the document with.
     *
     * @param \DOMDocument|\Dom\Document $ownerDocument
     * @param null|\DOMXPath|\Dom\XPath  $xpath
     * @param bool                       $modernParser
     *
     * @return \DOMXPath|\Dom\XPath
     **/
    private function resolveXPath($ownerDocument, $xpath, bool $modernParser)
    {
        $expected = $modernParser ? '\\Dom\\XPath' : '\\DOMXPath';
        if ($xpath === null) {
            return new $expected($ownerDocument);
        }
        if (!$xpath instanceof $expected) {
            throw new \TypeError(\sprintf('$xpath must be a %s.', $expected));
        }
        if ($xpath->document !== $ownerDocument) {
            throw new \ValueError('$xpath must have been created for the document $root belongs to.');
        }
        return $xpath;
    }

    /**
     * Construct the extractor.
     *
     * The extractor can run on the DOM for the entire document, but can also be limited to only look for links within
     * a sub element. This can be useful if you only want to get the links from within a previously determined context,
     * like an article.
     *
     * Even if a sub element of the document is supplied, it will still apply any BASE elements from th entire document
     * for the purpose of resolving relative URLs.
     *
     * @param \DOMNode|\Dom\Node        $root    Any node, including a whole document, to use as root.
     * @param string                    $baseUrl The URL for the document, to resolve relative URLs against.
     * @param null|\DOMXPath|\Dom\XPath $xpath   The processor to query the document with, NULL to create one.
     *
     * @throws \TypeError If $root is neither a \DOMNode nor a \Dom\Node.
     * @throws \TypeError If $xpath is not of the XPath class matching the DOM API of $root.
     * @throws \ValueError If $xpath was not created for the document $root belongs to.
     * @throws \InvalidArgumentException If $baseUrl could not be parsed as a valid URI.
     * @throws \InvalidArgumentException If the BASE element’s HREF could not be parsed as a valid URI.
     *
     * @api
     **/
    public function __construct($root, string $baseUrl = '', $xpath = null)
    {
        $modernParser = \class_exists('\\Dom\\Node') && $root instanceof \Dom\Node;
        if (!$root instanceof \DOMNode && !$modernParser) {
            throw new \TypeError('$root must be a \\DOMNode or a \\Dom\\Node.');
        }
        $this->root = $root;
        // Only a document has no owner document, so this is always one.
        /** @var \DOMDocument|\Dom\Document $ownerDocument */
        $ownerDocument = $root->ownerDocument ?? $root;
        $this->xpath = $this->resolveXPath($ownerDocument, $xpath, $modernParser);
        $this->documentUrl = new Uri($baseUrl);
        $baseUrl = $this->documentUrl;

        // Update the base URL in case a BASE element was provided.
        // We are not going to care about the validity of the location of the BASE element.
        // Match on local-name() because the modern parser adds a namespace.
        /** @var \DOMNodeList<\DOMElement>|\Dom\NodeList<\Dom\Element>|false $base */
        $base = $this->xpath->query("//*[local-name()='base'][@href]", $root);
        if ($base !== false && $base->length > 0) {
            $baseElementUrl = new Uri($this->htmlStripWhitespace($base->item(0)->getAttribute('href')));
            $baseUrl = UriResolver::resolve($baseUrl, $baseElementUrl);
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * Get all URLs from the document. These will all have been resolved to the base URL of the document, when possible.
     *
     * @return list<string> An array of all URLs found in the document.
     *
     * @api
     **/
    public function extract(): array
    {
        if (\is_array($this->extracted)) {
            return $this->extracted;
        }
        $xpath = \substr(
            \array_reduce(
                \array_unique(\array_merge(
                    \array_keys($this->urlAttributes),
                    \array_keys($this->nonEmptyUrlAttributes),
                    \array_keys($this->spaceSeparatedUrlAttributes),
                    \array_keys($this->imageCandidateUrlAttributes)
                )),
                function (string $xpath, string $attribute): string {
                    return $xpath . ' | .//@' . $attribute;
                },
                ''
            ),
            3
        );
        /** @var \DOMNodeList<\DOMAttr>|\Dom\NodeList<\Dom\Attr>|false $urlAttributes */
        $urlAttributes = $this->xpath->query($xpath, $this->root);
        $links = [];
        foreach ($urlAttributes as $urlAttribute) {
            $name = $urlAttribute->name;
            $url = $this->htmlStripWhitespace($urlAttribute->value);
            $element = \strtolower($urlAttribute->ownerElement->tagName);
            // The parser resolves MANIFEST before any BASE element has been seen, so BASE can never affect it.
            $baseUrl = ($name === 'manifest' && $element === 'html') ? $this->documentUrl : $this->baseUrl;
            if (
                \array_key_exists($name, $this->urlAttributes)
                && \in_array($element, $this->urlAttributes[$name])
            ) {
                $links[] = $this->resolveUrl($url, $baseUrl);
            } elseif (
                \array_key_exists($name, $this->nonEmptyUrlAttributes)
                && \in_array($element, $this->nonEmptyUrlAttributes[$name])
                && \strlen($url) > 0
            ) {
                $links[] = $this->resolveUrl($url, $baseUrl);
            } elseif (
                \array_key_exists($name, $this->spaceSeparatedUrlAttributes)
                && \in_array($element, $this->spaceSeparatedUrlAttributes[$name])
            ) {
                foreach (\preg_split('@[' . $this->htmlSpaceCharacters . ']+@', $url, -1, PREG_SPLIT_NO_EMPTY) as $singleUrl) {
                    $links[] = $this->resolveUrl($singleUrl, $baseUrl);
                }
            } elseif (
                \array_key_exists($name, $this->imageCandidateUrlAttributes)
                && \in_array($element, $this->imageCandidateUrlAttributes[$name])
            ) {
                foreach (SrcsetParser::parse($url) as $candidate) {
                    $links[] = $this->resolveUrl($candidate['url'], $baseUrl);
                }
            }
        }
        $this->extracted = $links;
        return $this->extracted;
    }

    /**
     * Check if the document links to a specified resource.
     *
     * @param string $url The URL of a resource.
     *
     * @return bool True if the document links to the specified resource.
     *
     * @api
     **/
    public function linksTo(string $url): bool
    {
        return \in_array($this->resolveUrl($url, $this->baseUrl), $this->extract());
    }
}
