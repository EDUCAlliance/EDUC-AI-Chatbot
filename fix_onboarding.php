<?php

declare(strict_types=1);

// The deployment system generates this file to load all environment variables.
if (file_exists(__DIR__ . '/educ-bootstrap.php')) {
    require_once __DIR__ . '/educ-bootstrap.php';
} elseif (file_exists(__DIR__ . '/auto-include.php')) {
    require_once __DIR__ . '/auto-include.php';
}

require_once __DIR__ . '/src/bootstrap.php';

$db = NextcloudBot\getDbConnection();

echo "Fixing stuck onboarding sessions...\n\n";

// Check current room configs
$stmt = $db->query("SELECT room_token, onboarding_done, mention_mode, meta FROM bot_room_config");
$rooms = $stmt->fetchAll();

echo "Current room configurations:\n";
foreach ($rooms as $room) {
    $meta = json_decode($room['meta'], true);
    echo "- Room: {$room['room_token']}\n";
    echo "  Onboarding done: " . ($room['onboarding_done'] ? 'true' : 'false') . "\n";
    echo "  Mention mode: {$room['mention_mode']}\n";
    echo "  Stage: " . ($meta['stage'] ?? 'unknown') . "\n";
    echo "  Answers: " . json_encode($meta['answers'] ?? []) . "\n\n";
}

// Fix the problematic room 5jsxxjjs
$problemRoom = '5jsxxjjs';
echo "Fixing room {$problemRoom}...\n";

// Mark onboarding as done for this room
$stmt = $db->prepare("UPDATE bot_room_config SET onboarding_done = true, mention_mode = 'on_mention' WHERE room_token = ?");
$result = $stmt->execute([$problemRoom]);

if ($result) {
    echo "✓ Successfully marked onboarding as complete for room {$problemRoom}\n";
} else {
    echo "✗ Failed to update room {$problemRoom}\n";
}

// Check the result
$stmt = $db->prepare("SELECT * FROM bot_room_config WHERE room_token = ?");
$stmt->execute([$problemRoom]);
$updatedRoom = $stmt->fetch();

if ($updatedRoom) {
    echo "\nUpdated room configuration:\n";
    echo "- Room: {$updatedRoom['room_token']}\n";
    echo "- Onboarding done: " . ($updatedRoom['onboarding_done'] ? 'true' : 'false') . "\n";
    echo "- Mention mode: {$updatedRoom['mention_mode']}\n";
    echo "- Is group: " . ($updatedRoom['is_group'] ? 'true' : 'false') . "\n";
} else {
    echo "Could not find room {$problemRoom}\n";
}

echo "\nDone! The bot should now respond normally to mentions in that room.\n"; 