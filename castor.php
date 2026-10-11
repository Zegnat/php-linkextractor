<?php

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Exception\ProblemException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\ExecutableFinder;

use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;

const PHP_VERSIONS = ['7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
const DEFAULT_PHP = '8.4';

// Container runtimes we know how to drive, in auto-detection order.
const CONTAINER_RUNTIMES = ['podman', 'docker'];

/**
 * Pick the container runtime to drive.
 */
function container_runtime(string $override = ''): string
{
    $finder = new ExecutableFinder();
    $either = implode(' or ', CONTAINER_RUNTIMES);

    if ('' !== $override) {
        if (!\in_array($override, CONTAINER_RUNTIMES, true)) {
            throw new ProblemException(sprintf('Unknown container runtime "%s", expected %s.', $override, $either));
        }
        if (null === $finder->find($override)) {
            throw new ProblemException(sprintf('Container runtime "%s" is not installed.', $override));
        }

        return $override;
    }

    foreach (CONTAINER_RUNTIMES as $candidate) {
        if (null !== $finder->find($candidate)) {
            return $candidate;
        }
    }

    throw new ProblemException(sprintf('No container runtime found: install %s.', $either));
}

/**
 * Build the matrix image, returning its ID or the exit code of a failed build.
 *
 * Untagged on purpose: a tag is a mutable name shared by every checkout using
 * the same image store, so concurrent builds overwrite each other's.
 */
function build_image(string $engine, array $arguments): string|int
{
    $file = fs()->tempnam(sys_get_temp_dir(), 'linkextractor-iid-');

    try {
        $build = run(
            [$engine, 'build', ...$arguments, '-f', 'Dockerfile.matrix', '--iidfile', $file, '.'],
            context()->withAllowFailure(true),
        );
        if (!$build->isSuccessful()) {
            return $build->getExitCode();
        }

        return trim(fs()->readFile($file));
    } finally {
        fs()->remove($file);
    }
}

#[AsTask(description: 'Run the test suite on a single PHP version')]
function test(
    #[AsArgument(description: 'PHP version to test, e.g. 8.4')]
    string $php = DEFAULT_PHP,
    #[AsOption(description: 'Container runtime to use: podman or docker', mode: InputOption::VALUE_REQUIRED)]
    string $runtime = '',
): int {
    $engine = container_runtime($runtime);

    // The PHP 7.0-7.3 images are linux/amd64 only.
    $platform = \in_array($php, ['7.0', '7.1', '7.2', '7.3'], true)
        ? ['--platform', 'linux/amd64']
        : [];

    $image = build_image($engine, [...$platform, '--build-arg', "TARGET_PHP={$php}"]);
    // Pass on the exit code of a failed build.
    if (\is_int($image)) {
        return $image;
    }

    return run([$engine, 'run', '--rm', ...$platform, $image], context()->withAllowFailure(true))->getExitCode();
}

#[AsTask(description: 'Run the test suite across every supported PHP version')]
function matrix(
    #[AsOption(description: 'Container runtime to use: podman or docker', mode: InputOption::VALUE_REQUIRED)]
    string $runtime = '',
): int {
    $engine = container_runtime($runtime);

    $failed = [];
    foreach (PHP_VERSIONS as $php) {
        io()->title("PHP {$php}");
        if (0 !== test($php, runtime: $engine)) {
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
    #[AsOption(
        description: 'Minimum percentage of source lines that must be covered',
        mode: InputOption::VALUE_REQUIRED,
    )]
    float $min = 100.0,
    #[AsOption(description: 'Container runtime to use: podman or docker', mode: InputOption::VALUE_REQUIRED)]
    string $runtime = '',
): int {
    $engine = container_runtime($runtime);

    $image = build_image($engine, ['--build-arg', "TARGET_PHP={$php}", '--build-arg', 'WITH_COVERAGE=1']);
    // Pass on the exit code of a failed build.
    if (\is_int($image)) {
        return $image;
    }

    // PHPUnit has no built-in coverage threshold; enforce the minimum from the Clover report.
    $check = '$m = simplexml_load_file("/tmp/clover.xml")->project->metrics;'
        . ' $p = (int) $m["statements"] > 0 ? $m["coveredstatements"] / $m["statements"] * 100 : 100;'
        . ' printf("Line coverage: %.2f%% (required ' . $min . '%%)\n", $p);'
        . ' exit($p + 1e-9 < ' . $min . ' ? 1 : 0);';

    return run(
        [
            $engine, 'run', '--rm', $image, 'sh', '-c',
            'vendor/bin/phpunit --coverage-text --coverage-clover /tmp/clover.xml --coverage-filter src'
                . " && php -r '{$check}'",
        ],
        context()->withAllowFailure(true),
    )->getExitCode();
}
