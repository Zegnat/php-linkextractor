<?php
/**
 * Testing if LinkExtractor extracts links.
 *
 * PHP version 7
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
    public function testExtract($htmlfile, $fileUrl, $expectedUrls)
    {
        $dom = new \DOMDocument();
        $dom->loadHTMLFile($htmlfile);
        $extractor = new LinkExtractor($dom, $fileUrl);
        $this->assertEquals($expectedUrls, $extractor->extract());
    }

    public function filesProvider(): array
    {
        $htmlfiles = glob(__DIR__ . '/files/*.html');
        natsort($htmlfiles);

        $data = [];
        foreach ($htmlfiles as $htmlfile) {
            $urlsfile = substr($htmlfile, 0, -5) . '.urls';
            $urlfile = substr($htmlfile, 0, -5) . '.url';
            $data[] = [
                $htmlfile,
                trim(file_get_contents($urlfile)),
                file($urlsfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            ];
        }
        return $data;
    }
}
