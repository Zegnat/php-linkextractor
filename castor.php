<?php

use Castor\Attribute\AsArgument;
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
