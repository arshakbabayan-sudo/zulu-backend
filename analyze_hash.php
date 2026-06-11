<?php
// Simulate what the backup command does vs what AuditService does

// AuditService::computeHash (lines 119-144)
// Canonical JSON structure:
$auditServiceCanonical = [
    'category' => 'system',
    'actor_type' => 'system',
    'actor_id' => null,
    'subject_type' => 'database',
    'subject_id' => 'zulu',
    'action' => 'database.backup.completed',
    'changes' => ['disk' => 'local', 'path' => 'backups/db/zulu-20260610-023045.sql.gz', 'size_bytes' => 12345],
    'context' => null,
    'created_at' => '2026-06-10T02:30:45+00:00',
    'previous' => null,  // This is the key: previous hash is included
];

// What DatabaseBackup::logBackupRecord does (line 249)
$backupServiceHash = hash('sha256', 'local|backups/db/zulu-20260610-023045.sql.gz|12345|' . date('Y-m-d H:i:s'));

echo "Hash mismatch analysis:\n";
echo "======================\n\n";

echo "AuditService::computeHash creates canonical JSON with 'previous' field\n";
echo json_encode($auditServiceCanonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "Hash: " . hash('sha256', json_encode($auditServiceCanonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n\n";

echo "DatabaseBackup::logBackupRecord creates simple concat string:\n";
echo "Input: disk|path|size|timestamp\n";
echo "Hash: $backupServiceHash\n\n";

echo "PROBLEM:\n";
echo "--------\n";
echo "1. DatabaseBackup.php line 249 computes hash directly without going through AuditService::computeHash\n";
echo "2. It also sets 'previous_log_hash' => null, breaking the chain\n";
echo "3. AuditService::verifyIntegrity (line 163) checks:\n";
echo "   - expected !== actual hash → FAIL\n";
echo "   - previous_log_hash !== expected_previous → FAIL\n\n";

echo "The backup rows bypass the entire hashing mechanism!\n";
