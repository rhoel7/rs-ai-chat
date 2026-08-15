<?php
require_once __DIR__ . '/api/config.php';

// Server-side environment detection — evaluated once, at request time,
// baked directly into the HTML before it reaches the browser.
// Works even if JavaScript never runs.
// Comma-separated hostname keywords that mark an environment as staging —
// adjust this in config.php to match your own naming convention (e.g. if
// you use "dev." or "preprod." instead of "staging."). Checked as
// case-insensitive substrings of the hostname, so "test" here would also
// match "testing.example.com" — pick keywords specific enough to avoid
// false positives on your actual domains.
$stagingKeywords = array_filter(array_map('trim', explode(',', getenv('STAGING_HOSTNAME_KEYWORDS') ?: 'staging')));
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$isStaging = false;
foreach ($stagingKeywords as $keyword) {
    if ($keyword !== '' && str_contains($host, strtolower($keyword))) {
        $isStaging = true;
        break;
    }
}
$envLabel = $isStaging ? 'STAGING' : 'LIVE';
$envClass = $isStaging ? 'staging' : 'live';

$chatTitle = getenv('CHAT_TITLE') ?: 'AI Chat';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($chatTitle); ?> Demo</title>

<link rel="icon" type="image/x-icon" href="docs/favicon/favicon.ico">
<link rel="icon" type="image/png" sizes="16x16" href="docs/favicon/favicon-16x16.png">
<link rel="icon" type="image/png" sizes="32x32" href="docs/favicon/favicon-32x32.png">
<link rel="apple-touch-icon" sizes="180x180" href="docs/favicon/apple-touch-icon.png">
<link rel="manifest" href="docs/favicon/site.webmanifest">

