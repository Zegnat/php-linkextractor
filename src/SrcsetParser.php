<?php
/**
 * The SrcsetParser turns the value of a srcset attribute into image candidates.
 *
 * A srcset attribute does not hold URLs. It holds a comma-separated list of image candidate strings, each of which
 * is a URL optionally followed by descriptors. Neither the comma nor the whitespace can be relied on to split them,
 * so the attribute needs the parser the HTML Standard describes for it.
 *
 * @author    Martijn van der Ven <martijn@vanderven.se>
 * @copyright 2026 Martijn van der Ven
 * @license   BSD Zero Clause License
 * @version   0.3.0
 * @link      https://github.com/Zegnat/php-linkextractor
 * @see       https://html.spec.whatwg.org/multipage/images.html#parsing-a-srcset-attribute The algorithm followed.
 */

declare(strict_types=1);

namespace Zegnat\LinkExtractor;

/**
 * SrcsetParser class for parsing the image candidate strings in a srcset attribute.
 */
class SrcsetParser
{
    /**
     * Characters identified as ASCII whitespace by the Infra Standard.
     *
     * @var string $spaceCharacters
     * @see https://infra.spec.whatwg.org/#ascii-whitespace
     **/
    private static $spaceCharacters = " \t\n\f\r";

    /**
     * A valid non-negative integer followed by the unit of a width or a future-compat-h descriptor.
     *
     * @var string $integerDescriptor
     * @see https://html.spec.whatwg.org/multipage/common-microsyntaxes.html#valid-non-negative-integer
     **/
    private static $integerDescriptor = '@\A([0-9]+)([wh])\z@';

    /**
     * A valid floating-point number followed by the unit of a pixel density descriptor.
     *
     * @var string $densityDescriptor
     * @see https://html.spec.whatwg.org/multipage/common-microsyntaxes.html#valid-floating-point-number
     **/
    private static $densityDescriptor = '@\A(-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)x\z@';

    /**
     * Parse the value of a srcset attribute.
     *
     * Every returned image candidate is an array of three keys: the string `url` exactly as it was written in the
     * attribute, an integer `width` for a width descriptor, and a float `density` for a pixel density descriptor.
     * A descriptor that was not given is null. URLs are not resolved, trimmed, or validated in any way, as this
     * parser has no idea what document the attribute came from.
     *
     * Candidates the HTML Standard rejects are left out. That covers descriptors that are not a width, a pixel
     * density or a future-compat-h, as well as combinations that contradict each other, like `2x 640w`.
     *
     * The attribute may be empty, be nothing but whitespace, or hold no valid candidate at all; the result is then
     * an empty array. The parser never throws.
     *
     * @param string $input The value of a srcset attribute.
     *
     * @return array Every valid image candidate, in the order they appear in the attribute.
     *
     * @see https://html.spec.whatwg.org/multipage/images.html#parsing-a-srcset-attribute
     *
     * @api
     **/
    public static function parse(string $input): array
    {
        $candidates = [];
        $length = \strlen($input);
        $position = 0;
        while (true) {
            // Splitting loop: skip the whitespace and commas that separate the image candidate strings.
            $position += \strspn($input, self::$spaceCharacters . ',', $position);
            if ($position >= $length) {
                return $candidates;
            }
            // Only whitespace ends the URL, it is allowed to contain commas.
            $urlLength = \strcspn($input, self::$spaceCharacters, $position);
            $url = \substr($input, $position, $urlLength);
            $position += $urlLength;
            $descriptors = [];
            if (\substr($url, -1) === ',') {
                // Trailing commas are removed, and this image candidate string has no descriptors.
                $url = \rtrim($url, ',');
            } else {
                $descriptors = self::tokenizeDescriptors($input, $position);
            }
            $image = self::parseDescriptors($descriptors);
            if (!$image['error']) {
                $candidates[] = [
                    'url' => $url,
                    'width' => $image['width'],
                    'density' => $image['density'],
                ];
            }
        }
    }

