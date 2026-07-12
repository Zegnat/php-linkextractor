<?php
/**
 * Testing if LinkExtractor extracts links.
 *
 * @author    Martijn van der Ven <martijn@vanderven.se>
 * @author    Christian Weiske <cweiske@cweiske.de>
 * @copyright 2017 Martijn van der Ven and authors
 * @license   BSD Zero Clause License
 * @version   0.1.0
 * @link      https://github.com/Zegnat/php-linkextractor
 */

declare(strict_types=1);

namespace Zegnat\LinkExtractor;

class LinkExtractorTest extends \PHPUnit\Framework\TestCase
{
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
        foreach (['urlAttributes' => false, 'nonEmptyUrlAttributes' => true, 'spaceSeparatedUrlAttributes' => true] as $property => $needsValue) {
            foreach ($defaults[$property] as $attribute => $elements) {
                foreach ($elements as $element) {
                    $locations["{$element}[@{$attribute}]"] = [$attribute, $element, $needsValue];
                }
            }
        }
        return $locations;
    }
}
