# License header policy

Every source file in this repository carries two SPDX lines at the very top, in
the comment syntax of its language:

```php
<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv
```

```twig
{# SPDX-License-Identifier: AGPL-3.0-or-later #}
{# SPDX-FileCopyrightText: 2026 s3gv #}
```

```yaml
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 s3gv
```

## Rules

**Two lines, not the full license text.** `LICENSE` and `NOTICE` in the
repository root are authoritative. The SPDX lines only point at them in a
machine-readable way. A twelve-line GPL boilerplate block at the top of every
file is noise nobody reads.

**`AGPL-3.0-or-later`, never `AGPL-3.0-only`.** `or-later` means the project is
not stranded if the FSF publishes a new AGPL version.

**The copyright year is the year the file was created.** Do not update it on
every edit and do not maintain year ranges. Git already records who changed
what and when; a hand-maintained year range only ever goes stale.

**Contributors do not add their own name to the header.** Contributors keep the
copyright in their contributions — see [CLA.md](../../CLA.md) — but the header
names the party that licenses the combined work outward. Authorship is recorded
in git history, which is the accurate record.

## Which files

Applies to: PHP, Twig, JavaScript, CSS, YAML configuration, shell scripts, SQL
migrations.

Does not apply to: generated files, lock files (`composer.lock`,
`package-lock.json`), vendored third-party code, fixture data files, Markdown
documentation, and files whose format has no comment syntax (`.json`).

Third-party files keep their original headers untouched.

## Why this matters beyond bookkeeping

This is groundwork for the plugin boundary.

ImmoBase is to grow an plugin ecosystem in which third parties build
commercially licensed extensions against an AGPLv3 core. When that happens,
plugin files will carry a different `SPDX-License-Identifier`. The license
boundary between core and plugin then becomes **visible in the file system and
checkable by a machine**, rather than living only in an architecture document
that drifts out of date.

Getting the header in place before the first source file exists is far cheaper
than retrofitting it across a codebase later.

## Enforcement

Not enforced yet — at the time of writing there is no source code to check.

A CI check that rejects missing or malformed headers is part of the tooling set
up alongside the first application code (phase 4 of the rebuild). Until then
this document is the rule.
