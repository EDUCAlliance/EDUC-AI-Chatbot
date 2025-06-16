<?php 

$repoUrl = 'https://github.com/EDUCAlliance/EDUC-AI-Chatbot.git';
// Working directory: the current directory where update_repo.php is located
$localPath = __DIR__;

chdir($localPath);

$branch = 'main';


// Check if the directory is already initialized as a Git repository
if (!is_dir($localPath . '/.git')) {
    error_log("Initializing Git repository...\n");
    
    // Initialize the Git repository
    $initOutput = shell_exec('git init 2>&1');
    
    // Add the remote "origin"
    $remoteOutput = shell_exec("git remote add origin $repoUrl 2>&1");
    
    // Fetch data from the remote repository
    $fetchOutput = shell_exec("git fetch origin 2>&1");
    
    // Reset the local repository to match the remote branch state
    $resetOutput = shell_exec("git reset --hard origin/$branch 2>&1");
    
    error_log("Repository initialized and data fetched:\n" . $initOutput . $remoteOutput . $fetchOutput . $resetOutput);
} else {
    // If the repository already exists, pull updates from the remote repository
    $pullOutput = shell_exec("git pull origin $branch 2>&1");
    error_log("Repository updated:\n" . $pullOutput);
}
?>