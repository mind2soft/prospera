# Security Policy

## Supported versions

Prospera is a personal project distributed as source code. Only the latest
version on the `main` branch is supported. Security fixes are applied to
`main` and released as new commits — no long-term support branches are
maintained.

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Report vulnerabilities privately through
[GitHub's private vulnerability reporting](../../security/advisories/new)
for this repository. This is the preferred channel, and notifications are
sent directly to the maintainers.

If private reporting is unavailable for any reason, contact the copyright
holder directly (see [README.md](README.md)) and mention "security
vulnerability" in the subject line.

## What to include

To help triage and fix the issue quickly, please include:

- A description of the vulnerability and its impact;
- The affected component (backend PHP, frontend JS/TS, build pipeline, etc.);
- Step-by-step instructions or a proof of concept to reproduce it;
- Any suggested fix or mitigation, if you have one.

## Response policy

- You will receive an acknowledgment within **7 days**;
- Confirmed vulnerabilities will be fixed and disclosed as soon as
  reasonably possible, with credit to the reporter if desired;
- Please keep reports confidential until a fix is published.

## Scope

In scope: this repository's own source code — the Laravel backend, the Vue
frontend, build tooling, and CI workflows.

Out of scope: vulnerabilities in third-party dependencies (report those
upstream; the project follows Dependabot updates), issues requiring access
to a private deployment's environment, and anything outside this codebase.
