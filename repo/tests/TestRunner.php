<?php
/**
 * Minimal test framework for HelpDeskPro
 * ---------------------------------------------------------
 * Deliberately zero-dependency (no Composer/PHPUnit) so it runs anywhere
 * PHP does - including a plain XAMPP install with no extra tooling.
 *
 * Usage:  php tests/run_tests.php
 */

class TestRunner {
    private $passed = 0;
    private $failed = 0;
    private $failures = [];
    private $currentGroup = '';
    private $currentTest = '';

    public function group($name) {
        $this->currentGroup = $name;
        echo "\n\033[1;36m== $name ==\033[0m\n";
    }

    public function test($name, callable $fn) {
        $this->currentTest = $name;
        try {
            $fn($this);
            echo "  \033[0;32mPASS\033[0m  $name\n";
            $this->passed++;
        } catch (AssertionFailed $e) {
            echo "  \033[0;31mFAIL\033[0m  $name\n";
            echo "        \033[0;31m{$e->getMessage()}\033[0m\n";
            $this->failed++;
            $this->failures[] = "{$this->currentGroup} > $name: {$e->getMessage()}";
        } catch (Throwable $e) {
            echo "  \033[0;31mERROR\033[0m $name\n";
            echo "        \033[0;31m" . get_class($e) . ": {$e->getMessage()}\033[0m\n";
            echo "        at {$e->getFile()}:{$e->getLine()}\n";
            $this->failed++;
            $this->failures[] = "{$this->currentGroup} > $name: " . $e->getMessage();
        }
    }

    // ---- assertions ----
    public function assertTrue($actual, $msg = '') {
        if ($actual !== true) {
            throw new AssertionFailed($msg ?: 'Expected true, got ' . var_export($actual, true));
        }
    }
    public function assertFalse($actual, $msg = '') {
        if ($actual !== false) {
            throw new AssertionFailed($msg ?: 'Expected false, got ' . var_export($actual, true));
        }
    }
    public function assertEquals($expected, $actual, $msg = '') {
        if ($expected != $actual) {
            throw new AssertionFailed($msg ?: 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
    public function assertNotEquals($expected, $actual, $msg = '') {
        if ($expected == $actual) {
            throw new AssertionFailed($msg ?: 'Expected value to differ from ' . var_export($expected, true));
        }
    }
    public function assertCount($expected, $arr, $msg = '') {
        $actual = is_array($arr) ? count($arr) : -1;
        if ($actual !== $expected) {
            throw new AssertionFailed($msg ?: "Expected $expected item(s), got $actual");
        }
    }
    public function assertNotEmpty($value, $msg = '') {
        if (empty($value)) {
            throw new AssertionFailed($msg ?: 'Expected non-empty value');
        }
    }
    public function assertEmpty($value, $msg = '') {
        if (!empty($value)) {
            throw new AssertionFailed($msg ?: 'Expected empty value, got ' . var_export($value, true));
        }
    }
    public function assertContains($needle, $haystack, $msg = '') {
        if (strpos((string) $haystack, (string) $needle) === false) {
            throw new AssertionFailed($msg ?: "Expected string to contain '$needle'");
        }
    }
    public function assertNull($value, $msg = '') {
        if ($value !== null) {
            throw new AssertionFailed($msg ?: 'Expected null, got ' . var_export($value, true));
        }
    }

    public function summary() {
        $total = $this->passed + $this->failed;
        echo "\n" . str_repeat('-', 60) . "\n";
        if ($this->failed === 0) {
            echo "\033[1;32mALL PASSED\033[0m  ($this->passed/$total assertions-groups)\n";
        } else {
            echo "\033[1;31m$this->failed FAILED\033[0m, \033[0;32m$this->passed passed\033[0m (of $total)\n\n";
            echo "Failures:\n";
            foreach ($this->failures as $f) {
                echo "  - $f\n";
            }
        }
        echo str_repeat('-', 60) . "\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

class AssertionFailed extends Exception {}
