<?php // tests/TestCase.php
class TestCase {
    public int $failures = 0; public int $assertions = 0;
    public function assertSame($exp, $act, $msg = '') {
        $this->assertions++;
        if ($exp !== $act) { $this->failures++; fwrite(STDERR, "  FAIL {$msg}: expected " . var_export($exp, true) . " got " . var_export($act, true) . "\n"); }
    }
    public function assertEqualsMoney($exp, $act, $msg = '') {
        $this->assertions++;
        if (abs((float)$exp - (float)$act) > 0.009) { $this->failures++; fwrite(STDERR, "  FAIL {$msg}: expected {$exp} got {$act}\n"); }
    }
    public function assertTrue($cond, $msg = '') { $this->assertSame(true, (bool)$cond, $msg); }
    public function assertThrows(callable $fn, $msg = '') {
        $this->assertions++;
        try { $fn(); $this->failures++; fwrite(STDERR, "  FAIL {$msg}: no exception thrown\n"); }
        catch (Throwable $e) { /* pass */ }
    }
}
