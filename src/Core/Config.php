<?php
// No longer needed as settings are managed in the database via the admin panel
// and Chatbot.php no longer depends on this for those settings.

// Optional: Keep for other potential config needs? For now, assume removal.
namespace EDUC\Core;

// The Config class previously defined here has been removed as its functionality
// for systemPrompt, model, and botMention is now handled by the database
// and accessed via the Database class. 