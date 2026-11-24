# Changelog

## Unreleased

### Added

- JavaScript errors, unhandled promise rejections and resources that fail to load are tracked through the JavaScript tracker.
- The report "Errors", with the latest occurrences and stack traces of each error.
- The reports "Errors over time" and "Errors by browser", and the browser and operating system of each occurrence.
- The reports are archived, so they work with segments, several periods and row evolution.
- A setting for each website to turn error tracking off, and a system setting for how many days errors are kept.
- Content Security Policy violations are tracked, and browser warnings (deprecations and interventions) for a website that turns them on.
- A snippet for the page collects the errors that happen before the tracker is loaded.
- A setting for each website with texts of errors to ignore.
- An error that happens before the tracker knows its website waits for it, as with a tag manager.
