<?php
$f = __DIR__ . '/test_output.txt';
$c = file_get_contents($f);
$lines = explode("\n", str_replace("\r\n", "\n", $c));

$out = "File size: " . strlen($c) . " bytes\n";
$out .= "Total lines: " . count($lines) . "\n\n";

$out .= "=== LAST 30 LINES ===\n";
foreach (array_slice($lines, -30) as $l) {
    $out .= $l . "\n";
}

$out .= "\n=== ALL FAILED TESTS ===\n";
$failCount = 0;
foreach ($lines as $l) {
    if (strpos($l, 'FAIL:') !== false) {
        $out .= $l . "\n";
        $failCount++;
    }
}
if ($failCount === 0) {
    $out .= "(none)\n";
}

$out .= "\n=== RESULTS LINE ===\n";
foreach ($lines as $l) {
    if (stripos($l, 'RESULTS:') !== false) {
        $out .= $l . "\n";
    }
}

// Force UTF-8 with BOM for Windows compatibility
file_put_contents(__DIR__ . '/results_summary.md', "\xEF\xBB\xBF" . $out);
