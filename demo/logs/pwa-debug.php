<?php
// logs/pwa-debug.php
// Run this to check PWA status
header('Content-Type: text/plain');

$logFile = __DIR__ . '/error.log';

echo "=== PWA Debug Info ===\n\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Check if manifest.json exists
$manifestPath = $_SERVER['DOCUMENT_ROOT'] . '/manifest.json';
echo "Manifest.json exists: " . (file_exists($manifestPath) ? 'YES' : 'NO') . "\n";
if (file_exists($manifestPath)) {
    echo "Manifest size: " . filesize($manifestPath) . " bytes\n";
}

// Check if sw.js exists
$swPath = $_SERVER['DOCUMENT_ROOT'] . '/sw.js';
echo "sw.js exists: " . (file_exists($swPath) ? 'YES' : 'NO') . "\n";
if (file_exists($swPath)) {
    echo "sw.js size: " . filesize($swPath) . " bytes\n";
}

// Check if offline.php exists
$offlinePath = $_SERVER['DOCUMENT_ROOT'] . '/offline.php';
echo "offline.php exists: " . (file_exists($offlinePath) ? 'YES' : 'NO') . "\n";

// Check icons directory
$iconsPath = $_SERVER['DOCUMENT_ROOT'] . '/assets/icons';
echo "Icons directory exists: " . (file_exists($iconsPath) ? 'YES' : 'NO') . "\n";

if (file_exists($iconsPath)) {
    $icons = glob($iconsPath . '/*.png');
    echo "Number of icons: " . count($icons) . "\n";
}

// Check last few error logs
if (file_exists($logFile)) {
    echo "\n=== Recent Error Logs ===\n";
    $logs = file($logFile);
    $lastLogs = array_slice($logs, -20);
    foreach ($lastLogs as $log) {
        echo $log;
    }
} else {
    echo "\nNo error log found yet.\n";
}
?>