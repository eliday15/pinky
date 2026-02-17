#!/bin/bash
# Pinky - Full Synchronization Script
# Runs both Python ZKTeco sync and Laravel attendance sync

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/storage/logs/full-sync.log"

echo "======================================" >> "$LOG_FILE"
echo "$(date '+%Y-%m-%d %H:%M:%S') - Starting full sync" >> "$LOG_FILE"

# Step 1: Run Python ZKTeco sync (pulls data from devices to MySQL)
echo "$(date '+%Y-%m-%d %H:%M:%S') - Running Python ZKTeco sync..." >> "$LOG_FILE"
cd "$SCRIPT_DIR/pinky_script"
source venv/bin/activate
python main.py --sync >> "$LOG_FILE" 2>&1
PYTHON_EXIT=$?

if [ $PYTHON_EXIT -eq 0 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Python sync completed successfully" >> "$LOG_FILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Python sync failed with exit code $PYTHON_EXIT" >> "$LOG_FILE"
fi

# Step 2: Run Laravel ZKTeco sync (processes MySQL data into employees/attendance)
echo "$(date '+%Y-%m-%d %H:%M:%S') - Running Laravel sync..." >> "$LOG_FILE"
cd "$SCRIPT_DIR"
/opt/homebrew/bin/php artisan zkteco:sync --days=1 >> "$LOG_FILE" 2>&1
LARAVEL_EXIT=$?

if [ $LARAVEL_EXIT -eq 0 ]; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Laravel sync completed successfully" >> "$LOG_FILE"
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') - Laravel sync failed with exit code $LARAVEL_EXIT" >> "$LOG_FILE"
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') - Full sync finished" >> "$LOG_FILE"
echo "" >> "$LOG_FILE"
