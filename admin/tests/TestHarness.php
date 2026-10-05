<?php
declare(strict_types=1);

final class TestFailure extends RuntimeException
{
}

final class TestHarness
{
    private int $passed = 0;
    private int $failed = 0;

    /**
     * @param callable(): void $test
     */
    public function test(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            echo "PASS {$name}\n";
        } catch (Throwable $exception) {
            $this->failed++;
            echo "FAIL {$name}\n";
            echo '  ' . get_class($exception) . ': ' . $exception->getMessage() . "\n";
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            return;
        }

        $detail = $message !== '' ? $message . ' ' : '';
        $detail .= 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.';
        throw new TestFailure($detail);
    }

    public function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void
    {
        if (!$condition) {
            throw new TestFailure($message);
        }
    }

    /**
     * @param class-string<Throwable> $exceptionClass
     * @param callable(): void $callback
     */
    public function assertThrows(string $exceptionClass, callable $callback, ?string $messageContains = null): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            if (!$exception instanceof $exceptionClass) {
                throw new TestFailure('Expected ' . $exceptionClass . ', got ' . get_class($exception) . '.');
            }
            if ($messageContains !== null && !str_contains($exception->getMessage(), $messageContains)) {
                throw new TestFailure('Exception message did not contain "' . $messageContains . '".');
            }
            return;
        }

        throw new TestFailure('Expected exception ' . $exceptionClass . ' was not thrown.');
    }

    public function exitCode(): int
    {
        echo "\n{$this->passed} passed, {$this->failed} failed.\n";
        return $this->failed === 0 ? 0 : 1;
    }
}
