# Contributing to Double Youpï Refined Media Library

Thank you for taking the time. Here is how this repository works, so your time is well spent.

## How this repository works

This repository is a **mirror of the releases published on WordPress.org**. Development happens in a private repository, and each release is exported here as a single commit. Two consequences:

- A pull request cannot be merged as is. When one is accepted, the change is ported into the main code by hand, ships in the next release, and the pull request is closed with a link to that release.
- Every accepted contribution is credited by name in the changelog of the release that ships it.

## Before you start

- **Support questions** go to the [support forum on WordPress.org](https://wordpress.org/support/plugin/doubleyoupi-refined-media-library/), not to the issues.
- **Ideas and feature requests:** open an issue first and describe the problem you want solved, before writing code. The plugin is deliberately opinionated: fewer settings, better defaults. A change that adds a setting needs a strong reason.
- **Translations** are not done here. They go through [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/doubleyoupi-refined-media-library/).
- **Security problems:** never in a public issue or pull request. See [SECURITY.md](SECURITY.md).

## Reporting a bug

Use the bug report form. The more of these you include, the faster it gets fixed:

- WordPress, PHP and plugin versions, and whether the site is a multisite;
- the steps to reproduce, from a fresh page load;
- what you expected, and what happened instead;
- any red message in the browser console (F12) and in `wp-content/debug.log`;
- the other plugins and the theme involved, if the bug only happens with them.

## Proposing a change

- Keep it small and focused on one problem.
- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) and target PHP 8.1 and WordPress 6.5.
- Escape all output, check nonces and capabilities on every action, and never write raw SQL.
- Every name we register or store is prefixed with `doubleyoupi_` (options, hooks, AJAX actions, meta keys) or `doubleyoupi-` (script and style handles). CSS classes use `dy-`.
- User-facing strings are in English and go through the WordPress translation functions.
- Stylesheets: edit the SCSS under `assets/scss/` when a source exists. Some stylesheets (login, developer mode, compatibility files) are plain CSS. The compiled CSS may differ slightly from your local build depending on your Sass version; that is expected.
- Describe how you tested it: which screens, light and dark, and which browser.

## License

By contributing, you agree that your contribution is released under the GPLv2 or later, like the rest of the plugin.
