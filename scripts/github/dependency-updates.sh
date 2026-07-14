#!/bin/bash

# Drupal Dependency Updates Script
# Checks for outdated Drupal modules (direct and transitive) and creates GitHub issues
# Priority is based on update type: major (high), minor (medium), patch (low)

set -e

ISSUE_LABEL="dependency-update"
ISSUE_LABEL_DRUPAL="drupal"
ISSUE_LABEL_DIRECT="direct"
ISSUE_LABEL_TRANSITIVE="transitive"
LABEL_PRIORITY_HIGH="priority: high"
LABEL_PRIORITY_MEDIUM="priority: medium"
LABEL_PRIORITY_LOW="priority: low"
# GitHub user that auto-created/updated issues are assigned to.
ISSUE_ASSIGNEE="corentinbessire"

# Packages that are always released in lockstep and should share a single
# issue instead of one issue per package.
CORE_GROUP_ISSUE_NAME="drupal/core"
CORE_GROUP_PACKAGES=(
    "drupal/core"
    "drupal/core-composer-scaffold"
    "drupal/core-project-message"
    "drupal/core-recommended"
)

# Name used for the single issue that bundles every patch-only update for
# packages outside the core group. Patch updates are low-risk, so they're
# tracked together instead of one issue per package.
PATCH_GROUP_ISSUE_NAME="patch-releases"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

echo "🔍 Checking for Drupal dependency updates..."

# Ensure required labels exist
ensure_labels() {
    echo "📋 Ensuring issue labels exist..."

    # Main labels
    if ! gh label list --json name -q ".[].name" | grep -q "^${ISSUE_LABEL}$"; then
        gh label create "$ISSUE_LABEL" --color "0366d6" --description "Dependency update available" 2>/dev/null || true
    fi

    if ! gh label list --json name -q ".[].name" | grep -q "^${ISSUE_LABEL_DRUPAL}$"; then
        gh label create "$ISSUE_LABEL_DRUPAL" --color "0678be" --description "Drupal module/theme" 2>/dev/null || true
    fi

    # Dependency type labels
    if ! gh label list --json name -q ".[].name" | grep -q "^${ISSUE_LABEL_DIRECT}$"; then
        gh label create "$ISSUE_LABEL_DIRECT" --color "1d76db" --description "Direct dependency" 2>/dev/null || true
    fi

    if ! gh label list --json name -q ".[].name" | grep -q "^${ISSUE_LABEL_TRANSITIVE}$"; then
        gh label create "$ISSUE_LABEL_TRANSITIVE" --color "5319e7" --description "Transitive dependency" 2>/dev/null || true
    fi

    # Priority labels
    if ! gh label list --json name -q ".[].name" | grep -q "^${LABEL_PRIORITY_HIGH}$"; then
        gh label create "$LABEL_PRIORITY_HIGH" --color "d73a4a" --description "Major version update" 2>/dev/null || true
    fi

    if ! gh label list --json name -q ".[].name" | grep -q "^${LABEL_PRIORITY_MEDIUM}$"; then
        gh label create "$LABEL_PRIORITY_MEDIUM" --color "fbca04" --description "Minor version update" 2>/dev/null || true
    fi

    if ! gh label list --json name -q ".[].name" | grep -q "^${LABEL_PRIORITY_LOW}$"; then
        gh label create "$LABEL_PRIORITY_LOW" --color "0e8a16" --description "Patch version update" 2>/dev/null || true
    fi
}

# Get direct Drupal dependencies from composer.json (drupal/* packages only)
get_direct_drupal_deps() {
    jq -r '(.require // {}) + (.["require-dev"] // {}) | keys[] | select(startswith("drupal/"))' composer.json 2>/dev/null || true
}

# Check if a package is a direct dependency
is_direct_drupal_dep() {
    local package="$1"
    get_direct_drupal_deps | grep -q "^${package}$"
}

