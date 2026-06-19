<?php

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\io;
use function Castor\run;

const PHP_VERSIONS = ['7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];
const DEFAULT_PHP = '8.4';

#[AsTask(description: 'Run the test suite on a single PHP version')]
function test(
    #[AsArgument(description: 'PHP version to test, e.g. 8.4')]
    string $php = DEFAULT_PHP,
): int {
    // The PHP 7.0-7.3 images are linux/amd64 only.
    $platform = \in_array($php, ['7.0', '7.1', '7.2', '7.3'], true)
        ? ['--platform', 'linux/amd64']
        : [];
    $tag = "linkextractor-test:{$php}";

    $build = run(
        [
            'docker', 'build', ...$platform, '-f', 'Dockerfile.matrix',
            '--build-arg', "PHP_VERSION={$php}", '-t', $tag, '.',
        ],
        context()->withAllowFailure(true),
    );
    if (!$build->isSuccessful()) {
        return $build->getExitCode();
    }

    return run(['docker', 'run', '--rm', ...$platform, $tag], context()->withAllowFailure(true))->getExitCode();
}

#[AsTask(description: 'Run the test suite across every supported PHP version')]
function matrix(): int
{
    $failed = [];
    foreach (PHP_VERSIONS as $php) {
        io()->title("PHP {$php}");
        if (0 !== test($php)) {
            $failed[] = $php;
        }
    }

    if ([] !== $failed) {
        io()->error('Failed on PHP: ' . implode(' ', $failed));

        return 1;
    }

    io()->success('All PHP versions passed.');

    return 0;
}

#[AsTask(description: 'Run the tests with code coverage and enforce a minimum')]
function coverage(
    #[AsArgument(description: 'PHP version to measure coverage on')]
    string $php = DEFAULT_PHP,
    #[AsOption(description: 'Minimum percentage of source lines that must be covered')]
    float $min = 100.0,
): int {
    $tag = "linkextractor-coverage:{$php}";
    $build = run(
        [
            'docker', 'build', '-f', 'Dockerfile.matrix', '--build-arg',
            "PHP_VERSION={$php}", '--build-arg', 'WITH_COVERAGE=1', '-t', $tag, '.',
        ],
        context()->withAllowFailure(true),
    );
    if (!$build->isSuccessful()) {
        return $build->getExitCode();
    }

    // PHPUnit has no built-in coverage threshold; enforce the minimum from the Clover report.
    $check = '$m = simplexml_load_file("/tmp/clover.xml")->project->metrics;'
        . ' $p = (int) $m["statements"] > 0 ? $m["coveredstatements"] / $m["statements"] * 100 : 100;'
        . ' printf("Line coverage: %.2f%% (required ' . $min . '%%)\n", $p);'
        . ' exit($p + 1e-9 < ' . $min . ' ? 1 : 0);';

    return run(
        [
            'docker', 'run', '--rm', $tag, 'sh', '-c',
            'vendor/bin/phpunit --coverage-text --coverage-clover /tmp/clover.xml --coverage-filter src'
                . " && php -r '{$check}'",
        ],
        context()->withAllowFailure(true),
    )->getExitCode();
}
