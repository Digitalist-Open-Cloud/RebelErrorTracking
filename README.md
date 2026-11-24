# Rebel Error Tracking

[![PHPCS check](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpcs.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpcs.yaml)
[![Semgrep OSS scan](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/semgrep.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/semgrep.yaml)
[![Test plugin with Matomo](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/matomo.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/matomo.yaml)
[![PHPStan check](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpstan.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpstan.yaml)

A Matomo plugin that tracks the errors that happen in the browsers of your visitors:

- JavaScript errors, with file, line, column and stack trace.
- Promises that are rejected without being handled.
- Images, scripts and stylesheets that fail to load.

## Install

Activate the plugin. It adds itself to the JavaScript tracker, `matomo.js`, so no change to the
tracking code of a website is needed. If Matomo can not write `matomo.js`, run:

```sh
./console custom-matomo-js:update
```

A website that loads the tracker through Matomo Tag Manager is covered too.

## Reports

**Errors** in the reporting menu lists the errors of the website for the chosen period. The same
error is one row, with how many times it happened, in how many visits, and when it was last
seen. Errors that differ only in numbers and IDs count as the same error.

Click a row to see its latest occurrences: the time, the page, the place in the code and the
stack trace.

The reports are also in the API, as `RebelErrorTracking.getErrors` and
`RebelErrorTracking.getOccurrences`. Segments are not supported.

## Settings

- **Track errors**, for each website under its settings. On by default.
- **Days to keep errors**, under the system settings of the plugin. Older errors are deleted by a
  daily task.

## What is sent and stored

- An error is sent once for each page load, and at most ten errors for each page load. A visit
  stores at most a hundred.
- Errors from browser extensions are not sent.
- Addresses are stored without what follows `?` and `#`.
- Errors go through the Matomo tracker, so consent, opt-out and the exclusions of the website
  apply. They are deleted with the visit they belong to.
- An error does not count as a page view or an action.

## Errors from scripts on other domains

A browser reports only `Script error.` for a script from another domain. To get the real error,
load the script with `crossorigin="anonymous"` and serve it with an
`Access-Control-Allow-Origin` header.

## Tests

```sh
./console tests:run RebelErrorTracking
deno test --allow-read tests/js
```

## License

GPL v3 or later, see [LICENSE.md](LICENSE.md).
