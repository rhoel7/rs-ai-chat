<?php
/**
 * Deletes chat history for visitors inactive for 30+ days.
 *
 * Set this up as a Hostinger Cron Job (hPanel → Advanced → Cron Jobs),
 * running once daily is plenty. Example command Hostinger will run:
 *   php /home/YOUR_USERNAME/domains/reunifystudios.com/public_html/wp-content/plugins/reunify-ai-chat/api/db/cleanup.php
 *
 * This is NOT meant to be hit via a browser URL — it's also blocked by
 * .htaccess like the other backend-only files (deny-by-default rule covers
 * this whole folder since it's still under api/).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/ChatHistoryStore.php';
require_once __DIR__ . '/RateLimiter.php';

$deleted = ChatHistoryStore::purgeInactive();
$rateLimitPurged = RateLimiter::purgeOldEntries();
echo date('Y-m-d H:i:s') . " — Purged {$deleted} inactive visitor(s) and their chat history.\n";
echo date('Y-m-d H:i:s') . " — Purged {$rateLimitPurged} old rate-limit log entries.\n";
