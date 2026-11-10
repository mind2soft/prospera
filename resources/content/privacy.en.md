---
last-updated: 2026-11-09
---

> **Work in progress.** This policy is a living document: it will evolve as
> the service and its security tighten. Material changes will be reflected in
> the "Last updated" date above.

Prospera handles sensitive financial information. This page explains, in
plain terms, what data the application collects, how it is stored and
protected, how long it is kept, and what happens to it when an account is
deleted.

> **Not legal advice.** This document describes how Prospera is built and
> behaves today; it is not a legal opinion or a substitute for one.

## Your data belongs to you

Prospera does **not** use your data for analytics and does **not** sell or
share it with third parties.

The browser footprint is intentionally minimal:

- a **login session cookie**, required to keep you signed in (CSRF and
  session protection);
- a **`localStorage` entry** on your device, used only to remember which
  financial profile you last selected and your interface preference
  (light/dark theme).

No advertising trackers, no telemetry, no third-party analytics scripts.

## What is collected

### Your account

Creating an account requires a **name**, an **email address** and a
**password** (optionally a passkey, two-factor secret and recovery codes, and
a preferred language). The email address is used for authentication,
account verification, security notices and the notifications you explicitly
enable.

### Financial data

Everything you enter — financial profiles, envelopes, budgets, ledgers,
transactions, starting balances and any other money amounts — is stored
for the sole purpose of providing the service to you and to the members you
share a financial profile with.

### Server-side technical data

When you use the **contact form**, we log the submission so it can be
answered and abuse can be limited:

- the **email address** and **subject** you provide;
- your **message**, stored encrypted at rest;
- the **IP address** the request came from, used to rate-limit the form and
  enforce its per-IP cooldown.

Contact submissions are deleted after a configurable retention window
(90 days by default). IP addresses are also processed transiently by the web
server and by rate limiters for abuse protection.

## How your financial data is protected

**Encryption at rest.** All financial amounts — ledger transactions, budget
amounts and starting balances — are stored
**encrypted** with an encryption key **unique to each financial profile**.
Amounts are only ever decrypted server-side, after permission checks, to
serve them to people you granted access to. Keys are rotated regularly and
are never shared with clients. For the technical details, see the
[encryption at rest](https://github.com/mind2soft/prospera/blob/main/docs/encryption.md)
design document.

**Permission model.** Sharing is explicit: members of a financial profile see
its budgets and ledgers, and finer-grained viewer/contributor permissions can
be granted per resource. Users without even read permission on an envelope
never receive its amounts — the server masks them before responding.

**Your credentials are your responsibility.** You are responsible for your
own credentials and authentication methods (password, two-factor
authentication, passkeys) and for keeping them confidential. Prospera cannot
be held liable for a stolen identity resulting from compromised credentials.

**Data breaches.** In the event of a data breach affecting the database,
users will be informed.

## How long data is kept

Financial records do not outlive their purpose. The rules below are the
retention policy the service commits to:

- a **ledger**, with the budgets and transactions tied to its covered period,
  is kept until **7 years after the ledger's end date**, after which it is
  deleted (for example, a ledger ending December 31, 2026 is deleted at the
  start of 2034);
- a **published budget** is kept until **7 years past its effective month**,
  and a budget draft **7 years after its creation**;
- removal is performed at the application level — the amounts can only be
  interpreted in-app — so a record is either fully present or fully gone,
  never partially deleted.

Envelopes and scheduled transactions are not bound to a period and are not
auto-deleted; they are removed with their owner's account, as described
below.

> This retention period is a policy decision and is subject to change; this
> page is where the current rule is published.

## When your account is deleted

When you delete your account:

- every financial profile that is left without any remaining member —
  including all of its ledgers, budgets and transactions — is deleted;
- every shared profile that still has other members **keeps its data**:
  ownership passes to the **earliest-joined remaining member** (ties broken
  by the lowest user id), matching the documented ownership-transfer rules.

**You are responsible for revoking sharing permissions before deleting your
account** if you do not want a financial profile to keep existing — and be
transferred — without you.

## Placeholders — to be completed

The following sections are intentionally pending and will be added as the
service matures:

- the **data controller** identity and contact details;
- the **hosting / processing location**;
- the applicable **jurisdictions** and regimes (e.g. Canadian PIPEDA and
  Quebec's Law 25; GDPR-style rights for users in the EU);
- a summary of **your rights**: the data you can obtain, correct and delete,
  and how to exercise those rights.
