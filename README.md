# Rebel Error Tracking

[![PHPCS check](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpcs.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpcs.yaml)
[![Semgrep OSS scan](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/semgrep.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/semgrep.yaml)
[![Test plugin with Matomo](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/matomo.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/matomo.yaml)
[![PHPStan check](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpstan.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelErrorTracking/actions/workflows/phpstan.yaml)

A Matomo plugin that tracks the errors that happen in the browsers of your visitors:

- JavaScript errors, with file, line, column and stack trace.
- Promises that are rejected without being handled.
- Images, scripts and stylesheets that fail to load.
- Violations of the Content Security Policy of the website.
- Optionally, what the browser reports about the website: deprecated features it uses, and
  things the browser stopped it from doing.

## Install

Activate the plugin. It adds itself to the JavaScript tracker, `matomo.js`, so no change to the
tracking code of a website is needed. If Matomo can not write `matomo.js`, run:

```sh
./console custom-matomo-js:update
```

A website that loads the tracker through Matomo Tag Manager is covered too.

## Errors before the tracker is loaded

The tracker is loaded late, so an error thrown before it is not seen. To catch those too, put
this first in the `<head>` of the website:

```html
<script>
window.rebelErrorQueue = [];
addEventListener('error', function (e) { rebelErrorQueue.push(e); }, true);
addEventListener('unhandledrejection', function (e) { rebelErrorQueue.push(e); });
addEventListener('securitypolicyviolation', function (e) { rebelErrorQueue.push(e); });
</script>
```

The tracker sends what the snippet collected when it is loaded.

## Reports

**Errors** in the reporting menu has three reports for the chosen website and period:

- **Errors over time**: the number of errors, or of visits with an error, as a graph.
- **Errors**: one row for each error, with how many times it happened, in how many visits, and
  when it was last seen. Errors that differ only in numbers and IDs count as the same error.
  Click a row to see its latest occurrences: the time, the page, the browser and operating
  system, the place in the code and the stack trace.
- **Errors by browser**: the browsers and versions the errors happened in.

The browser comes from the visit an error belongs to, so the reports can also be narrowed with a
segment, such as one browser, one country or one page.

The reports are built when Matomo archives, like its other reports: an error shows up the next
time the reports of the day are processed.

In the API they are `RebelErrorTracking.get`, `getErrors`, `getOccurrences` and `getBrowsers`.
The occurrences are read from the stored errors: they do not follow a segment, and they are gone
when the errors are deleted, while the counts stay.

## Settings

For each website, under its settings:

- **Track errors**. On by default.
- **Track browser warnings**. Off by default: Chromium-based browsers report a deprecated
  feature for every page view that uses it.
- **Errors to ignore**. One text on each line; an error whose message or file contains one of
  them is not stored.

For all websites:

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
