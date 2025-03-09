#!/bin/bash
# Process all split JSON files one by one

SPLIT_DIR="data/split"

# Check if the split directory exists
if [ ! -d "$SPLIT_DIR" ]; then
  echo "Error: Split directory not found: $SPLIT_DIR"
  echo "Run split-json.php first to create split files."
  exit 1
fi

# Get count of JSON files
FILE_COUNT=$(ls -1 "$SPLIT_DIR"/*.json 2>/dev/null | wc -l)
if [ "$FILE_COUNT" -eq 0 ]; then
  echo "No JSON files found in $SPLIT_DIR"
  exit 1
fi

echo "Found $FILE_COUNT files to process"
echo "Starting processing..."

# Process each file
COUNTER=1
for FILE in "$SPLIT_DIR"/*.json; do
  echo ""
  echo "[$COUNTER/$FILE_COUNT] Processing $FILE"
  echo "----------------------------------------"
  
  # Process single file
  php process-single.php "$FILE"
  
  # Check result
  if [ $? -ne 0 ]; then
    echo "ERROR: Failed to process $FILE"
  else
    echo "Successfully processed $FILE"
  fi
  
  echo "----------------------------------------"
  
  # Increment counter
  COUNTER=$((COUNTER+1))
  
  # Sleep briefly to let system recover
  sleep 2
done

echo ""
echo "All files processed. Check logs for any errors." 