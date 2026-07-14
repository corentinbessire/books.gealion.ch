#!/bin/bash

# Security Audit Script
# Checks composer and npm dependencies for known vulnerabilities
# Creates/updates GitHub issues for both direct and transitive dependencies

set -e

ISSUE_LABEL="security"
ISSUE_LABEL_COMPOSER="composer"
ISSUE_LABEL_NPM="npm"
ISSUE_LABEL_DIRECT="direct"
ISSUE_LABEL_TRANSITIVE="transitive"
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

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

echo "🔍 Starting security audit..."

# Ensure required labels exist
ensure_labels() {
    echo "📋 Ensuring issue labels exist..."

    # Main labels
    for label in "$ISSUE_LABEL" "$ISSUE_LABEL_COMPOSER" "$ISSUE_LABEL_NPM"; do
        if ! gh label list --json name -q ".[].name" | grep -q "^${label}$"; then
            case $label in
                "security")
                    gh label create "$label" --color "d73a4a" --description "Security vulnerability" 2>/dev/null || true
                    ;;
                "composer")
                    gh label create "$label" --color "6f42c1" --description "PHP/Composer dependency" 2>/dev/null || true
                    ;;
                "npm")
                    gh label create "$label" --color "f9a825" --description "Node/npm dependency" 2>/dev/null || true
                    ;;
            esac
        fi
    done

    # Dependency type labels
    if ! gh label list --json name -q ".[].name" | grep -q "^${ISSUE_LABEL_DIRECT}$"; then
        gh label create "$ISSUE_LABEL_DIRECT" --color "1d76db" --description "Direct dependency" 2>/dev/null || true
    fi

    if ! gh label list --json name -q ".[].name" | grep -q "^${ISSUE_LABEL_TRANSITIVE}$"; then
        gh label create "$ISSUE_LABEL_TRANSITIVE" --color "5319e7" --description "Transitive dependency" 2>/dev/null || true
    fi
}

# Get direct Composer dependencies from composer.json
get_direct_composer_deps() {
    jq -r '(.require // {}) + (.["require-dev"] // {}) | keys[]' composer.json 2>/dev/null | grep -v "^php$" | grep -v "^ext-" || true
}

# Get direct npm dependencies from package.json
get_direct_npm_deps() {
    local package_json="${THEME_ROOT}/package.json"
    if [ -f "$package_json" ]; then
        jq -r '(.dependencies // {}) + (.devDependencies // {}) | keys[]' "$package_json" 2>/dev/null || true
    fi
}

# Check if a package is a direct dependency
is_direct_composer_dep() {
    local package="$1"
    get_direct_composer_deps | grep -q "^${package}$"
}

