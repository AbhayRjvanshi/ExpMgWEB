<?php
// Execute test suite and write results to a plain ASCII file
$output = [];
$exitCode = 0;
exec('php ' . escapeshellarg(__DIR__ . '/run_tests.php') . ' 2>&1', $output, $exitCode);

$resultFile = __DIR__ . '/test_result_final.php';

// Extract key info
$lastLines = array_slice($output, -30);
$failures = array_filter($output, function($l) { return strpos($l, 'FAIL:') !== false; });
$resultLine = array_filter($output, function($l) { return stripos($l, 'RESULTS:') !== false; });

$data = [
    'exit_code' => $exitCode,
    'total_lines' => count($output),
    'result_line' => array_values($resultLine),
    'failures' => array_values($failures),
    'last_lines' => $lastLines,
];

// Write as a PHP file that returns the data (guaranteed UTF-8 safe)
file_put_contents($resultFile, "<?php\nreturn " . var_export($data, true) . ";\n");
