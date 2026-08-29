# Changelog

All notable changes to `solution-forest/laravel-boop` will be documented in this file.

## [Unreleased]

### Changed

- Composer package renamed to `solution-forest/laravel-boop` (namespace unchanged, `SolutionForest\Boop`).
- `composer.json` now declares `homepage`, `support` (issues + source), `authors` and expanded `keywords`.
- `sendAsync()` captures the event title before validation, so the never-throw guarantee holds even if dispatch itself throws.
- `max_retries` documentation clarified: it is the total attempt count passed to `Http::retry()` (2 = 1 initial + 1 retry), not retries-after-first.

### Added

- README "What is Boop" section (self-hosted notification inbox; what it replaces).
- README "Quick start" section + `docker-compose.boop.yml` for a local dev Boop instance.
- README note on per-call `overrides` for `sendAsync()`.

### Added

- Initial release. Boop client for Laravel:
  - `Boop::send()` — blocking HTTP to `POST /api/v1/events`, never throws.
  - `Boop::sendAsync()` — fire-and-forget via `dispatchAfterResponse()`, never throws.
  - `Boop::healthy()` — `GET /health` reachability check.
  - `Event` value object — validate/normalise/truncate/redact, wire payload builder.
  - `Redactor` — recursive redaction of sensitive keys (default list + configurable extras).
  - `Level` enum — `info`, `success`, `warning`, `error`, `critical`.
  - `BoopError` — shared error taxonomy, `isRetryable()`.
  - `Result` — `ok` / `disabled` / `failure` outcomes.
  - `SendEvent` job — queue-backed alternative for async sends.
  - Retries (network + 5xx only, jittered backoff, never 4xx).
  - Config + facade + service provider with auto-discovery.
