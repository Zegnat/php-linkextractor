<?php
require_once __DIR__ . '/../vendor/autoload.php';

class LinkExtractorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider filesProvider
     */
    public function testExtract($htmlfile, $fileUrl, $expectedUrls)
    {
        $dom = new \DOMDocument();
        $dom->loadHTMLFile($htmlfile);
        $extractor = new \Zegnat\LinkExtractor\LinkExtractor($dom, $fileUrl);
        $this->assertEquals($expectedUrls, $extractor->extract());
    }

    public function filesProvider()
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
?>
