# JSON:API Frontend Menu

[![Drupal Module](https://github.com/code-wheel/jsonapi-frontend-menu/actions/workflows/drupal-module.yml/badge.svg?branch=master)](https://github.com/code-wheel/jsonapi-frontend-menu/actions/workflows/drupal-module.yml?query=branch%3Amaster) [![Semgrep](https://github.com/code-wheel/jsonapi-frontend-menu/actions/workflows/semgrep.yml/badge.svg?branch=master)](https://github.com/code-wheel/jsonapi-frontend-menu/actions/workflows/semgrep.yml?query=branch%3Amaster) [![codecov](https://codecov.io/gh/code-wheel/jsonapi-frontend-menu/branch/master/graph/badge.svg)](https://codecov.io/gh/code-wheel/jsonapi-frontend-menu) [![Security Policy](https://img.shields.io/badge/security-policy-blue.svg)](SECURITY.md)

`jsonapi_frontend_menu` is an optional add-on for `jsonapi_frontend` that exposes a ready-to-render menu tree with:

- Role-aware access filtering (uses Drupal menu access checks).
- Optional active trail from a provided `path`.
- Per-item `resolve` hints (headless + `drupal_url` / `jsonapi_url` / `data_url`).

Main module: https://www.drupal.org/project/jsonapi_frontend

## Endpoint

```
GET /jsonapi/menu/{menu}?path=/about-us&_format=json
```

- `menu`: the menu machine name (example: `main`).
- `path` (optional): the current request path to compute active trail.
- `langcode` (optional): forwarded to the resolver.
- `resolve` (optional): set to `0` to skip resolver decoration.

## Response

Top-level `data` is an array of menu items. Each item includes a nested `children` tree and an optional `resolve` object that matches the
shape returned by `/jsonapi/resolve`.

Tip: For maximum cache reuse, call the endpoint without `path` and compute active trail client-side.

## Install

```
composer require drupal/jsonapi_frontend_menu
drush en jsonapi_frontend_menu
```
