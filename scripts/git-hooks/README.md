# Git hooks

Version-controlled hooks for this repository. Install once per clone:

```bash
make install-hooks
```

Or manually:

```bash
git config core.hooksPath scripts/git-hooks
```

## pre-commit

Scans staged files for likely secrets (API keys, tokens, private keys, connection strings).

## pre-push

1. **`make quality`** — same PHPCS, PHPStan, and naming checks as [GitHub Actions](../../.github/workflows/quality.yml).
2. On branch **`dev`** only: **`make dev`** — build and push to the private registry.

Bypass in an emergency: `git push --no-verify` (not recommended).

Uninstall (restore default `.git/hooks`):

```bash
git config --unset core.hooksPath
```
