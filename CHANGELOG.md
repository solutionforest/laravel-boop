# Changelog

All notable changes to `solutionforest/laravel-boop` will be documented in this file.

## [Unreleased]

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
