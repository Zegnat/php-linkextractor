# LinkExtractor

The LinkExtractor tries to figure out what resources an HTML document links 
to.

This tries to solve 2 questions:

1. what constitutes a link between an HTML document and another resource, and
2. how to supply them in a useful (resolved) format.

The class is able to get (and resolve) every URL from a pre-parsed DOM in 
accordance with where the HTML5 specification allows URLs to be defined. The 
HTML5 specification is followed after [a GitHub discussion][webmention-links] 
about what a link is, and further in person discussion at [IWC Berlin 
2017][iwc-berling-2017].

[webmention-links]: https://github.com/w3c/webmention/issues/91
[iwc-berling-2017]: https://indieweb.org/2017/Berlin

## Using versions < 1.0

The current function prototypes are the same as those that will be in 1.0.0. 
This means you can write the method calls in your code right now and update 
to 1.0.0 without breaking any of your calls later.

```php
function extract(): array
function linksTo(string $url): bool
```

If you rely on specific output or exceptions from these methods however, 
there might be breaking changes before version 1.0.0. Take a look at [the 
roadmap to version 1.0.0][milestone-1] to get an idea of  what is likely to 
still be changed.

Don’t wait for 1.0.0 with using this library if you need it! Instead, start 
using it and leave the ever-important feedback.

[milestone-1]: https://github.com/Zegnat/php-linkextractor/milestone/1

## Install

Via Composer

``` bash
$ composer require zegnat/linkextractor
```

## Usage

``` php
// Parse the HTML into a DOMDocument:
$dom = new \DOMDocument();
$dom->loadHTML(file_get_contents('http://example.com/index.html'));
// Initiate the extractor with the document and its URL:
$extractor = new \Zegnat\LinkExtractor\LinkExtractor($dom, 'http://example.com/index.html');
var_dump(
    $extractor->linksTo('https://github.com/'),
    $extractor->linksTo('http://www.iana.org/domains/example')
);
/*
bool(false)
bool(true)
*/
```

On PHP 8.4 and newer you can instead pass a document parsed with the new 
`\Dom\HTMLDocument` API and its standards-compliant HTML parser:

``` php
$dom = \Dom\HTMLDocument::createFromString(
    file_get_contents('http://example.com/index.html')
);
$extractor = new \Zegnat\LinkExtractor\LinkExtractor($dom, 'http://example.com/index.html');
```

## Supported PHP versions

This library requires PHP 7.0 or newer and is tested against PHP 7.0, 7.1, 
7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4.

From PHP 8.4 onwards it accepts both `\DOMNode` and `\Dom\Node`.

## Testing

Tests run on every supported PHP version through Docker, orchestrated by 
[Castor][castor]. Each PHP version and its dependencies live entirely in their 
container, so only Docker and Castor are needed on the host:

``` bash
$ castor matrix     # run the whole matrix
$ castor test 8.4   # run a single version
$ castor coverage   # run with code coverage and enforce the minimum
```

Some test fixtures under `tests/files` are copied verbatim from external 
open-source projects under their respective licenses; see [CREDITS.md](CREDITS.md) 
for their sources and the `LICENSES` directory ([REUSE][reuse]).

[castor]: https://castor.jolicode.com/
[reuse]: https://reuse.software/

## License

The BSD Zero Clause License (0BSD). Please see the LICENSE file for more 
information.
