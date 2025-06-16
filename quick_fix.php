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

echo "Quick fix for stuck onboarding...\n";

// Fix the room that's stuck at stage 6
$stmt = $db->prepare("UPDATE bot_room_config SET onboarding_done = true WHERE room_token = '5jsxxjjs'");
$result = $stmt->execute();

if ($result) {
    echo "✓ Fixed room 5jsxxjjs - onboarding marked as complete\n";
} else {
    echo "✗ Failed to fix room\n";
}

// Verify the fix
$stmt = $db->prepare("SELECT onboarding_done, mention_mode FROM bot_room_config WHERE room_token = '5jsxxjjs'");
$stmt->execute();
$room = $stmt->fetch();

if ($room && $room['onboarding_done']) {
    echo "✓ Verified: Room is now out of onboarding mode\n";
    echo "  Mention mode: {$room['mention_mode']}\n";
} else {
    echo "✗ Something went wrong\n";
}

echo "\nNow try sending '@educai hello' to the bot!\n"; 