# Check if a package belongs to the drupal/core release group
is_core_group_package() {
    local package="$1"
    local p
    for p in "${CORE_GROUP_PACKAGES[@]}"; do
        [ "$package" = "$p" ] && return 0
    done
    return 1
}

# Determine update type by comparing versions
# Returns: major, minor, or patch
get_update_type() {
    local current="$1"
    local latest="$2"

    # Remove any leading 'v' and extract version numbers
    current=$(echo "$current" | sed 's/^v//')
    latest=$(echo "$latest" | sed 's/^v//')

    # Extract major.minor.patch (handle versions like 2.0.0-beta1)
    local current_major=$(echo "$current" | cut -d. -f1 | cut -d- -f1)
    local current_minor=$(echo "$current" | cut -d. -f2 | cut -d- -f1)
    local latest_major=$(echo "$latest" | cut -d. -f1 | cut -d- -f1)
    local latest_minor=$(echo "$latest" | cut -d. -f2 | cut -d- -f1)

    if [ "$current_major" != "$latest_major" ]; then
        echo "major"
    elif [ "$current_minor" != "$latest_minor" ]; then
        echo "minor"
    else
        echo "patch"
    fi
}

# Get priority label based on update type
get_priority_label() {
    local update_type="$1"

    case $update_type in
        major)
            echo "$LABEL_PRIORITY_HIGH"
            ;;
        minor)
            echo "$LABEL_PRIORITY_MEDIUM"
            ;;
        patch)
            echo "$LABEL_PRIORITY_LOW"
            ;;
    esac
}

# Find existing open issue for a dependency
find_existing_issue() {
    local dep_name="$1"

    gh issue list \
        --state open \
        --label "$ISSUE_LABEL" \
        --label "$ISSUE_LABEL_DRUPAL" \
        --json number,title,body,labels \
        --jq ".[] | select(.title | startswith(\"[Update] ${dep_name} \")) | {number, title, body, labels: [.labels[].name]}" \
        2>/dev/null || echo ""
}

# Find which direct dependency requires a transitive package
find_requiring_packages() {
    local package="$1"
    composer why "$package" 2>/dev/null | head -5 | awk '{print $1}' | tr '\n' ', ' | sed 's/,$//' || echo "unknown"
}

# Create or update a GitHub issue for a dependency update
create_or_update_issue() {
    local dep_name="$1"
    local current_version="$2"
    local latest_version="$3"
    local update_type="$4"
    local priority_label="$5"
    local is_direct="$6"

    # Get package description from Drupal.org or composer
    local description=""
    description=$(composer show "$dep_name" 2>/dev/null | grep -E "^descrip" | sed 's/descrip[tion]*[ :]*//' || echo "")

    local title="[Update] ${dep_name} ${current_version} → ${latest_version}"

    local update_type_display
    case $update_type in
        major) update_type_display="⚠️ **Major**" ;;
        minor) update_type_display="📦 **Minor**" ;;
        patch) update_type_display="🔧 **Patch**" ;;
    esac

    local dep_type_label
    local dep_type_display
    local dep_type_note=""

    if [ "$is_direct" = "true" ]; then
        dep_type_label="$ISSUE_LABEL_DIRECT"
        dep_type_display="Direct"
    else
        dep_type_label="$ISSUE_LABEL_TRANSITIVE"
        dep_type_display="Transitive"
        local required_by=$(find_requiring_packages "$dep_name")
        dep_type_note="
