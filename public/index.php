<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CronVision — Cron Expression Explainer & Builder</title>
  <meta name="description" content="Explain and build cron expressions. Get human-readable descriptions and next scheduled run times with timezone support.">

  <?php
    $expr = htmlspecialchars($_GET['expr'] ?? '', ENT_QUOTES, 'UTF-8');
    if ($expr) {
        echo "<meta property=\"og:title\" content=\"CronVision: $expr\">\n";
        echo "<meta property=\"og:description\" content=\"Cron expression: $expr\">\n";
    }
  ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/tokens.css">
  <link rel="stylesheet" href="/style.css">
</head>
<body x-data="cronApp()" x-init="init()">
  <header class="site-header">
    <div class="header-inner">
      <span class="logo">cron<span class="logo-accent">vision</span></span>
      <div class="header-controls">
        <button class="theme-toggle" @click="toggleTheme()" :aria-label="'Switch to ' + (theme === 'dark' ? 'light' : 'dark') + ' mode'">
          <span x-text="theme === 'dark' ? '☀' : '☾'"></span>
        </button>
      </div>
    </div>
  </header>
  <main class="main-layout">
    <div class="col-left">
      <div class="mode-toggle" role="group" aria-label="Mode">
        <button class="mode-btn" :class="{ active: mode === 'explainer' }" @click="setMode('explainer')">Explainer</button>
        <button class="mode-btn" :class="{ active: mode === 'builder' }" @click="setMode('builder')">Builder</button>
      </div>
      <div x-show="mode === 'explainer'" class="section">
        <label class="field-label" for="cron-input">Expression</label>
        <div class="input-row">
          <input
            id="cron-input"
            type="text"
            class="cron-input"
            :class="{ error: error }"
            x-model="expression"
            @input.debounce.300ms="fetchCron()"
            placeholder="*/5 9-17 * * 1-5"
            autocomplete="off"
            spellcheck="false"
            aria-describedby="cron-error"
          >
          <button class="copy-btn" @click="copyExpression()" :class="{ copied: copied }">
            <span x-text="copied ? 'Copied!' : 'Copy'"></span>
          </button>
        </div>
        <p class="error-msg" id="cron-error" x-show="error" x-text="error" role="alert" aria-live="polite"></p>
      </div>
      <div x-show="mode === 'builder'" class="section">
        <label class="field-label">Builder</label>
        <div class="builder-grid">
          <template x-for="seg in segments" :key="seg.key">
            <div class="builder-field">
              <label class="segment-label" :data-segment="seg.key" :style="'color: var(--color-segment-' + seg.key + ')'">
                <span x-text="seg.label"></span>
              </label>
              <input
                type="text"
                class="segment-input"
                :style="'border-color: var(--color-segment-' + seg.key + '40)'"
                x-model="seg.value"
                @input="assembleExpression()"
                :placeholder="seg.placeholder"
                autocomplete="off"
                spellcheck="false"
              >
              <span class="segment-hint" x-text="seg.hint"></span>
            </div>
          </template>
        </div>
        <div class="builder-result">
          <span class="field-label">Expression</span>
          <div class="input-row">
            <code class="assembled-expr" x-text="expression || '* * * * *'"></code>
            <button class="copy-btn" @click="copyExpression()" :class="{ copied: copied }">
              <span x-text="copied ? 'Copied!' : 'Copy'"></span>
            </button>
          </div>
        </div>
        <p class="error-msg" x-show="error" x-text="error" role="alert" aria-live="polite"></p>
      </div>
      <div class="section" x-show="expression && !error">
        <label class="field-label">Segments</label>
        <div class="segments-display" aria-label="Expression segments">
          <template x-for="seg in segments" :key="seg.key">
            <span
              class="segment-span"
              :data-segment="seg.key"
              :class="{ highlighted: highlightedSegment === seg.key }"
              :style="'color: var(--color-segment-' + seg.key + ')'"
              x-text="seg.value || '*'"
            ></span>
          </template>
        </div>
      </div>
      <div class="section">
        <div class="explanation-header">
          <label class="field-label">Explanation</label>
          <div class="locale-toggle" role="group" aria-label="Language">
            <button class="locale-btn" :class="{ active: locale === 'en' }" @click="setLocale('en')">EN</button>
            <button class="locale-btn" :class="{ active: locale === 'pl' }" @click="setLocale('pl')">PL</button>
          </div>
        </div>
        <p
          class="explanation-text"
          x-text="loading ? 'Parsing...' : (explanation || (error ? '—' : 'Type an expression above'))"
          aria-live="polite"
          :class="{ muted: !explanation || error }"
        ></p>
      </div>
      <div class="section">
        <label class="field-label">Presets</label>
        <div class="presets-row">
          <template x-for="preset in presets" :key="preset.expr">
            <button class="preset-btn" @click="applyPreset(preset)" x-text="preset.label"></button>
          </template>
        </div>
      </div>
    </div>
    <div class="col-right">
      <div class="timeline-header">
        <label class="field-label">Next 10 runs</label>
        <select class="tz-select" x-model="timezone" @change="fetchCron()" aria-label="Timezone">
          <?php
          $zones = \DateTimeZone::listIdentifiers();
          foreach ($zones as $zone) {
              $selected = $zone === 'Europe/Warsaw' ? ' selected' : '';
              echo "<option value=\"" . htmlspecialchars($zone) . "\"$selected>" . htmlspecialchars($zone) . "</option>\n";
          }
          ?>
        </select>
      </div>
      <div class="permalink-indicator" x-show="expression && !error">
        <span class="permalink-dot"></span>
        <span>Shareable link ready</span>
      </div>
      <ul class="timeline" aria-live="polite" aria-label="Next scheduled runs">
        <template x-if="loading">
          <template x-for="i in [1,2,3,4,5,6,7,8,9,10]" :key="i">
            <li class="timeline-item skeleton"></li>
          </template>
        </template>
        <template x-if="!loading && nextRuns.length > 0">
          <template x-for="(run, i) in nextRuns" :key="i">
            <li class="timeline-item">
              <span class="run-index" x-text="(i + 1) + '.'"></span>
              <span class="run-date" x-text="run"></span>
            </li>
          </template>
        </template>
        <template x-if="!loading && nextRuns.length === 0 && !error && expression">
          <li class="timeline-empty">No runs found within 1 year</li>
        </template>
        <template x-if="!loading && !expression">
          <li class="timeline-empty">Fix the expression to see next runs</li>
        </template>
        <template x-if="!loading && error">
          <li class="timeline-empty">Fix the expression to see next runs</li>
        </template>
      </ul>
    </div>
  </main>
  <script src="/app.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</body>
</html>
