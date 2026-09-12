# Whole-branch review final fixes

- Exempted both signed platform-mail unsubscribe routes from the global ops IP allowlist.
- Changed per-site push jobs to `ShouldBeUniqueUntilProcessing`, so duplicate queued work is suppressed while a newer save can enqueue once processing starts.
- Made failed platform-mail sync results log safe site/status metadata and throw, surfacing the queue job as failed.
- Added focused coverage for public signed unsubscribe, unique-lock semantics, and failed sync handling.
- Verification: `php artisan test --filter=PlatformMail` — 16 passed, 53 assertions.
