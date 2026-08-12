<?php
/**
 * A small, dependency-free assertion framework. No PHPUnit, no composer
 * install required — this runs anywhere PHP does, including shared
 * hosting with no CLI package manager access. Structured similarly to
 * PHPUnit's assertion style on purpose, so migrating to real PHPUnit
 * later (composer require --dev phpunit/phpunit) is a straightforward port.
 */
class TestRunner {
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private string $currentTest = '';

    public function run(string $testClass): void {
        echo "\n--- {$testClass} ---\n";
        $instance = new $testClass();
        $methods = get_class_methods($instance);
        foreach ($methods as $method) {
            if (!str_starts_with($method, 'test')) continue;
            $this->currentTest = "{$testClass}::{$method}";
            try {
                $instance->$method($this);
                echo "  PASS  {$method}\n";
            } catch (Throwable $e) {
                $this->failed++;
                $this->failures[] = "{$this->currentTest}: " . $e->getMessage();
                echo "  FAIL  {$method} — " . $e->getMessage() . "\n";
            }
        }
    }

    public function assertTrue(bool $condition, string $message = 'Expected true, got false'): void {
        if (!$condition) throw new RuntimeException($message);
        $this->passed++;
    }

    public function assertFalse(bool $condition, string $message = 'Expected false, got true'): void {
        $this->assertTrue(!$condition, $message);
    }

    public function assertEquals($expected, $actual, string $message = ''): void {
        if ($expected !== $actual) {
            $msg = $message ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
            throw new RuntimeException($msg);
        }
        $this->passed++;
    }

    public function assertStringContains(string $needle, string $haystack, string $message = ''): void {
        if (!str_contains($haystack, $needle)) {
            $msg = $message ?: "Expected to find \"{$needle}\" in \"{$haystack}\"";
            throw new RuntimeException($msg);
        }
        $this->passed++;
    }

    public function assertStringNotContains(string $needle, string $haystack, string $message = ''): void {
        if (str_contains($haystack, $needle)) {
            $msg = $message ?: "Expected NOT to find \"{$needle}\" in \"{$haystack}\"";
            throw new RuntimeException($msg);
        }
        $this->passed++;
    }

    public function summary(): int {
        echo "\n=========================\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        if ($this->failures) {
            echo "\nFailures:\n";
            foreach ($this->failures as $f) echo "  - {$f}\n";
        }
        echo "=========================\n";
        return $this->failed === 0 ? 0 : 1;
    }
}
