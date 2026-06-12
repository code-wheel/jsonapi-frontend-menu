# Security Policy

## Reporting a vulnerability

Please do **not** open public issues or pull requests for security vulnerabilities.

Preferred: report privately using GitHub Security Advisories for this repository.

If private reporting is not available for you, contact the maintainers via the Drupal.org project page and clearly indicate that the report is security-sensitive.

## What to include

- A clear description of the vulnerability and impact
- Steps to reproduce (or a proof of concept)
- Affected versions (Drupal core version, module version, and relevant config)
- Any suggested mitigation or fix (if you have one)

## Endpoint access model

`/jsonapi/menu/{menu}` is a **public** endpoint (`_access: TRUE`), the same
pattern Drupal's JSON:API core uses. Authorization is not skipped — every
menu link is filtered by its own access result, so links the current user
cannot reach (e.g. admin routes) are omitted from the response for anonymous
callers. This is covered by `MenuEndpointTest::testMenuActiveTrailAndResolve`,
which asserts an admin-only link is absent.