<meta property="og:title" content="<?php echo htmlspecialchars($chatTitle); ?> Demo">
<meta property="og:description" content="A self-hosted, multi-provider AI chat assistant with encrypted memory, RAG over your own docs, and a configurable persona.">
<meta property="og:image" content="docs/favicon/favicon-big.png">
<meta property="og:type" content="website">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body {
    padding-bottom: 100px; /* keeps landing content clear of the floating bubble */
  }

  /* ---------- Environment banner (demo-only — not part of the widget) ---------- */
  #envBanner {
    text-align: center;
    font-weight: 700;
    font-size: 1.25rem;
    letter-spacing: 0.05em;
    padding: 0.6rem;
  }
  #envBanner.staging {
    background-color: #ffc107;
    color: #212529;
  }
  #envBanner.live {
    background-color: #dc3545;
    color: #fff;
  }

  /* ---------- Hero (demo-only — not part of the widget) ---------- */
  .hero-dark-band {
    background-color: #1a1a1a;
    padding: 3.5rem 1.5rem 3rem;
    margin-bottom: 3rem;
  }
  .hero-wrap {
    max-width: 1000px; /* matches .features below exactly, so the hero's content still aligns with the rest of the page even though its background now extends full-width */
    margin: 0 auto;
  }
  .hero-logo-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1.1rem;
    margin-bottom: 2rem;
  }
  .hero-logo {
    width: 68px;
    height: auto; /* no circular frame — this is the plain line-art mark now, aspect ratio preserved */
    flex-shrink: 0;
  }
  .hero-logo-row h1 {
    font-weight: 700;
    text-align: left;
    margin-bottom: 0;
    line-height: 1.1;
    color: #fff;
  }
  .hero-subtitle {
    color: #adb5bd;
    font-size: 1rem;
    margin: 0.2rem 0 0;
    text-align: left;
  }
  @media (max-width: 480px) {
    .hero-logo-row {
      flex-direction: column;
      text-align: center;
    }
    .hero-logo-row h1,
    .hero-subtitle {
      text-align: center;
    }
  }
  .hero-section {
    display: flex;
    align-items: flex-start; /* the video is taller than the text block — centering them made the video tower both above and below the text, top-aligning reads as a matched, balanced pair instead */
    gap: 3rem;
  }
  .hero-text {
    flex: 1 1 480px;
    text-align: center;
  }
  .hero-text p.pitch {
    font-size: 1.15rem;
    color: #ced4da;
  }
  .hero-badges {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.75rem;
    margin-top: 2rem;
  }
  .hero-disclaimer {
    font-size: 0.85rem;
    color: #9aa1a8;
    font-style: italic;
    margin-top: 1rem;
    max-width: 480px;
    margin-left: auto;
    margin-right: auto;
  }
  .hero-github-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    background-color: #fff;
    color: #1a1a1a;
    border-radius: 0.6rem;
    padding: 0.7rem 1.4rem;
    font-size: 0.95rem;
    font-weight: 500;
    text-decoration: none;
    margin-top: 1.5rem;
    transition: background-color 0.15s ease;
  }
  .hero-github-btn:hover {
    background-color: #e9ecef;
    color: #1a1a1a;
  }
  .hero-github-btn svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
  }
  .hero-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background-color: rgba(255, 255, 255, 0.08);
    color: #e9ecef;
    border-radius: 2rem;
    padding: 0.55rem 1.1rem;
    font-size: 0.9rem;
    font-weight: 500;
  }
  .hero-badge svg {
    width: 16px;
    height: 16px;
    color: #0d6efd;
    flex-shrink: 0;
  }
  .hero-image-wrap {
    flex: 0 0 340px;
    max-width: 340px; /* the demo video is portrait (1104x1608) — narrow by nature, which is exactly what makes it a good side column rather than a full-width stacked element */
  }
  .hero-image-wrap video {
    width: 100%;
    height: auto;
    display: block;
    border-radius: 1rem;
    box-shadow: 0 20px 60px rgba(0,0,0,0.15);
    border: 1px solid #e9ecef;
  }
  @media (max-width: 900px) {
    .hero-section {
      flex-direction: column;
      gap: 2rem;
    }
    .hero-image-wrap {
      flex: 0 0 auto;
      max-width: 340px;
      width: 100%;
    }
  }

  /* ---------- Feature grid (demo-only — not part of the widget) ---------- */
  .features {
    max-width: 1000px;
    margin: 0 auto 4rem;
    padding: 0 1.5rem;
  }
  .features h2 {
    text-align: center;
    font-weight: 700;
    margin-bottom: 2.5rem;
  }
  .feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 1.5rem;
  }
  .feature-card {
    background-color: #fff;
    border: 1px solid #e9ecef;
    border-radius: 0.85rem;
    padding: 1.5rem;
  }
  .feature-card .icon {
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: rgba(13, 110, 253, 0.1);
    color: #0d6efd;
    border-radius: 0.65rem;
    margin-bottom: 0.9rem;
  }
  .feature-card .icon svg {
    width: 22px;
    height: 22px;
  }
  .feature-card h3 {
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
  }
  .feature-card p {
    font-size: 0.92rem;
    color: #495057;
    margin-bottom: 0;
  }

  /* ---------- Embed snippet (demo-only — not part of the widget) ---------- */
  .embed-section {
    max-width: 640px;
    margin: 0 auto 4rem;
    padding: 0 1.5rem;
    text-align: center;
  }
  .embed-section h2 {
    font-weight: 700;
    margin-bottom: 0.75rem;
  }
  .embed-section p {
    color: #495057;
    margin-bottom: 1.25rem;
  }
  .embed-code {
    background-color: #212529;
    color: #f8f9fa;
    border-radius: 0.75rem;
    padding: 1.1rem 1.4rem;
    text-align: left;
    font-family: ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, monospace;
    font-size: 0.9rem;
    overflow-x: auto;
  }
</style>
</head>
<body class="bg-light">

<div id="envBanner" class="<?php echo htmlspecialchars($envClass); ?>"><?php echo htmlspecialchars($envLabel); ?></div>

