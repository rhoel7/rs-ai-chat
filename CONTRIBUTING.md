# Contributing

Thanks for considering contributing. This is a small, focused project, keeping it that way is part of the point, so a few guidelines before opening a PR.

## Before you start

For anything beyond a small fix (a typo, a clear bug), open an issue first describing what you want to change and why. Saves everyone time if the direction doesn't fit the project.

## Setup

1. `cp api/config.example.php api/config.php`, fill in a database and at least one AI provider key (Groq or Mistral both have free tiers).
2. Run the schema files in `api/db/` and `api/rag/` against your database.
3. Run the test suite before you start, so you know what passing looks like on your machine: `php tests/run.php` and `node tests/js/markdown.test.js`.

## Making changes

- **Run the tests before opening a PR.** If you added a feature that's meaningfully testable (most things touching `api/` are), add a test for it. `tests/RagIngestorTest.php` and `tests/RagRetrieverTest.php` are reasonable examples of the style used here: real assertions against a real test database, not mocked-out unit tests that only prove the code compiles.
- **No new Composer dependencies** without discussing it first in an issue. Part of this project's value is running on plain shared hosting with zero `composer install` step. A new dependency is a real cost against that.
- **Match the existing comment style.** Comments here tend to explain *why* a decision was made, not just what the code does, especially for anything security- or correctness-related. Keep that up.
- **Security-sensitive changes** (anything touching `api/db/MessageCipher.php`, `api/admin.php`, `.htaccess` files, or rate limiting) get held to a higher bar. Explain your reasoning in the PR description, not just the diff.

## What's likely to get a quick yes

- Bug fixes with a test proving the bug existed and is now fixed
- Documentation improvements
- New AI provider support, if the provider has an OpenAI-compatible API, this is usually a small, contained addition

## What needs more discussion first

- New Composer dependencies
- Changes to the encryption scheme or database schema
- Anything that would require WordPress, WooCommerce, or another CMS-specific assumption to be baked into `api/` — that layer is deliberately framework-agnostic

## Reporting bugs

Open an issue with what you did, what you expected, and what actually happened. If it's a security issue, see `SECURITY.md` instead, don't open a public issue for it.
