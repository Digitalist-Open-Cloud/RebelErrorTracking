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
- The report "Errors" shows the browsers each error happened most in.
- The segments "Error type", "Error message" and "Error file", to narrow any report to the visits with an error.
- The visit log shows the errors of a visit.
- When an error was first seen, a mark on the errors that are new in the period, and the number of new errors.
- The error rate: the share of visits with an error.
- The report "Errors by page" and the numbers of the period as "Errors in numbers".
- The time of an occurrence links to its visit in the visit log.
