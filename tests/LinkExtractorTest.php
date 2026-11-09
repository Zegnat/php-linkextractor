<?php
/**
 * Testing if LinkExtractor extracts links.
 *
 * @author    Martijn van der Ven <martijn@vanderven.se>
 * @author    Christian Weiske <cweiske@cweiske.de>
 * @copyright 2017 Martijn van der Ven and authors
 * @license   BSD Zero Clause License
 * @version   0.3.0
 * @link      https://github.com/Zegnat/php-linkextractor
 */

declare(strict_types=1);

namespace Zegnat\LinkExtractor;

class LinkExtractorTest extends \PHPUnit\Framework\TestCase
{
    public function testConstructorRejectsUnsupportedRoot()
    {
        $this->expectException(\TypeError::class);
        new LinkExtractor('not a DOM node');
    }

    public function testConstructorRejectsNonXPath()
    {
        $this->expectException(\TypeError::class);
        new LinkExtractor(new \DOMDocument(), '', new \stdClass());
    }

    public function testConstructorRejectsXPathForAnotherDocument()
    {
        $this->expectException(\ValueError::class);
        new LinkExtractor(new \DOMDocument(), '', new \DOMXPath(new \DOMDocument()));
    }

    public function testConstructorUsesSuppliedXPath()
    {
        $dom = new \DOMDocument();
        $dom->loadHTMLFile(__DIR__ . '/files/example.com-index.html', LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        $extractor = new LinkExtractor($dom, 'http://example.com/index.html', $xpath);
        $this->assertSame($xpath, self::extractorXPath($extractor));
        $this->assertTrue($extractor->linksTo('http://www.iana.org/domains/example'));
    }

    public function testConstructorUsesSuppliedXPathForAnElementRoot()
    {
        $dom = new \DOMDocument();
        $dom->loadHTML('<div><a href="/in">in</a></div><a href="/out">out</a>', LIBXML_NOERROR | LIBXML_NOWARNING);
        $div = $dom->getElementsByTagName('div')->item(0);
        $extractor = new LinkExtractor($div, 'http://zegnat.example/', new \DOMXPath($dom));
        $this->assertSame(['http://zegnat.example/in'], $extractor->extract());
    }

    public function testConstructorUsesSuppliedXPathModern()
    {
        if (!class_exists('\\Dom\\HTMLDocument')) {
            $this->markTestSkipped('Modern \Dom\HTMLDocument not available.');
        }
        $dom = \Dom\HTMLDocument::createFromFile(__DIR__ . '/files/example.com-index.html', LIBXML_NOERROR);
        $xpath = new \Dom\XPath($dom);
        $extractor = new LinkExtractor($dom, 'http://example.com/index.html', $xpath);
        $this->assertSame($xpath, self::extractorXPath($extractor));
        $this->assertTrue($extractor->linksTo('http://www.iana.org/domains/example'));
    }

    public function testConstructorRejectsLegacyXPathForModernRoot()
    {
        if (!class_exists('\\Dom\\HTMLDocument')) {
            $this->markTestSkipped('Modern \Dom\HTMLDocument not available.');
        }
        $dom = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><title>t</title>', LIBXML_NOERROR);
        $this->expectException(\TypeError::class);
        new LinkExtractor($dom, '', new \DOMXPath(new \DOMDocument()));
    }

    /**
     * @return \DOMXPath|\Dom\XPath The processor the extractor ended up querying with.
     */
    private static function extractorXPath(LinkExtractor $extractor)
    {
        $property = new \ReflectionProperty(LinkExtractor::class, 'xpath');
        $property->setAccessible(true);
        return $property->getValue($extractor);
    }

    /**
     * @dataProvider filesProvider
     */
    public function testExtract($dom, $modernDom, $fileUrl, $expectedUrls)
    {
        $extractor = new LinkExtractor($dom, $fileUrl);
        $this->assertEquals($expectedUrls, $extractor->extract(), '\DOMDocument');
        if ($modernDom !== null) {
            $extractor = new LinkExtractor($modernDom, $fileUrl);
            $this->assertEquals($expectedUrls, $extractor->extract(), '\Dom\HTMLDocument');
        }
    }

    public function testLinksTo()
    {
        $dom = new \DOMDocument();
        $dom->loadHTMLFile(__DIR__ . '/files/example.com-index.html', LIBXML_NOERROR | LIBXML_NOWARNING);
        $extractor = new LinkExtractor($dom, 'http://example.com/index.html');
        $extractor->extract();
        $this->assertTrue($extractor->linksTo('http://www.iana.org/domains/example'));
        $this->assertFalse($extractor->linksTo('https://github.com/'));
        $this->assertFalse($extractor->linksTo(':'));
    }

    public function testLinksToModern()
    {
        if (!class_exists('\\Dom\\HTMLDocument')) {
            $this->markTestSkipped('Modern \Dom\HTMLDocument not available.');
        }
        $dom = \Dom\HTMLDocument::createFromFile(__DIR__ . '/files/example.com-index.html', LIBXML_NOERROR);
        $extractor = new LinkExtractor($dom, 'http://example.com/index.html');
        $extractor->extract();
        $this->assertTrue($extractor->linksTo('http://www.iana.org/domains/example'));
        $this->assertFalse($extractor->linksTo('https://github.com/'));
        $this->assertFalse($extractor->linksTo(':'));
    }

    public function testManifestIsNotAffectedByBaseWithoutDocumentUrl()
    {
        $dom = new \DOMDocument();
        $dom->loadHTMLFile(
            __DIR__ . '/files/example.com-manifest-with-base.html',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $extractor = new LinkExtractor($dom);
        $this->assertEquals(
            [
                'offline.appcache',
                'https://cdn.example.com/assets/',
                'https://cdn.example.com/assets/archives.html'
            ],
            $extractor->extract()
        );
    }

    /**
     * Resolve a reference against a base URL, through a document holding a single link.
     *
     * @param string $reference
     * @param string $expected
     * @param string $baseUrl
     *
     * @return void
     **/
    private function assertResolves(string $reference, string $expected, string $baseUrl)
    {
        $dom = new \DOMDocument();
        $dom->loadHTML(
            '<!doctype html><a href="' . \htmlspecialchars($reference, ENT_QUOTES) . '">link</a>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $extractor = new LinkExtractor($dom, $baseUrl);
        $this->assertSame([$expected], $extractor->extract(), "Resolving '{$reference}'.");
    }

    /**
     * @dataProvider rfc3986ReferenceProvider
     */
    public function testResolvesTheReferenceExamplesFromRfc3986($reference, $expected)
    {
        $this->assertResolves($reference, $expected, 'http://a/b/c/d;p?q');
    }

    /**
     * @dataProvider omittedReferenceProvider
     */
    public function testResolvesReferenceShapesTheExamplesOmit($reference, $expected)
    {
        $this->assertResolves($reference, $expected, 'http://a/b/c/d;p?q');
    }

    /**
     * A scheme trailed by at most five digits is read as a host and a port by parse_url(), which
     * leaves Guzzle reporting no scheme at all. Such a reference is absolute and the document it
     * was found in must play no part in it.
     *
     * @see https://bugs.php.net/bug.php?id=70588 The report, closed without a fix.
     **/
    public function testKeepsASchemeThatParseUrlReadsAsAPort()
    {
        $this->assertResolves('tel:12345', 'tel:12345', 'http://a/b/c/d;p?q');
    }

    /**
     * @dataProvider linkLocationProvider
     */
    public function testLinkLocationIsExercisedByAFixture($attribute, $element, $needsValue)
    {
        $covered = false;
        foreach (self::filesProvider() as $case) {
            $xpath = new \DOMXPath($case[0]);
            foreach ($xpath->query("//{$element}[@{$attribute}]") as $node) {
                if (!$needsValue || strlen(trim($node->getAttribute($attribute))) > 0) {
                    $covered = true;
                    break 2;
                }
            }
        }

        $this->assertTrue($covered, "No fixture exercises {$element}[@{$attribute}].");
    }

    /**
     * Every reference resolution example the URI specification lists, against its own base URL.
     *
     * The abnormal example “http:g” is given two results. This library is a strict parser, so it
     * keeps the reference as written rather than taking the backward compatible reading.
     *
     * @return array<string, array{0: string, 1: string}>
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986.html#section-5.4 Reference resolution examples.
     **/
    public static function rfc3986ReferenceProvider(): array
    {
        $examples = [
            // 5.4.1. Normal Examples
            ['g:h', 'g:h'],
            ['g', 'http://a/b/c/g'],
            ['./g', 'http://a/b/c/g'],
            ['g/', 'http://a/b/c/g/'],
            ['/g', 'http://a/g'],
            ['//g', 'http://g'],
            ['?y', 'http://a/b/c/d;p?y'],
            ['g?y', 'http://a/b/c/g?y'],
            ['#s', 'http://a/b/c/d;p?q#s'],
            ['g#s', 'http://a/b/c/g#s'],
            ['g?y#s', 'http://a/b/c/g?y#s'],
            [';x', 'http://a/b/c/;x'],
            ['g;x', 'http://a/b/c/g;x'],
            ['g;x?y#s', 'http://a/b/c/g;x?y#s'],
            ['', 'http://a/b/c/d;p?q'],
            ['.', 'http://a/b/c/'],
            ['./', 'http://a/b/c/'],
            ['..', 'http://a/b/'],
            ['../', 'http://a/b/'],
            ['../g', 'http://a/b/g'],
            ['../..', 'http://a/'],
            ['../../', 'http://a/'],
            ['../../g', 'http://a/g'],
            // 5.4.2. Abnormal Examples
            ['../../../g', 'http://a/g'],
            ['../../../../g', 'http://a/g'],
            ['/./g', 'http://a/g'],
            ['/../g', 'http://a/g'],
            ['g.', 'http://a/b/c/g.'],
            ['.g', 'http://a/b/c/.g'],
            ['g..', 'http://a/b/c/g..'],
            ['..g', 'http://a/b/c/..g'],
            ['./../g', 'http://a/b/g'],
            ['./g/.', 'http://a/b/c/g/'],
            ['g/./h', 'http://a/b/c/g/h'],
            ['g/../h', 'http://a/b/c/h'],
            ['g;x=1/./y', 'http://a/b/c/g;x=1/y'],
            ['g;x=1/../y', 'http://a/b/c/y'],
            ['g?y/./x', 'http://a/b/c/g?y/./x'],
            ['g?y/../x', 'http://a/b/c/g?y/../x'],
            ['g#s/./x', 'http://a/b/c/g#s/./x'],
            ['g#s/../x', 'http://a/b/c/g#s/../x'],
            ['http:g', 'http:g'],
        ];

        $cases = [];
        foreach ($examples as $example) {
            $cases['' === $example[0] ? 'the empty reference' : $example[0]] = $example;
        }
        return $cases;
    }

    /**
     * Reference shapes the specification defines in its grammar but leaves out of its examples.
     *
     * @return array<string, array{0: string, 1: string}>
     *
     * @see https://www.rfc-editor.org/rfc/rfc3986.html#section-3.1 A scheme may hold “+”, “-” and “.”.
     * @see https://www.rfc-editor.org/rfc/rfc3986.html#section-5.2.2 Dot segments go even with a scheme.
     * @see https://www.rfc-editor.org/rfc/rfc3986.html#section-3.3 Only the first segment may hold no colon.
     **/
    public static function omittedReferenceProvider(): array
    {
        return [
            'a scheme holding a plus' => ['coap+tcp://a/resource', 'coap+tcp://a/resource'],
            'dot segments under a scheme' => ['http://a/b/../c/./d', 'http://a/c/d'],
            'a colon in a later segment' => ['g/h:i', 'http://a/b/c/g/h:i'],
        ];
    }

    /**
     * @return array<int, array{0: \DOMDocument, 1: ?\Dom\HTMLDocument, 2: string, 3: string[]}>
     */
    public static function filesProvider(): array
    {
        static $data;
        if ($data !== null) {
            return $data;
        }

        $htmlfiles = glob(__DIR__ . '/files/*.html');
        natsort($htmlfiles);

        $data = [];
        foreach ($htmlfiles as $htmlfile) {
            $urlsfile = substr($htmlfile, 0, -5) . '.urls';
            $urlfile = substr($htmlfile, 0, -5) . '.url';
            $dom = new \DOMDocument();
            $dom->loadHTMLFile($htmlfile, LIBXML_NOERROR | LIBXML_NOWARNING);
            $modern = class_exists('\\Dom\\HTMLDocument')
                ? \Dom\HTMLDocument::createFromFile($htmlfile, LIBXML_NOERROR)
                : null;
            $data[] = [
                $dom,
                $modern,
                trim(file_get_contents($urlfile)),
                file($urlsfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            ];
        }
        return $data;
    }

    public static function linkLocationProvider(): array
    {
        $defaults = (new \ReflectionClass(LinkExtractor::class))->getDefaultProperties();
        $locations = [];
        $properties = [
            'urlAttributes' => false,
            'nonEmptyUrlAttributes' => true,
            'spaceSeparatedUrlAttributes' => true,
            'imageCandidateUrlAttributes' => true,
        ];
        foreach ($properties as $property => $needsValue) {
            foreach ($defaults[$property] as $attribute => $elements) {
                foreach ($elements as $element) {
                    $locations["{$element}[@{$attribute}]"] = [$attribute, $element, $needsValue];
                }
            }
        }
        return $locations;
    }
}