is_direct_npm_dep() {
    local package="$1"
    get_direct_npm_deps | grep -q "^${package}$"
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

# Find which direct dependency requires a transitive package
find_requiring_composer_packages() {
    local package="$1"
    composer why "$package" 2>/dev/null | head -5 | awk '{print $1}' | tr '\n' ', ' | sed 's/,$//' || echo "unknown"
}

find_requiring_npm_packages() {
    local package="$1"
    npm why "$package" 2>/dev/null | head -5 | grep -oE '[a-zA-Z0-9@/_-]+' | head -3 | tr '\n' ', ' | sed 's/,$//' || echo "unknown"
}

# Find existing open issue for a dependency
find_existing_issue() {
    local dep_name="$1"
    local dep_type="$2"  # composer or npm

    # Search for open issues with matching title pattern
    gh issue list \
        --state open \
        --label "$ISSUE_LABEL" \
        --label "$dep_type" \
        --json number,title,body,labels \
        --jq ".[] | select(.title | startswith(\"[Security] ${dep_name} \")) | {number, title, body, labels: [.labels[].name]}" \
        2>/dev/null || echo ""
}

# Create or update a GitHub issue for a vulnerability
create_or_update_issue() {
    local dep_name="$1"
    local dep_type="$2"
    local advisories="$3"
    local installed_version="$4"
    local is_direct="$5"

    local dep_type_label
    local dep_type_display
    local dep_type_note=""

    if [ "$is_direct" = "true" ]; then
        dep_type_label="$ISSUE_LABEL_DIRECT"
        dep_type_display="Direct"
    else
        dep_type_label="$ISSUE_LABEL_TRANSITIVE"
        dep_type_display="Transitive"
        local required_by
        if [ "$dep_type" = "composer" ]; then
            required_by=$(find_requiring_composer_packages "$dep_name")
        else
            required_by=$(find_requiring_npm_packages "$dep_name")
        fi
        dep_type_note="
> **Note:** This is a transitive dependency required by: \`${required_by}\`
> Updating the parent package(s) may resolve this vulnerability."
    fi

    local title="[Security] ${dep_name} - Vulnerability detected"
    local body="## 🚨 Security Vulnerability in \`${dep_name}\`

**Dependency Type:** ${dep_type_display}
**Package Manager:** ${dep_type}
**Installed Version:** ${installed_version}
**Detected:** $(date -u +"%Y-%m-%d %H:%M UTC")
${dep_type_note}

### Advisories

${advisories}

---

### How to fix

"

    if [ "$dep_type" = "composer" ]; then
        body+="Run \`composer update ${dep_name} --with-dependencies\` to update to a patched version.

Check available versions: \`composer show ${dep_name} --all\`"
    else
        body+="Run \`npm update ${dep_name}\` or \`npm install ${dep_name}@latest\` to update to a patched version.

Check available versions: \`npm view ${dep_name} versions\`"
    fi

    body+="

---
*This issue is automatically managed by the security audit workflow.*"

    # Check for existing issue
    local existing=$(find_existing_issue "$dep_name" "$dep_type")

    if [ -n "$existing" ]; then
        local issue_number=$(echo "$existing" | jq -r '.number')
        local existing_body=$(echo "$existing" | jq -r '.body')
        local existing_labels=$(echo "$existing" | jq -r '.labels[]' 2>/dev/null || echo "")

        # Check if the content has changed (compare advisories section)
        if echo "$existing_body" | grep -q "$installed_version"; then
            echo -e "${YELLOW}  ↳ Issue #${issue_number} already exists and is up to date${NC}"
        else
            echo -e "${YELLOW}  ↳ Updating issue #${issue_number}${NC}"
            gh issue edit "$issue_number" --body "$body" --add-assignee "$ISSUE_ASSIGNEE"
            gh issue comment "$issue_number" --body "🔄 **Updated:** Vulnerability information has been refreshed. Installed version: ${installed_version}"
        fi
    else
        echo -e "${RED}  ↳ Creating new issue${NC}"
        gh issue create \
            --title "$title" \
            --body "$body" \
            --label "$ISSUE_LABEL" \
            --label "$dep_type" \
            --label "$dep_type_label" \
            --assignee "$ISSUE_ASSIGNEE"
    fi
}

# Create or update the single combined security issue for the drupal/core
# release group. meta_file has lines of "name|installed_version|is_direct",
# body_file has the pre-formatted advisories for each affected package.
create_or_update_core_group_security_issue() {
    local dep_type="$1"
    local meta_file="$2"
    local body_file="$3"

    local table_rows=""
    local has_direct="false"
    while IFS='|' read -r name installed_version pkg_is_direct; do
        [ -z "$name" ] && continue
        local dep_display="Transitive"
        if [ "$pkg_is_direct" = "true" ]; then
            dep_display="Direct"
            has_direct="true"
        fi
        table_rows="${table_rows}| \`${name}\` | ${installed_version} | ${dep_display} |
"
    done < "$meta_file"

    local dep_type_label="$ISSUE_LABEL_TRANSITIVE"
    [ "$has_direct" = "true" ] && dep_type_label="$ISSUE_LABEL_DIRECT"

    local title="[Security] ${CORE_GROUP_ISSUE_NAME} - Vulnerability detected"
    local body="## 🚨 Security Vulnerability in \`${CORE_GROUP_ISSUE_NAME}\`

These packages are versioned and released together, so they are tracked as a single security issue.

**Package Manager:** ${dep_type}
**Detected:** $(date -u +"%Y-%m-%d %H:%M UTC")

| Package | Installed Version | Dependency |
|---------|--------------------|------------|
${table_rows}
### Advisories

$(cat "$body_file")
---

### How to fix

Run \`composer update ${CORE_GROUP_PACKAGES[*]} --with-dependencies\` to update to a patched version.

Check available versions: \`composer show ${CORE_GROUP_ISSUE_NAME} --all\`

---
*This issue is automatically managed by the security audit workflow.*"

    local existing=$(find_existing_issue "$CORE_GROUP_ISSUE_NAME" "$dep_type")

    if [ -n "$existing" ]; then
        local issue_number=$(echo "$existing" | jq -r '.number')
        local existing_body=$(echo "$existing" | jq -r '.body')

        if [[ "$existing_body" == *"$table_rows"* ]]; then
            echo -e "${YELLOW}  ↳ Issue #${issue_number} already exists and is up to date${NC}"
        else
            echo -e "${YELLOW}  ↳ Updating issue #${issue_number}${NC}"
            gh issue edit "$issue_number" --body "$body" --add-assignee "$ISSUE_ASSIGNEE"
            gh issue comment "$issue_number" --body "🔄 **Updated:** Vulnerability information has been refreshed."
        fi
    else
        echo -e "${RED}  ↳ Creating new issue${NC}"
        gh issue create \
            --title "$title" \
            --body "$body" \
            --label "$ISSUE_LABEL" \
            --label "$dep_type" \
            --label "$dep_type_label" \
            --assignee "$ISSUE_ASSIGNEE"
    fi
}

# Close issues for dependencies that are no longer vulnerable
close_resolved_issues() {
    local dep_type="$1"
    local vulnerable_deps="$2"

    echo "🧹 Checking for resolved vulnerabilities (${dep_type})..."

    # Get all open security issues for this dep type
    gh issue list \
        --state open \
        --label "$ISSUE_LABEL" \
        --label "$dep_type" \
        --json number,title \
        --jq '.[] | "\(.number)|\(.title)"' 2>/dev/null | while read -r issue_line; do

        local issue_number=$(echo "$issue_line" | cut -d'|' -f1)
        local issue_title=$(echo "$issue_line" | cut -d'|' -f2-)

        # Extract dependency name from title "[Security] dep-name - Vulnerability detected"
        local dep_name=$(echo "$issue_title" | sed -n 's/\[Security\] \(.*\) - Vulnerability detected/\1/p')

        if [ -n "$dep_name" ]; then
            # Check if this dependency is still in the vulnerable list
            if ! echo "$vulnerable_deps" | grep -q "^${dep_name}$"; then
                echo -e "${GREEN}  ↳ Closing issue #${issue_number} - ${dep_name} is no longer vulnerable${NC}"
                gh issue close "$issue_number" --comment "✅ **Resolved:** This vulnerability has been addressed. The dependency is no longer flagged by security audit."
            fi
        fi
    done
}

# Run Composer audit
run_composer_audit() {
    echo ""
    echo "📦 Running Composer security audit..."

    local audit_output
    local audit_exit_code=0

    audit_output=$(composer audit --format=json 2>/dev/null) || audit_exit_code=$?

    if [ $audit_exit_code -eq 0 ]; then
        echo -e "${GREEN}  ✓ No vulnerabilities found in Composer dependencies${NC}"
        close_resolved_issues "composer" ""
        return 0
    fi

    # Parse the audit output
    local advisories_json=$(echo "$audit_output" | jq -r '.advisories // {}')

    if [ "$advisories_json" = "{}" ] || [ -z "$advisories_json" ]; then
        echo -e "${GREEN}  ✓ No vulnerabilities found in Composer dependencies${NC}"
        close_resolved_issues "composer" ""
        return 0
    fi

    echo -e "${RED}  ⚠ Vulnerabilities found, processing all dependencies...${NC}"

    # Clear temp files
    rm -f /tmp/vulnerable_composer_deps.txt /tmp/core_group_security_composer.txt /tmp/core_group_security_composer_body.md

    # Iterate through each package with advisories
    echo "$advisories_json" | jq -r 'keys[]' | while read -r package; do
        # Determine if direct or transitive
        local is_direct="false"
        if is_direct_composer_dep "$package"; then
            is_direct="true"
            echo -e "${RED}  → ${package} (direct dependency)${NC}"
        else
            echo -e "${MAGENTA}  → ${package} (transitive dependency)${NC}"
        fi

        # Get installed version
        local installed_version=$(composer show "$package" --format=json 2>/dev/null | jq -r '.versions[0] // "unknown"')

        # Format advisories for this package
        local package_advisories=$(echo "$advisories_json" | jq -r --arg pkg "$package" '.[$pkg][] | "- **\(.title // .cve // "Unknown")**\n  - CVE: \(.cve // "N/A")\n  - Affected versions: \(.affectedVersions // "N/A")\n  - Link: \(.link // "N/A")\n"')

        if is_core_group_package "$package"; then
            # Grouped packages are always released together; defer them to a
            # single combined issue instead of creating one per package.
            echo "$CORE_GROUP_ISSUE_NAME" >> /tmp/vulnerable_composer_deps.txt
            printf '%s|%s|%s\n' "$package" "$installed_version" "$is_direct" >> /tmp/core_group_security_composer.txt
            {
                echo "#### \`${package}\` (installed: ${installed_version})"
                echo ""
                echo "$package_advisories"
                echo ""
            } >> /tmp/core_group_security_composer_body.md
        else
            echo "$package" >> /tmp/vulnerable_composer_deps.txt
            create_or_update_issue "$package" "composer" "$package_advisories" "$installed_version" "$is_direct"
        fi
    done

    # Create/update the single combined security issue for the drupal/core group
    if [ -f /tmp/core_group_security_composer.txt ]; then
        create_or_update_core_group_security_issue "composer" /tmp/core_group_security_composer.txt /tmp/core_group_security_composer_body.md
        rm -f /tmp/core_group_security_composer.txt /tmp/core_group_security_composer_body.md
    fi

    # Close resolved issues
    if [ -f /tmp/vulnerable_composer_deps.txt ]; then
        close_resolved_issues "composer" "$(cat /tmp/vulnerable_composer_deps.txt)"
        rm -f /tmp/vulnerable_composer_deps.txt
    else
        close_resolved_issues "composer" ""
    fi
}

# Run bun audit
run_bun_audit() {
    echo ""
    echo "📦 Running Bun security audit..."

    cd "$THEME_ROOT"

    local audit_output
    local audit_exit_code=0

    audit_output=$(bun audit --json 2>/dev/null) || audit_exit_code=$?

    # bun audit returns non-zero if vulnerabilities found
    if [ $audit_exit_code -eq 0 ]; then
        echo -e "${GREEN}  ✓ No vulnerabilities found in Bun dependencies${NC}"
        cd - > /dev/null
        close_resolved_issues "npm" ""
        return 0
    fi

    # Parse vulnerabilities from bun audit JSON output
    local vuln_count=$(echo "$audit_output" | jq -r '
        if .metadata.vulnerabilities.total then .metadata.vulnerabilities.total
        elif .vulnerabilities then .vulnerabilities | keys | length
        else [.[] | arrays | .[]] | length
        end // 0
    ' 2>/dev/null)

    if [ "$vuln_count" -eq 0 ] 2>/dev/null; then
        echo -e "${GREEN}  ✓ No vulnerabilities found in Bun dependencies${NC}"
        cd - > /dev/null
        close_resolved_issues "npm" ""
        return 0
    fi

    echo -e "${RED}  ⚠ ${vuln_count} vulnerabilities found, processing all dependencies...${NC}"

    # Clear temp file
    rm -f /tmp/vulnerable_npm_deps.txt

    # Get all vulnerable packages - handle both npm-style and raw advisory formats
    local all_packages=$(echo "$audit_output" | jq -r '
        if .vulnerabilities then .vulnerabilities | keys[]
        elif .advisories then .advisories | .[].module_name
        else [.[] | arrays | .[].module_name // .[].name] | unique[]
        end
    ' 2>/dev/null | sort -u)

    echo "$all_packages" | while read -r package; do
        if [ -z "$package" ] || [ "$package" = "null" ]; then
            continue
        fi

        # Determine if direct or transitive
        local is_direct="false"
        if is_direct_npm_dep "$package"; then
            is_direct="true"
            echo -e "${RED}  → ${package} (direct dependency)${NC}"
        else
            echo -e "${MAGENTA}  → ${package} (transitive dependency)${NC}"
        fi

        # Track vulnerable dependency
        echo "$package" >> /tmp/vulnerable_npm_deps.txt

        # Get installed version from bun.lock
        local installed_version=$(bun pm ls 2>/dev/null | grep "$package@" | head -1 | grep -oE '@[0-9][0-9.]*' | head -1 | tr -d '@' || echo "unknown")
        if [ -z "$installed_version" ]; then
            installed_version="unknown"
        fi

        # Get advisory info - handle both formats
        local package_advisories=$(echo "$audit_output" | jq -r --arg pkg "$package" '
            if .vulnerabilities then
                .vulnerabilities[$pkg] |
                "- **Severity:** \(.severity // "unknown")\n- **Range:** \(.range // "N/A")\n- **Fix available:** \(.fixAvailable // "unknown")\n"
            elif .advisories then
                .advisories | to_entries[] | select(.value.module_name == $pkg) | .value |
                "- **\(.title // "Unknown")**\n  - Severity: \(.severity // "N/A")\n  - Vulnerable versions: \(.vulnerable_versions // "N/A")\n  - Recommendation: \(.recommendation // "N/A")\n  - URL: \(.url // "N/A")\n"
            else
                .[$pkg] // (.. | arrays | .[] | select(.module_name == $pkg or .name == $pkg)) |
                "- **\(.title // "Unknown")**\n  - Severity: \(.severity // "N/A")\n  - Vulnerable versions: \(.vulnerable_versions // "N/A")\n  - URL: \(.url // "N/A")\n"
            end
        ' 2>/dev/null)

        create_or_update_issue "$package" "npm" "$package_advisories" "$installed_version" "$is_direct"
    done

    cd - > /dev/null

    # Close resolved issues
    if [ -f /tmp/vulnerable_npm_deps.txt ]; then
        close_resolved_issues "npm" "$(cat /tmp/vulnerable_npm_deps.txt)"
        rm -f /tmp/vulnerable_npm_deps.txt
    else
        close_resolved_issues "npm" ""
    fi
}

# Main execution
main() {
    ensure_labels
    run_composer_audit
    run_bun_audit

    echo ""
    echo "✅ Security audit complete"
}

main