<div class="hero-dark-band">
<div class="hero-wrap">
  <div class="hero-logo-row">
    <img src="docs/logo/RAIA-white-transparent.png" alt="RAIA logo" class="hero-logo">
    <div>
      <h1><?php echo htmlspecialchars($chatTitle); ?></h1>
      <p class="hero-subtitle">Reunify AI Assistant — Demo</p>
    </div>
  </div>
  <div class="hero-section">
    <div class="hero-text">
      <p class="pitch">
        A multi-provider AI chat assistant. Switch between Groq, Mistral, Gemini, OpenAI, Claude, DeepSeek, or Azure OpenAI without touching the frontend, ground its answers in your own FAQ or documentation with a CSV drop, and give it a defined persona instead of a generic AI voice. Built with encrypted memory, rate limiting, and real security from the ground up. Try it in the corner.
      </p>
      <p class="hero-disclaimer">
        Heads up: this demo currently works as a knowledge-grounded chat assistant, answering questions and pulling from real documentation. Full autonomous agent capabilities, taking actions on your behalf, not just answering, are on the roadmap, not built yet.
      </p>
      <div class="hero-badges">
        <span class="hero-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>AES-256 encrypted</span>
        <span class="hero-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>7 AI providers</span>
        <span class="hero-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>RAG-grounded</span>
        <span class="hero-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"></polyline><line x1="12" y1="19" x2="20" y2="19"></line></svg>Self-hosted, no vendor lock-in</span>
      </div>
      <a href="https://github.com/rhoel7/rs-ai-chat" target="_blank" rel="noopener noreferrer" class="hero-github-btn">
        <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"></path></svg>
        View on GitHub
      </a>
    </div>
    <div class="hero-image-wrap">
      <video autoplay muted loop playsinline poster="docs/screenshots/desktop-chat.png" aria-label="Demo of the Reunify AI Chat widget in use">
        <source src="docs/screenshots/hero-demo.mp4" type="video/mp4">
      </video>
    </div>
  </div>
</div>
</div>

<div class="features">
  <h2>What's actually built in</h2>
  <div class="feature-grid">
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg></div>
      <h3>Seven AI Providers</h3>
      <p>Groq, Mistral, Gemini, OpenAI, Claude, DeepSeek, or Azure OpenAI, switch between them from a dropdown, no code changes.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg></div>
      <h3>Encrypted Memory</h3>
      <p>Conversation history is AES-256-GCM encrypted at rest in MySQL, not stored as plain text.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"></path><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"></path><path d="M12 7v5l4 2"></path></svg></div>
      <h3>Remembers Context</h3>
      <p>Tied to a visitor cookie, history survives page reloads and return visits, and auto-expires after 30 days of inactivity.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg></div>
      <h3>Rate Limited</h3>
      <p>Built-in abuse protection by IP and by visitor, checked before any paid API call ever goes out.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg></div>
      <h3>Real Formatting</h3>
      <p>Bold, links, code, lists, and more, rendered safely. HTML is escaped first, so nothing an AI replies with can ever inject a real tag.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg></div>
      <h3>Grounded in Your Docs</h3>
      <p>Drop a CSV of FAQs, policies, or documentation, and answers get grounded in it automatically. Not a generic chatbot, one that actually knows your business.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></div>
      <h3>Configurable Persona</h3>
      <p>Give it a defined role, a sales associate, a coding tutor, a support agent, instead of a generic AI. Off by default, and hardened against "ignore your instructions" attempts when it's on.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 17 10 11 4 5"></polyline><line x1="12" y1="19" x2="20" y2="19"></line></svg></div>
      <h3>Drop-In Widget</h3>
      <p>One include line embeds it on any PHP page, anywhere on your site, see below.</p>
    </div>
    <div class="feature-card">
      <div class="icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg></div>
      <h3>Token Usage Tracking</h3>
      <p>Every reply's token usage is tracked per conversation, so you can see exactly what a conversation cost, not guess from a vague estimate.</p>
    </div>
  </div>
</div>

<div class="embed-section">
  <h2>Integration is one line</h2>
  <p>The chat bubble is a fully self-contained component. This is the entire setup:</p>
  <div class="embed-code">&lt;?php include '/path/to/reunify-ai-chat/widget.php'; ?&gt;</div>
</div>

<?php
// This is the entire integration story: one include. Everything else on
// this page above is demo-only landing content — the widget itself needs
// nothing but this line to work on any PHP page.
include __DIR__ . '/widget.php';
?>
</body>
</html>
