# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Send a private report with:

- a short description of the issue and its impact,
- reproduction steps (request/endpoint/params),
- affected version (`APP_VERSION` in `app/bootstrap.php`) and PHP version,
- any suggested fix.

You can reach the maintainers through the repository's *Security advisories* tab
(GitHub → Security → Report a vulnerability). We aim to acknowledge within 72 hours
and to publish a fix within 14 days for confirmed issues.

## Scope

In scope:

- authentication / OTP bypass in the admin panel,
- SQL injection, XSS, CSRF, SSRF, path traversal,
- privilege escalation between `admin` and `owner`,
- secret leakage (bot token, API key, OTP),
- webhook authentication bypass.

Out of scope:

- issues that require a compromised server or database,
- denial of service through intentionally huge uploads beyond documented limits,
- problems caused by running with `APP_DEBUG=true` in production,
- reports produced solely by automated scanners without a working proof of concept.

## Hardening checklist for operators

See [`docs/SECURITY.md`](docs/SECURITY.md). The most important items:

1. Serve only the `public/` directory; keep `.env` and `storage/` outside the web root.
2. Use HTTPS and a valid certificate (webhook and secure cookies depend on it).
3. Keep `require_2fa` enabled and deliver OTP codes through Bale.
4. Restrict the admin panel with `ip_allowlist` when the operator IP is static.
5. Rotate the bot token and `APP_KEY` after any suspected exposure.
6. Back up MySQL daily and keep the audit log.
