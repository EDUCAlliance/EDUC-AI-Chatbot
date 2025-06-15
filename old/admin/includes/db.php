<?php

use EDUC\Database\Database;

// This file can be used to initialize the database connection 
// or provide helper functions for database interactions within the admin panel.

// Example: Function to get the Database instance
function get_db_instance(): Database {
    static $dbInstance = null;
    if ($dbInstance === null) {
        $dbPath = getenv('DB_PATH');
        if (!$dbPath) {
            throw new \Exception('DB_PATH environment variable not set.');
        }
        $dbInstance = Database::getInstance($dbPath);
    }
    return $dbInstance;
}

// You might call get_db_instance() early if needed globally
// $db = get_db_instance(); 