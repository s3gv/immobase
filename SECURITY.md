# Security Policy

## Reporting a vulnerability

**Please do not report security vulnerabilities in public issues, pull requests or discussions.**

Use GitHub's private vulnerability reporting instead: go to the **Security** tab of this repository and choose **Report a vulnerability**. That channel is private between you and the maintainer, requires no email address, and keeps the report attached to the repository.

This project deliberately publishes no email address, so private vulnerability reporting is the only confidential channel. If it is unavailable to you, open a **public issue containing no details** — just say that you have a security report and cannot use private reporting — and the maintainer will arrange a private channel with you from there. Do not describe the vulnerability in that issue.

### What to include

- What the problem is and roughly how severe you think it is
- Steps to reproduce, or a proof of concept
- The version, commit or branch you tested
- Whether the issue is already public anywhere

### What to expect

This is a single-maintainer project without a paid support commitment, so please treat the following as intent rather than a guarantee:

- Acknowledgement within a few days
- An assessment and a rough plan once the report is understood
- Credit in the release notes, unless you prefer not to be named

### Scope

ImmoBase is **self-hosted**. There is no service operated by the maintainer, no hosted instance and no central infrastructure to attack. Reports should concern the ImmoBase source code, its container images, or its installation tooling.

Findings against somebody else's ImmoBase installation belong to whoever runs it, not here.

## Supported versions

ImmoBase is currently being rebuilt from scratch and has no release yet. Until the first release, only the default branch is supported.
