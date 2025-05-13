<?php
session_save_path('/app/code/public'); // Set session path *before* session_start()
session_start();

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: index.php');
exit; 