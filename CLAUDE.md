# Project Notes

## Development Environment

This project uses **DDEV** for local development.

## Environment Configuration

The Hardcover GraphQL API needs a token. `web/sites/default/settings.php` reads
it from the `HARDCOVER_API_TOKEN` environment variable:

```php
$settings['hardcover_api_token'] = getenv('HARDCOVER_API_TOKEN') ?: '';
```

Override it in `web/sites/default/settings.local.php` (git-ignored) or export
the variable. Without a token every lookup logs a warning and returns no data,
so a book sync appears to run and changes nothing. Never commit the token.

## Custom DDEV Commands

### Drush

Use `ddev drush` instead of `vendor/bin/drush`:

```bash
ddev drush cr      # Clear cache
ddev drush updb    # Run database updates
ddev drush cex     # Export config
ddev drush cim     # Import config
```

### Theme (books)

The theme is located at `web/themes/custom/books/` and uses Vite + Tailwind CSS v4 with **Bun** as the package manager.

**Important:** Run bun commands inside DDEV (not on the host) to ensure correct native binaries.

```bash
ddev books:build          # Build the theme
ddev books:watch          # Watch mode (dev server)
ddev books:bun <command>  # Run any bun command in theme directory
ddev books:bunx <command> # Run any bunx command in theme directory
ddev books:node <command> # Run any node command in theme directory
```

If you get rollup errors about missing native modules, reinstall inside DDEV:

```bash
rm -rf web/themes/custom/books/node_modules web/themes/custom/books/bun.lock
ddev books:bun install
```

### Code Quality

```bash
ddev phpcs [path]    # Run PHP CodeSniffer
ddev phpcbf [path]   # Run PHP Code Beautifier and Fixer
ddev phpstan [args]  # Run PHPStan static analysis
ddev phpunit [args]  # Run PHPUnit tests
```

### Testing

```bash
ddev cy:install      # Install Cypress test dependencies
ddev cy:test         # Run Cypress tests
```

### Sync & Tools

```bash
ddev project:sync @project.prod   # Sync local from remote (db + files)
ddev browsersync                  # Run BrowserSync proxy on port 3000
```

## Issue Workflow

When fixing a GitHub issue, always follow this process:

1. **Create a new branch** from `origin/main` (e.g., `fix/issue-42-null-safety`).
2. **Commit often** as you work — small, focused commits are preferred.
3. **Once everything is committed**, open a Pull Request against `main`.