> **Note:** This is a transitive dependency required by: \`${required_by}\`
> Updating the parent package(s) may resolve this automatically."
    fi

    local body="## Drupal Module Update Available

**Package:** \`${dep_name}\`
**Description:** ${description:-"N/A"}
**Dependency Type:** ${dep_type_display}

| Current Version | Latest Version | Update Type |
|-----------------|----------------|-------------|
| ${current_version} | ${latest_version} | ${update_type_display} |
${dep_type_note}

### Update Command

\`\`\`bash
composer update ${dep_name} --with-dependencies
\`\`\`

### Before updating

1. Review the changelog: https://www.drupal.org/project/${dep_name#drupal/}/releases
2. Check for any breaking changes (especially for major updates)
3. Test on a development environment first

### After updating

1. Run database updates: \`drush updatedb\`
2. Export configuration: \`drush config:export\`
3. Clear caches: \`drush cache:rebuild\`
4. Test affected functionality

---
*This issue is automatically managed by the dependency updates workflow.*
*Last checked: $(date -u +"%Y-%m-%d %H:%M UTC")*"

    # Check for existing issue
    local existing=$(find_existing_issue "$dep_name")

    if [ -n "$existing" ]; then
        local issue_number=$(echo "$existing" | jq -r '.number')
        local existing_title=$(echo "$existing" | jq -r '.title')
        local existing_labels=$(echo "$existing" | jq -r '.labels[]' 2>/dev/null || echo "")

        # Check if version info has changed
        if [ "$existing_title" = "$title" ]; then
            echo -e "${YELLOW}  ↳ Issue #${issue_number} already exists and is up to date${NC}"
        else
            echo -e "${YELLOW}  ↳ Updating issue #${issue_number} (version changed)${NC}"

            # Remove old priority labels and add new one
            for old_priority in "$LABEL_PRIORITY_HIGH" "$LABEL_PRIORITY_MEDIUM" "$LABEL_PRIORITY_LOW"; do
                if echo "$existing_labels" | grep -q "^${old_priority}$"; then
                    gh issue edit "$issue_number" --remove-label "$old_priority" 2>/dev/null || true
                fi
            done

            gh issue edit "$issue_number" --title "$title" --body "$body" --add-label "$priority_label" --add-assignee "$ISSUE_ASSIGNEE"
            gh issue comment "$issue_number" --body "🔄 **Updated:** New version available. ${current_version} → ${latest_version} (${update_type} update)"
        fi
    else
        echo -e "${BLUE}  ↳ Creating new issue${NC}"
        gh issue create \
            --title "$title" \
            --body "$body" \
            --label "$ISSUE_LABEL" \
            --label "$ISSUE_LABEL_DRUPAL" \
            --label "$dep_type_label" \
            --label "$priority_label" \
            --assignee "$ISSUE_ASSIGNEE"
    fi
}

# Create or update the single combined issue for the drupal/core release
# group. Reads lines of "name|current|latest|update_type|is_direct" from the
# given file, one per outdated package in the group.
create_or_update_core_group_issue() {
    local group_file="$1"

    local description=""
    description=$(composer show "$CORE_GROUP_ISSUE_NAME" 2>/dev/null | grep -E "^descrip" | sed 's/descrip[tion]*[ :]*//' || echo "")

    # All packages in the group share the same version number, so use
    # drupal/core's own current/latest for the title (falling back to
    # whichever package is available if drupal/core itself isn't outdated).
    local current latest update_type
    read -r current latest update_type <<< "$(awk -F'|' -v name="$CORE_GROUP_ISSUE_NAME" '$1 == name { print $2, $3, $4; exit }' "$group_file")"
    if [ -z "$current" ]; then
        read -r current latest update_type <<< "$(awk -F'|' '{ print $2, $3, $4; exit }' "$group_file")"
    fi

    local title="[Update] ${CORE_GROUP_ISSUE_NAME} ${current} → ${latest}"

    local rows=""
    local worst_update_type="patch"
    local has_direct="false"
    while IFS='|' read -r name pkg_current pkg_latest pkg_update_type pkg_is_direct; do
        [ -z "$name" ] && continue

        case "$pkg_update_type" in
            major) worst_update_type="major" ;;
            minor) [ "$worst_update_type" != "major" ] && worst_update_type="minor" ;;
        esac

        local pkg_type_display
        case $pkg_update_type in
            major) pkg_type_display="⚠️ Major" ;;
            minor) pkg_type_display="📦 Minor" ;;
            patch) pkg_type_display="🔧 Patch" ;;
        esac

        local dep_display="Transitive"
        if [ "$pkg_is_direct" = "true" ]; then
            dep_display="Direct"
            has_direct="true"
        fi

        rows="${rows}| \`${name}\` | ${pkg_current} | ${pkg_latest} | ${pkg_type_display} | ${dep_display} |
"
    done < "$group_file"

    local priority_label=$(get_priority_label "$worst_update_type")
    local dep_type_label="$ISSUE_LABEL_TRANSITIVE"
    [ "$has_direct" = "true" ] && dep_type_label="$ISSUE_LABEL_DIRECT"

    local body="## Drupal Core Update Available

**Description:** ${description:-"N/A"}

These packages are versioned and released together, so they are tracked as a single update.

| Package | Current Version | Latest Version | Update Type | Dependency |
|---------|------------------|-----------------|--------------|------------|
${rows}
### Update Command

\`\`\`bash
composer update ${CORE_GROUP_PACKAGES[*]} --with-dependencies
\`\`\`

### Before updating

1. Review the changelog: https://www.drupal.org/project/drupal/releases
2. Check for any breaking changes (especially for major updates)
3. Test on a development environment first

### After updating

1. Run database updates: \`drush updatedb\`
2. Export configuration: \`drush config:export\`
3. Clear caches: \`drush cache:rebuild\`
4. Test affected functionality

---
*This issue is automatically managed by the dependency updates workflow.*
*Last checked: $(date -u +"%Y-%m-%d %H:%M UTC")*"

    local existing=$(find_existing_issue "$CORE_GROUP_ISSUE_NAME")

    if [ -n "$existing" ]; then
        local issue_number=$(echo "$existing" | jq -r '.number')
        local existing_title=$(echo "$existing" | jq -r '.title')
        local existing_labels=$(echo "$existing" | jq -r '.labels[]' 2>/dev/null || echo "")

        if [ "$existing_title" = "$title" ]; then
            echo -e "${YELLOW}  ↳ Issue #${issue_number} already exists and is up to date${NC}"
        else
            echo -e "${YELLOW}  ↳ Updating issue #${issue_number} (version changed)${NC}"

            for old_priority in "$LABEL_PRIORITY_HIGH" "$LABEL_PRIORITY_MEDIUM" "$LABEL_PRIORITY_LOW"; do
                if echo "$existing_labels" | grep -q "^${old_priority}$"; then
                    gh issue edit "$issue_number" --remove-label "$old_priority" 2>/dev/null || true
                fi
            done

            gh issue edit "$issue_number" --title "$title" --body "$body" --add-label "$priority_label" --add-assignee "$ISSUE_ASSIGNEE"
            gh issue comment "$issue_number" --body "🔄 **Updated:** New version available. ${current} → ${latest} (${update_type} update)"
        fi
    else
        echo -e "${BLUE}  ↳ Creating new issue${NC}"
        gh issue create \
            --title "$title" \
            --body "$body" \
            --label "$ISSUE_LABEL" \
            --label "$ISSUE_LABEL_DRUPAL" \
            --label "$dep_type_label" \
            --label "$priority_label" \
            --assignee "$ISSUE_ASSIGNEE"
    fi
}

# Create or update the single combined issue for every non-core-group package
# that only has a patch-level update available. Patch updates are low-risk,
# so they're bundled together instead of creating one issue per package.
# Reads lines of "name|current|latest|is_direct" from the given file.
create_or_update_patch_group_issue() {
    local group_file="$1"

    local title="[Update] ${PATCH_GROUP_ISSUE_NAME} available"

    local rows=""
    local package_names=""
    local has_direct="false"
    while IFS='|' read -r name pkg_current pkg_latest pkg_is_direct; do
        [ -z "$name" ] && continue

        local dep_display="Transitive"
        if [ "$pkg_is_direct" = "true" ]; then
            dep_display="Direct"
            has_direct="true"
        fi

        local release_notes="https://www.drupal.org/project/${name#drupal/}/releases/${pkg_latest}"

        rows="${rows}| \`${name}\` | ${pkg_current} | ${pkg_latest} | ${dep_display} | [Patch notes](${release_notes}) |
"
        package_names="${package_names} ${name}"
    done < "$group_file"
    package_names="${package_names# }"

    local dep_type_label="$ISSUE_LABEL_TRANSITIVE"
    [ "$has_direct" = "true" ] && dep_type_label="$ISSUE_LABEL_DIRECT"

    local body="## Drupal Patch Updates Available

Patch-level updates are low-risk, so they are bundled into a single issue instead of one per package.

| Package | Current Version | Latest Version | Dependency | Patch Notes |
|---------|------------------|-----------------|------------|-------------|
${rows}
### Update Command

\`\`\`bash
composer update ${package_names} --with-dependencies
\`\`\`

### Before updating

1. Review each package's patch notes linked above
2. Test on a development environment first

### After updating

1. Run database updates: \`drush updatedb\`
2. Export configuration: \`drush config:export\`
3. Clear caches: \`drush cache:rebuild\`
4. Test affected functionality

---
*This issue is automatically managed by the dependency updates workflow.*
*Last checked: $(date -u +"%Y-%m-%d %H:%M UTC")*"

    local existing=$(find_existing_issue "$PATCH_GROUP_ISSUE_NAME")

    if [ -n "$existing" ]; then
        local issue_number=$(echo "$existing" | jq -r '.number')
        local existing_body=$(echo "$existing" | jq -r '.body')

        if [[ "$existing_body" == *"$rows"* ]]; then
            echo -e "${YELLOW}  ↳ Issue #${issue_number} already exists and is up to date${NC}"
        else
            echo -e "${YELLOW}  ↳ Updating issue #${issue_number} (patch list changed)${NC}"
            gh issue edit "$issue_number" --title "$title" --body "$body" --add-label "$LABEL_PRIORITY_LOW" --add-assignee "$ISSUE_ASSIGNEE"
            gh issue comment "$issue_number" --body "🔄 **Updated:** Patch update list has changed."
        fi
    else
        echo -e "${BLUE}  ↳ Creating new issue${NC}"
        gh issue create \
            --title "$title" \
            --body "$body" \
            --label "$ISSUE_LABEL" \
            --label "$ISSUE_LABEL_DRUPAL" \
            --label "$dep_type_label" \
            --label "$LABEL_PRIORITY_LOW" \
            --assignee "$ISSUE_ASSIGNEE"
    fi
}

# Close issues for dependencies that are now up to date
close_resolved_issues() {
    local outdated_deps="$1"

    echo "🧹 Checking for resolved updates..."

    # Get all open update issues for Drupal
    gh issue list \
        --state open \
        --label "$ISSUE_LABEL" \
        --label "$ISSUE_LABEL_DRUPAL" \
        --json number,title \
        --jq '.[] | "\(.number)|\(.title)"' 2>/dev/null | while read -r issue_line; do

        local issue_number=$(echo "$issue_line" | cut -d'|' -f1)
        local issue_title=$(echo "$issue_line" | cut -d'|' -f2-)

        # Extract dependency name from title "[Update] drupal/name x.x.x → y.y.y"
        local dep_name=$(echo "$issue_title" | sed -n 's/\[Update\] \([^ ]*\) .*/\1/p')

        if [ -n "$dep_name" ]; then
            # Check if this dependency is still in the outdated list
            if ! echo "$outdated_deps" | grep -q "^${dep_name}$"; then
                echo -e "${GREEN}  ↳ Closing issue #${issue_number} - ${dep_name} is now up to date${NC}"
                gh issue close "$issue_number" --comment "✅ **Resolved:** This dependency has been updated to the latest version."
            fi
        fi
    done
}

# Main function to check outdated packages
check_outdated_packages() {
    echo ""
    echo "📦 Running composer outdated..."

    # Get ALL outdated packages (not just direct) to include transitive Drupal dependencies
    local outdated_output
    outdated_output=$(composer outdated --format=json 2>/dev/null) || true

    if [ -z "$outdated_output" ] || [ "$outdated_output" = "[]" ]; then
        echo -e "${GREEN}  ✓ All dependencies are up to date${NC}"
        close_resolved_issues ""
        return 0
    fi

    # Parse the installed array from composer outdated
    local packages=$(echo "$outdated_output" | jq -r '.installed // []')

    if [ "$packages" = "[]" ] || [ -z "$packages" ]; then
        echo -e "${GREEN}  ✓ All dependencies are up to date${NC}"
        close_resolved_issues ""
        return 0
    fi

    local package_count=$(echo "$packages" | jq 'length')

    echo "  Found ${package_count} outdated packages, filtering Drupal modules..."

    # Clear temp files
    rm -f /tmp/outdated_drupal_deps.txt /tmp/core_group_updates.txt /tmp/patch_group_updates.txt

    # Iterate through each outdated package
    echo "$packages" | jq -c '.[]' | while read -r package; do
        local name=$(echo "$package" | jq -r '.name')
        local current=$(echo "$package" | jq -r '.version')
        local latest=$(echo "$package" | jq -r '.latest')
        local abandoned=$(echo "$package" | jq -r '.abandoned // false')

        # Skip non-Drupal packages
        if [[ ! "$name" =~ ^drupal/ ]]; then
            continue
        fi

        # Determine if direct or transitive
        local is_direct="false"
        if is_direct_drupal_dep "$name"; then
            is_direct="true"
        fi

        # Determine update type and priority
        local update_type=$(get_update_type "$current" "$latest")
        local priority_label=$(get_priority_label "$update_type")

        if [ "$is_direct" = "true" ]; then
            echo -e "${BLUE}  → ${name}: ${current} → ${latest} (${update_type}, direct)${NC}"
        else
            echo -e "${MAGENTA}  → ${name}: ${current} → ${latest} (${update_type}, transitive)${NC}"
        fi

        if is_core_group_package "$name"; then
            # Grouped packages are always released together; defer them to a
            # single combined issue instead of creating one per package.
            echo "$CORE_GROUP_ISSUE_NAME" >> /tmp/outdated_drupal_deps.txt
            echo "${name}|${current}|${latest}|${update_type}|${is_direct}" >> /tmp/core_group_updates.txt
        elif [ "$update_type" = "patch" ]; then
            # Patch updates are low-risk; bundle them into a single issue
            # instead of creating one per package.
            echo "$PATCH_GROUP_ISSUE_NAME" >> /tmp/outdated_drupal_deps.txt
            echo "${name}|${current}|${latest}|${is_direct}" >> /tmp/patch_group_updates.txt
        else
            echo "$name" >> /tmp/outdated_drupal_deps.txt
            create_or_update_issue "$name" "$current" "$latest" "$update_type" "$priority_label" "$is_direct"
        fi
    done

    # Create/update the single combined issue for the drupal/core release group
    if [ -f /tmp/core_group_updates.txt ]; then
        create_or_update_core_group_issue /tmp/core_group_updates.txt
        rm -f /tmp/core_group_updates.txt
    fi

    # Create/update the single combined issue for patch-only updates
    if [ -f /tmp/patch_group_updates.txt ]; then
        create_or_update_patch_group_issue /tmp/patch_group_updates.txt
        rm -f /tmp/patch_group_updates.txt
    fi

    # Close resolved issues
    if [ -f /tmp/outdated_drupal_deps.txt ]; then
        close_resolved_issues "$(cat /tmp/outdated_drupal_deps.txt)"
        rm -f /tmp/outdated_drupal_deps.txt
    else
        close_resolved_issues ""
    fi
}

# Main execution
main() {
    ensure_labels
    check_outdated_packages

    echo ""
    echo "✅ Dependency update check complete"
}

main