    /**
     * Run the descriptor tokenizer over the descriptors of a single image candidate string.
     *
     * @param string $input    The value of a srcset attribute.
     * @param int    $position The offset directly after the URL. Moved to directly after the candidate string.
     *
     * @return array The descriptors, as the strings they were written as.
     *
     * @see https://html.spec.whatwg.org/multipage/images.html#parsing-a-srcset-attribute
     **/
    private static function tokenizeDescriptors(string $input, int &$position): array
    {
        $descriptors = [];
        $descriptor = '';
        $length = \strlen($input);
        $position += \strspn($input, self::$spaceCharacters, $position);
        $state = 'in descriptor';
        while ($position < $length) {
            $character = $input[$position];
            if ($state === 'in parens') {
                // A comma between parentheses does not end the image candidate string.
                $descriptor .= $character;
                if ($character === ')') {
                    $state = 'in descriptor';
                }
            } elseif ($state === 'after descriptor') {
                if (\strpos(self::$spaceCharacters, $character) === false) {
                    // This character opens the next descriptor, so hand it to the in descriptor state unconsumed.
                    $state = 'in descriptor';
                    continue;
                }
            } elseif ($character === ',') {
                $position++;
                break;
            } elseif ($character === '(') {
                $descriptor .= $character;
                $state = 'in parens';
            } elseif (\strpos(self::$spaceCharacters, $character) !== false) {
                if ($descriptor !== '') {
                    $descriptors[] = $descriptor;
                    $descriptor = '';
                }
                $state = 'after descriptor';
            } else {
                $descriptor .= $character;
            }
            $position++;
        }
        if ($descriptor !== '') {
            $descriptors[] = $descriptor;
        }
        return $descriptors;
    }

    /**
     * Apply the descriptor parser to the descriptors of a single image candidate string.
     *
     * The returned array holds the boolean `error`, which tells whether the candidate is to be dropped, plus the
     * `width` and `density` that were found. Both are null when the candidate had no descriptor for them.
     *
     * A future-compat-h descriptor is recognised so that it can invalidate the candidates the HTML Standard says it
     * invalidates, but it is never reported: it does not describe the image, only a future version of the syntax.
     *
     * @param array $descriptors The descriptors of one image candidate string.
     *
     * @return array The `error` flag, and the `width` and `density` that were parsed.
     *
     * @see https://html.spec.whatwg.org/multipage/images.html#parsing-a-srcset-attribute
     **/
    private static function parseDescriptors(array $descriptors): array
    {
        $error = false;
        $width = null;
        $density = null;
        $futureCompatH = null;
        foreach ($descriptors as $descriptor) {
            $matches = [];
            if (\preg_match(self::$integerDescriptor, $descriptor, $matches)) {
                $value = \intval($matches[1]);
                if ($matches[2] === 'w') {
                    if ($width !== null || $density !== null) {
                        $error = true;
                    }
                    if ($value === 0) {
                        $error = true;
                    } else {
                        $width = $value;
                    }
                } else {
                    if ($futureCompatH !== null || $density !== null) {
                        $error = true;
                    }
                    if ($value === 0) {
                        $error = true;
                    } else {
                        $futureCompatH = $value;
                    }
                }
            } elseif (\preg_match(self::$densityDescriptor, $descriptor, $matches)) {
                if ($width !== null || $density !== null || $futureCompatH !== null) {
                    $error = true;
                }
                $value = \floatval($matches[1]);
                if ($value < 0) {
                    $error = true;
                } else {
                    $density = $value;
                }
            } else {
                $error = true;
            }
        }
        // A future-compat-h descriptor only means anything next to a width descriptor.
        if ($futureCompatH !== null && $width === null) {
            $error = true;
        }
        return ['error' => $error, 'width' => $width, 'density' => $density];
    }
}
