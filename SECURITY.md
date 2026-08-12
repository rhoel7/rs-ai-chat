# Security Policy

## Reporting a vulnerability

**Please don't open a public GitHub issue for security vulnerabilities.** Use GitHub's private vulnerability reporting instead: go to the **Security** tab on this repo → **Report a vulnerability**. That opens a private conversation with the maintainer, nothing public until it's resolved.

If that's not available to you for some reason, reach out via [LinkedIn](https://www.linkedin.com/in/rhoel/) instead of a public channel.

Please include:
- What you found and where (which file, which endpoint)
- Steps to reproduce
- What you think the actual impact is (what could someone do with this)

## What's actually in scope

This project's own code: encryption (`api/db/MessageCipher.php`), rate limiting, the admin authentication in `api/admin.php`, the `.htaccess` access controls, and the markdown/XSS handling in `chat.js`.

**Not in scope**: vulnerabilities in the underlying AI provider APIs themselves (Groq, Mistral, Gemini, OpenAI, Claude, DeepSeek, Azure), those belong to their respective vendors. Vulnerabilities in WordPress, WooCommerce, or your hosting environment also aren't this project's responsibility, though a report about how this project interacts badly with one of those is genuinely useful.

## Response expectations

This is a small, currently single-maintainer open-source project, not a company with an SLA. A genuine best effort will be made to acknowledge reports promptly and fix real vulnerabilities quickly, but there's no contractual response-time guarantee.

## Known, deliberate design tradeoffs, not vulnerabilities

A few things that might look like issues but are documented, intentional tradeoffs, covered in more detail in the README's "Security model" section:

- The encryption key lives in `config.php` on the same server as the database it protects. This defends against database-only exposure, not full server compromise.
- Rate limiting is IP- and cookie-based, appropriate for a small deployment or demo, not a substitute for real authentication at larger scale.
- Removing a row from a RAG CSV does not delete it from the knowledge base, by design, to prevent accidental data loss from a trimmed file.

If you believe one of these tradeoffs is actually exploitable in a way the README doesn't account for, that's still worth reporting, the reasoning might be wrong or incomplete.
