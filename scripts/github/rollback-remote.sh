#!/bin/bash

# Exit on error
set -e

# Enable debug mode if needed
# set -x

# Validate input parameters
if [ "$#" -ne 2 ]; then
    echo "Error: Missing required parameters"
    echo "Usage: $0 DEPLOY_PATH DEPLOY_SYMLINK"
    exit 1
fi

DEPLOY_PATH=$1
DEPLOY_SYMLINK=$2
TIMESTAMP=$(date "+%Y%m%d_%H%M%S")
LOG_FILE="${DEPLOY_PATH}/logs/rollback_${TIMESTAMP}.log"
DRUSH="${DEPLOY_PATH}/${DEPLOY_SYMLINK}/vendor/bin/drush"

# Create logs directory if it doesn't exist
mkdir -p "${DEPLOY_PATH}/logs"

# Function for logging
log_message() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo -e "$message" | tee -a "$LOG_FILE"
}

# Function for error handling
handle_error() {
    local exit_code=$?
    local command=$1
    log_message "❌ ERROR: Command '$command' failed with exit code $exit_code"
    # Ensure maintenance mode is disabled even if rollback fails
    $DRUSH state:set system.maintenance_mode 0
    exit $exit_code
}

# Set trap for error handling
trap 'handle_error "$BASH_COMMAND"' ERR

log_message "🔄 Starting rollback process..."

# Get current and previous release paths
RELEASES_DIR="${DEPLOY_PATH}/releases"
CURRENT_RELEASE=$(readlink -f "${DEPLOY_PATH}/${DEPLOY_SYMLINK}")
CURRENT_RELEASE_NAME=$(basename "$CURRENT_RELEASE")
PREVIOUS_RELEASE=$(ls -1dt "${RELEASES_DIR}"/* | grep -v "$CURRENT_RELEASE_NAME" | head -n1)

if [ -z "$PREVIOUS_RELEASE" ]; then
    log_message "❌ ERROR: No previous release found to roll back to!"
    exit 1
fi

log_message "📂 Current release: $CURRENT_RELEASE"
log_message "📂 Rolling back to: $PREVIOUS_RELEASE"

# Find the corresponding database backup
DUMPS_DIR="${DEPLOY_PATH}/dumps"
PREVIOUS_RELEASE_TIMESTAMP=$(echo "$PREVIOUS_RELEASE" | grep -oE "[0-9]{8}_[0-9]{6}")
CORRESPONDING_DB_BACKUP=$(ls -1t "${DUMPS_DIR}" | grep -m1 "dump_${PREVIOUS_RELEASE_TIMESTAMP}")

if [ -z "$CORRESPONDING_DB_BACKUP" ]; then
    # If exact match not found, get the closest older backup
    log_message "⚠️ Warning: Exact database backup not found, using closest older backup..."
    CORRESPONDING_DB_BACKUP=$(ls -1t "${DUMPS_DIR}" | head -n1)
fi

if [ -z "$CORRESPONDING_DB_BACKUP" ]; then
    log_message "❌ ERROR: No database backup found to restore!"
    exit 1
fi

log_message "💾 Using database backup: $CORRESPONDING_DB_BACKUP"

# Start rollback process
log_message "🔒 Enabling maintenance mode..."
$DRUSH state:set system.maintenance_mode 1

# Create backup of current state before rollback
log_message "💾 Creating backup of current state..."
BACKUP_NAME="pre_rollback_${TIMESTAMP}"
$DRUSH sql:dump --gzip --structure-tables-list="cache,cache_*,sessions,watchdog" --result-file="${DUMPS_DIR}/${BACKUP_NAME}.sql"

# Restore previous database
log_message "🔄 Restoring previous database..."
gunzip -c "${DUMPS_DIR}/${CORRESPONDING_DB_BACKUP}" | $DRUSH sql:cli

# Switch symlink to previous release
log_message "🔄 Switching to previous release..."
ln -nsf "$PREVIOUS_RELEASE" "${DEPLOY_PATH}/${DEPLOY_SYMLINK}"

# Update database and rebuild cache
log_message "🔄 Running database updates..."
$DRUSH updatedb -y

log_message "🔄 Importing configuration..."
$DRUSH config:import -y

log_message "🧹 Rebuilding cache..."
$DRUSH cache:rebuild

# Disable maintenance mode
log_message "🔓 Disabling maintenance mode..."
$DRUSH state:set system.maintenance_mode 0

log_message "✅ Rollback completed successfully!"
log_message "⚠️ Note: The current release $CURRENT_RELEASE_NAME has been kept for reference."
log_message "📝 Detailed log available at: $LOG_FILE"
