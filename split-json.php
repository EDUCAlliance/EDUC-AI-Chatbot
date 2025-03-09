<?php
/**
 * EDUC AI TalkBot - JSON Splitter Tool
 * 
 * This script splits a large JSON file into multiple smaller files
 * for easier processing by the ingestion script.
 * 
 * Usage:
 *   php split-json.php <input_file> [<chunk_size>] [<output_dir>]
 * 
 * Example:
 *   php split-json.php data/lo.json 50 data/split
 */

// Set memory limit for processing large files
ini_set('memory_limit', '2G');

// Parse command line arguments
if ($argc < 2) {
    echo "Usage: php split-json.php <input_file> [<chunk_size>] [<output_dir>]\n";
    exit(1);
}

$inputFile = $argv[1];
$chunkSize = isset($argv[2]) ? (int)$argv[2] : 50; // Default to 50 items per file
$outputDir = isset($argv[3]) ? $argv[3] : dirname($inputFile) . '/split';

// Check if input file exists
if (!file_exists($inputFile)) {
    echo "Error: Input file '$inputFile' not found.\n";
    exit(1);
}

// Create output directory if it doesn't exist
if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0777, true)) {
        echo "Error: Unable to create output directory '$outputDir'.\n";
        exit(1);
    }
}

echo "Splitting JSON file: $inputFile\n";
echo "Chunk size: $chunkSize items per file\n";
echo "Output directory: $outputDir\n\n";

// Start processing
try {
    // Get input file size
    $fileSize = filesize($inputFile);
    $fileSizeMB = round($fileSize / (1024 * 1024), 2);
    echo "Input file size: $fileSizeMB MB\n";
    
    // Open the file
    $handle = fopen($inputFile, 'r');
    if ($handle === false) {
        throw new Exception("Could not open input file: $inputFile");
    }
    
    // Read the first character to verify it's a JSON array
    $firstChar = fread($handle, 1);
    if ($firstChar !== '[') {
        fclose($handle);
        throw new Exception("Input must be a JSON array (starting with '[')");
    }
    
    // Reset file pointer and skip the opening bracket
    rewind($handle);
    fseek($handle, 1);
    
    // Variables for tracking
    $itemCount = 0;
    $fileCount = 0;
    $currentItems = [];
    $currentItem = '';
    $braceCount = 0;
    
    // Process line by line
    while (($line = fgets($handle)) !== false) {
        // If we're between items or at the start/end
        if (trim($line) === '[' || trim($line) === ']') {
            continue; // Skip array markers
        }
        
        // Start collecting an item
        $currentItem .= $line;
        
        // Count open braces to know when we have a complete item
        $braceOpen = substr_count($line, '{');
        $braceClose = substr_count($line, '}');
        $braceCount += $braceOpen - $braceClose;
        
        // Once we've seen a balanced set of braces, we have a complete item
        if ($braceCount === 0 && !empty($currentItem)) {
            // Clean up the item - remove trailing commas
            $currentItem = rtrim($currentItem, ",\r\n ");
            
            // Parse the item
            $item = json_decode($currentItem, true);
            
            if ($item !== null) {
                $currentItems[] = $item;
                $itemCount++;
                
                // When we've reached the chunk size or end of file, write to a new file
                if (count($currentItems) >= $chunkSize) {
                    writeJsonFile($currentItems, $outputDir, $fileCount, $inputFile);
                    $currentItems = [];
                    $fileCount++;
                }
            }
            
            // Reset for next item
            $currentItem = '';
        }
    }
    
    // Write any remaining items
    if (!empty($currentItems)) {
        writeJsonFile($currentItems, $outputDir, $fileCount, $inputFile);
        $fileCount++;
    }
    
    // Close the file
    fclose($handle);
    
    echo "\nFinished processing:\n";
    echo "Total items: $itemCount\n";
    echo "Total files created: $fileCount\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    
    // Close handle if open
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    
    exit(1);
}

/**
 * Write a chunk of items to a JSON file
 * 
 * @param array $items Items to write
 * @param string $outputDir Output directory
 * @param int $fileIndex File index for naming
 * @param string $inputFile Original input file for naming
 */
function writeJsonFile(array $items, string $outputDir, int $fileIndex, string $inputFile) {
    $baseName = pathinfo($inputFile, PATHINFO_FILENAME);
    $outputFile = $outputDir . '/' . $baseName . '_' . sprintf('%03d', $fileIndex) . '.json';
    
    echo "Writing file: $outputFile (" . count($items) . " items)\n";
    
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new Exception("Failed to encode JSON for chunk $fileIndex");
    }
    
    if (file_put_contents($outputFile, $json) === false) {
        throw new Exception("Failed to write file: $outputFile");
    }
} 