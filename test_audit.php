<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AuditLog;
use App\Services\Audit\AuditService;

// Count backup rows
$backupRows = AuditLog::where('actor_name_snapshot', 'db:backup')
    ->orderBy('created_at')
    ->get(['id', 'actor_name_snapshot', 'action', 'created_at', 'hash', 'previous_log_hash']);

echo "Found " . $backupRows->count() . " db:backup rows\n";

if ($backupRows->count() > 0) {
    echo "\nFirst backup row:\n";
    echo json_encode($backupRows->first()->toArray(), JSON_PRETTY_PRINT) . "\n";
    
    echo "\n\nVerifying integrity...\n";
    $service = app(AuditService::class);
    
    // Verify each backup row manually
    foreach ($backupRows as $log) {
        $expected = $service->computeHash($log->toArray(), $log->previous_log_hash);
        $match = $expected === $log->hash;
        echo "Row {$log->id}: " . ($match ? "✓" : "✗") . "\n";
        if (!$match) {
            echo "  Expected: $expected\n";
            echo "  Actual:   {$log->hash}\n";
        }
    }
    
    // Now check the full chain
    echo "\n\nFull chain verification:\n";
    $corrupted = $service->verifyIntegrity();
    if (empty($corrupted)) {
        echo "✓ Chain intact\n";
    } else {
        echo "✗ Found " . count($corrupted) . " corrupted rows\n";
        foreach ($corrupted as $id) {
            echo "  - $id\n";
        }
    }
}
