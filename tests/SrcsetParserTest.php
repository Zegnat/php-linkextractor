<?php
/**
 * Testing if SrcsetParser parses image candidate strings.
 *
 * @author    Martijn van der Ven <martijn@vanderven.se>
 * @copyright 2026 Martijn van der Ven and authors
 * @license   BSD Zero Clause License
 * @version   0.3.0
 * @link      https://github.com/Zegnat/php-linkextractor
 */

declare(strict_types=1);

namespace Zegnat\LinkExtractor;

class SrcsetParserTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Text to put in place of the character references the HTML parser cannot be trusted with.
     *
     * @var string $hiddenReference
     **/
    private static $hiddenReference = 'and-hash-ex';

    /**
     * @dataProvider srcsetProvider
     */
    public function testParse($attribute, $expectedCandidates)
    {
        $this->assertSame($expectedCandidates, SrcsetParser::parse($attribute));
    }

    /**
     * Check one image candidate string from the web-platform-tests suite.
     *
     * That suite asserts which candidate a browser selected, which needs the image selection
     * algorithm this library has no business implementing. Two things it still settles for us:
     * a selected URL has to be among the candidates, and a value a browser shows nothing for
     * cannot leave a candidate behind.
     *
     * @dataProvider webPlatformTestsProvider
     */
    public function testParseAgreesWithWebPlatformTests($attribute, $selectedUrl)
    {
        $urls = \array_column(SrcsetParser::parse($attribute), 'url');
        if ($selectedUrl === '') {
            $this->assertSame([], $urls);
        } else {
            $this->assertContains($selectedUrl, $urls);
        }
    }

    /**
     * Build the image candidate that SrcsetParser is expected to return.
     */
    private static function image(string $url, $width = null, $density = null): array
    {
        return ['url' => $url, 'width' => $width, 'density' => $density];
    }

    /**
     * Turn a code point into UTF-8.
     *
     * The suite references nothing above U+FEFF, so three bytes are always enough.
     *
     * @param int $code
     *
     * @return string
     **/
    private static function utf8Character(int $code): string
    {
        if ($code < 0x80) {
            return \chr($code);
        }
        if ($code < 0x800) {
            return \chr(0xC0 | $code >> 6) . \chr(0x80 | $code & 0x3F);
        }
        return \chr(0xE0 | $code >> 12) . \chr(0x80 | ($code >> 6) & 0x3F) . \chr(0x80 | $code & 0x3F);
    }

    /**
     * Put back the character references that were kept away from the HTML parser.
     *
     * @param string $value
     *
     * @return string
     **/
    private static function resolveHiddenReferences(string $value): string
    {
        return \preg_replace_callback(
            '@' . self::$hiddenReference . '([0-9a-fA-F]+);@',
            function (array $match): string {
                return self::utf8Character(\intval(\hexdec($match[1])));
            },
            $value
        );
    }

    /**
     * Every image candidate string in the vendored web-platform-tests document.
     *
     * Fourteen of the cases are built around control characters, which libxml will not decode:
     * it drops the character and truncates the attribute around it, whatever options it is
     * given. Every hexadecimal reference in the file is therefore hidden from the parser and
     * resolved once the DOM has handed the attribute back, which also keeps the substitution
     * away from the markup.
     *
     * The suite lives in tests/wpt/ rather than beside the fixtures because filesProvider()
     * globs tests/files/*.html, and would look for a .url and a .urls file to go with it.
     *
     * @return array<string, array{0: string, 1: string}>
     **/
    public static function webPlatformTestsProvider(): array
    {
        $source = \file_get_contents(__DIR__ . '/wpt/parse-a-srcset-attribute.html');
        if (\strpos($source, self::$hiddenReference) !== false) {
            throw new \RuntimeException('The suite now contains ' . self::$hiddenReference . ' itself.');
        }
        $document = new \DOMDocument();
        $document->loadHTML(
            \str_replace('&#x', self::$hiddenReference, $source),
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $cases = [];
        $number = 0;
        foreach ($document->getElementsByTagName('img') as $image) {
            $attribute = $image->getAttribute('srcset');
            $cases[++$number . ': ' . $attribute] = [
                self::resolveHiddenReferences($attribute),
                self::resolveHiddenReferences($image->getAttribute('data-expect')),
            ];
        }
        return $cases;
    }

    /**
     * What the web-platform-tests cases cannot say, because they only ever report a single URL.
     *
     * @return array<string, array{0: string, 1: array}>
     **/
    public static function srcsetProvider(): array
    {
        return [
            'no descriptors leaves both absent' => ['a.png', [self::image('a.png')]],
            'commas inside the URL' => ['a,b.png 100w', [self::image('a,b.png', 100)]],
            'width descriptor' => ['a.png 640w', [self::image('a.png', 640)]],
            'density descriptor' => ['a.png 2x', [self::image('a.png', null, 2.0)]],
            'fractional density' => ['a.png 1.5x', [self::image('a.png', null, 1.5)]],
            'density without a leading digit' => ['a.png .5x', [self::image('a.png', null, 0.5)]],
            'density with an exponent' => ['a.png 1e2x', [self::image('a.png', null, 100.0)]],
            'zero density is valid' => ['a.png 0x', [self::image('a.png', null, 0.0)]],
            // A future-compat-h descriptor is never reported, it only invalidates its candidate.
            'future-compat-h next to a width' => ['a.png 640w 480h', [self::image('a.png', 640)]],
            'zero future-compat-h is invalid' => ['a.png 640w 0h', []],
            'dropping a candidate keeps the others' => ['a.png 1x 2x, b.png 3x', [self::image('b.png', null, 3.0)]],
        ];
    }
}
