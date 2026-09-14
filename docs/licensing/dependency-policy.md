# Dependency license policy

ImmoBase is AGPLv3 and follows an open-core model: commercial licenses are
intended to be offered alongside the open source version, including licenses
under which a customer may build and keep closed-source derivatives.

**That plan is not limited by the Contributor License Agreement. It is limited
by our dependencies.** A single strong-copyleft package in the core makes the
commercial license unsellable, because we cannot grant rights to code we do not
own and whose license forbids proprietary redistribution.

This document is the rule that keeps that from happening by accident.

## The rule

**Runtime dependencies of the core must be permissively licensed.**

| Verdict | Licenses |
|---|---|
| **Allowed** | MIT, BSD-2-Clause, BSD-3-Clause, Apache-2.0, ISC, Unlicense, CC0 |
| **Allowed** | Multi-licensed packages that offer a permissive option — e.g. `BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only`, where the permissive option may simply be chosen |
| **Review first** | MPL-2.0 and other file-level copyleft. Combining with proprietary code is permitted, but the MPL files themselves stay MPL. Acceptable for a self-contained library, not for something we expect to patch. |
| **Not in the core** | GPL, AGPL, LGPL, EUPL, OSL, CDDL, SSPL, BUSL, "commons clause" variants, and any package whose license is unclear or missing |

LGPL sits in the "not in the core" row deliberately. LGPL permits use by
proprietary software on the condition that the library remains replaceable by
the user — a condition written for dynamic linking, which PHP does not have in
that sense. Reasonable lawyers disagree about what LGPL means for a Composer
package. We do not want a commercial license to rest on that disagreement.

## Data and media assets

The table above is about code. Data files, fonts, icons and images follow a related but separate
rule: **attribution licenses are fine, copyleft and non-commercial licenses are not.**

| Verdict | Licenses |
|---|---|
| **Allowed** | CC0, CC BY 4.0, Datenlizenz Deutschland Namensnennung 2.0 (dl-de/by-2-0), dl-de/zero-2-0, OFL (fonts), MIT-licensed icon sets |
| **Not allowed** | CC BY-SA and other ShareAlike variants, CC NC (non-commercial), and anything without a stated license |

ShareAlike is the trap here. Most German state outlines and map graphics on Wikipedia are CC BY-SA,
which would impose ShareAlike obligations on a commercial licensee — at the most visible surface of
the product.

**Currently in use:** the state outlines used as the application background derive from the BKG
VG250 dataset under dl-de/by-2-0. Attribution is required and lives in `NOTICE` and in the
application's about screen, together with a note that the data has been modified. Attribution-only,
no copyleft — a closed-source licensee only has to carry the source credit.

**Development dependencies are exempt.** Anything under `require-dev` is not
shipped to users and not part of the distributed work. Test and static analysis
tooling may be licensed however it likes.

## When you need a copyleft library anyway

Sometimes the only good tool for a job is copyleft. The escape hatch is the same
architectural boundary the project builds for plugins:

**Run it as a separate process and talk to it over a network API.**

A separate program that we invoke over HTTP is not linked into our work. It is
distributed as its own thing, under its own license, and our code is not a
derivative of it. This is the same reasoning that lets a proprietary
application shell out to `grep`.

What this requires, to actually hold up:

- A real process boundary — HTTP or another network protocol, not a PHP
  library call, not a shared autoloader, not an `exec()` of code we bundled
- No shared data structures or headers, only a documented wire format
- The copyleft component distributed as its own container image or package,
  with its own license text intact, replaceable by the operator

### The concrete case: PDF rendering

This is not hypothetical. Nearly every PHP PDF library is copyleft — `dompdf`
is LGPL, `mpdf` is GPL-2.0, `tcpdf` is LGPL — and PDF output is not optional
for property management software that produces statements and dunning letters.

The audit of the reference implementation found exactly this: `dompdf` and its
two support packages under LGPL, plus `enshrined/svg-sanitize` under
GPL-2.0-or-later, which is strong copyleft and would block a closed-source
derivative outright.

So PDF rendering in the rebuild belongs behind the process boundary — a
rendering service the application calls over HTTP, not a library it imports.
That decision is made in phase 3 alongside the rest of the plugin architecture;
this document records why it cannot be decided the other way.

## Enforcement

Not enforced yet — there are no dependencies, because there is no code.

A CI check belongs in the tooling set up with the first application code
(phase 4): run a license check over `composer.lock` and the npm lockfile, fail
the build on anything outside the allowed set, and require an explicit
exception entry for anything in the "review first" row.

Until then this document is the rule, and every `composer require` should be
checked against it by hand.

## Adding an exception

If a package really has to come in despite this policy:

1. Say what it does and why no permissively licensed alternative works.
2. Say whether it can live behind the process boundary instead.
3. If it genuinely cannot, record the decision and accept that the commercial
   license offering is constrained by it — and say so in writing to anyone
   buying such a license.

Exceptions are recorded in this file, not in a commit message.

**Current exceptions:** none.
