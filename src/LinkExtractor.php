<?php
/**
 * The LinkExtractor tries to figure out what resources an HTML document links to.
 *
 * This tries to solve 2 questions:
 * 1. what constitutes a link between an HTML document and another resource, and
 * 2. how to supply them in a useful (resolved) format.
 *
 * PHP version 7
 *
 * @author    Martijn van der Ven <martijn@vanderven.se>
 * @copyright 2017 Martijn van der Ven
 * @license   BSD Zero Clause License
 * @version   0.1.0
 * @link      https://github.com/Zegnat/php-linkextractor
 * @see       https://github.com/w3c/webmention/issues/91 The discussion that prompted this.
 */

declare(strict_types=1);

namespace Zegnat\LinkExtractor;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

/**
 * LinkExtractor class for finding all linked resources in an HTML document.
 */
class LinkExtractor
{
    /**
     * The HTML5 attributes with a URL value, and the elements they can be found on.
     *
     * @var array $urlAttributes
     * @var array $nonEmptyUrlAttributes These treat empty values as invalid.
     * @see https://html.spec.whatwg.org/multipage/indices.html#attributes-3 Data source
     **/
    private $urlAttributes = [
        'cite' => ['blockquote', 'del', 'ins', 'q'],
        'href' => ['a', 'area', 'base'],
    ];
    private $nonEmptyUrlAttributes = [
        'action' => ['form'],
        'data' => ['object'],
        'formaction' => ['button', 'input'],
        'href' => ['link'],
        'manifest' => ['html'],
        'poster' => ['video'],
        'src' => ['audio', 'embed', 'iframe', 'img', 'input', 'script', 'source', 'track', 'video'],
    ];
    private $spaceSeparatedUrlAttributes = [
        'ping' => ['a', 'area'],
    ];

    /**
     * Characters identified as space characters per the HTML5 spec.
     *
     * @var string $htmlSpaceCharacters
     * @see https://www.w3.org/TR/html5/infrastructure.html#space-character
     **/
    private $htmlSpaceCharacters = ' \t\n\f\r';

    /** @var DOMNode $root */
    private $root;

    /** @var XPath $root */
    private $xpath;

    /** @var Uri $baseUrl */
    private $baseUrl;

    /** @var null|array $extracted */
    private $extracted = null;

    /**
     * Strip the leading and trailing whitespace per the HTML5 spec.
     *
     * @param string $string
     *
     * @return string
     *
     * @see https://www.w3.org/TR/html5/infrastructure.html#strip-leading-and-trailing-whitespace
     **/
    private function htmlStripWhitespace(string $string): string
    {
        return \preg_replace(\sprintf('@^[%1$s]*|[%1$s]*$@', $this->htmlSpaceCharacters), '', $string);
    }

    /**
     * Resolve a relative URL to the current base URL.
     *
     * @param string $url
     *
     * @return string
     **/
    private function resolveUrl(string $url): string
    {
        try {
            return \strval(UriResolver::resolve($this->baseUrl, new Uri($url)));
        } catch (\InvalidArgumentException $e) {
            return $url;
        }
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
     * @param \DOMNode $root    Any DOMNode, including the entire DOMDocument, to use as root.
     * @param string   $baseUrl The URL for the document, to resolve relative URLs against.
     *
     * @throws \InvalidArgumentException If the BASE element’s HREF could not be parsed as a valid URI.
     *
     * @api
     **/
    public function __construct(\DOMNode $root, string $baseUrl = '')
    {
        $this->root = $root;
        $ownerDocument = $root instanceof \DOMDocument ? $root : $root->ownerDocument;
        $this->xpath = new \DOMXPath($ownerDocument);
        $baseUrl = new Uri($baseUrl);

        // Update the base URL in case a BASE element was provided.
        // We are not going to care about the validity of the location of the BASE element.
        $base = $this->xpath->query('//base[@href]', $root);
        if ($base !== false && $base->length > 0) {
            $baseElementUrl = new Uri($this->htmlStripWhitespace($base->item(0)->getAttribute('href')));
            $baseUrl = UriResolver::resolve($baseUrl, $baseElementUrl);
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * Get all URLs from the document. These will all have been resolved to the base URL of the document, when possible.
     *
     * @return array An array of all URLs found in the document.
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
                    \array_keys($this->spaceSeparatedUrlAttributes)
                )),
                function (string $xpath, string $attribute): string {
                    return $xpath . ' | .//@' . $attribute;
                },
                ''
            ),
            3
        );
        $urlAttributes = $this->xpath->query($xpath, $this->root);
        $links = [];
        foreach ($urlAttributes as $urlAttribute) {
            $name = $urlAttribute->name;
            $url = $this->htmlStripWhitespace($urlAttribute->value);
            $element = $urlAttribute->parentNode->tagName;
            if (
                \array_key_exists($name, $this->urlAttributes)
                && \in_array($element, $this->urlAttributes[$name])
            ) {
                $links[] = $url;
            } elseif (
                \array_key_exists($name, $this->nonEmptyUrlAttributes)
                && \in_array($element, $this->nonEmptyUrlAttributes[$name])
                && \strlen($url) > 0
            ) {
                $links[] = $url;
            } elseif (
                \array_key_exists($name, $this->spaceSeparatedUrlAttributes)
                && \in_array($element, $this->spaceSeparatedUrlAttributes[$name])
            ) {
                foreach (\preg_split('@[' . $this->htmlSpaceCharacters . ']+@', $url, -1, PREG_SPLIT_NO_EMPTY) as $singleUrl) {
                    $links[] = $singleUrl;
                }
            }
        }
        $this->extracted = \array_map(
            [$this, 'resolveUrl'],
            $links
        );
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
        return \in_array($this->resolveUrl($url), $this->extract());
    }
}
