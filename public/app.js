function cronApp() {
  return {
    expression: '',
    mode: 'explainer',
    locale: 'en',
    timezone: 'Europe/Warsaw',
    explanation: '',
    nextRuns: [],
    error: '',
    loading: false,
    copied: false,
    highlightedSegment: null,
    theme: 'dark',

    segments: [
      { key: 'minutes', label: 'Minutes', value: '*', placeholder: '*', hint: '0-59' },
      { key: 'hours',   label: 'Hours',   value: '*', placeholder: '*', hint: '0-23' },
      { key: 'dom',     label: 'Day',     value: '*', placeholder: '*', hint: '1-31' },
      { key: 'month',   label: 'Month',   value: '*', placeholder: '*', hint: '1-12' },
      { key: 'dow',     label: 'Weekday', value: '*', placeholder: '*', hint: '0-6'  },
    ],

    presets: [
      { label: 'Every minute',     expr: '* * * * *'    },
      { label: 'Every hour',       expr: '0 * * * *'    },
      { label: 'Daily midnight',   expr: '0 0 * * *'    },
      { label: 'Weekdays 9am',     expr: '0 9 * * 1-5'  },
      { label: 'n8n 15min',        expr: '*/15 * * * *' },
      { label: 'n8n Workdays 8am', expr: '0 8 * * 1-5'  },
    ],

    init() {
      const params = new URLSearchParams(window.location.search);
      if (params.get('expr'))   this.expression = params.get('expr');
      if (params.get('tz'))     this.timezone   = params.get('tz');
      if (params.get('mode'))   this.mode       = params.get('mode');
      if (params.get('locale')) this.locale     = params.get('locale');

      this.theme = document.documentElement.dataset.theme || 'dark';

      if (this.expression) {
        this.syncSegmentsFromExpression();
        this.fetchCron();
      }
    },

    setMode(m) {
      this.mode = m;
      this.updateUrl();
      if (m === 'builder' && this.expression) {
        this.syncSegmentsFromExpression();
      }
    },

    setLocale(l) {
      this.locale = l;
      this.updateUrl();
      if (this.expression) this.fetchCron();
    },

    assembleExpression() {
      this.expression = this.segments.map(s => s.value || '*').join(' ');
      this.fetchCron();
    },

    syncSegmentsFromExpression() {
      const parts = this.expression.trim().split(/\s+/);
      if (parts.length === 5) {
        this.segments.forEach((seg, i) => { seg.value = parts[i]; });
      }
    },

    applyPreset(preset) {
      this.expression = preset.expr;
      this.syncSegmentsFromExpression();
      this.fetchCron();
    },

    async fetchCron() {
      if (!this.expression.trim()) {
        this.explanation = '';
        this.nextRuns    = [];
        this.error       = '';
        this.updateUrl();
        return;
      }

      this.loading = true;
      this.updateUrl();

      try {
        const params = new URLSearchParams({
          expr:   this.expression,
          tz:     this.timezone,
          locale: this.locale,
        });
        const res  = await fetch('/api/cron.php?' + params);
        const data = await res.json();

        if (data.valid) {
          this.explanation = data.explanation;
          this.nextRuns    = data.next_runs;
          this.error       = '';
        } else {
          this.explanation = '';
          this.nextRuns    = [];
          this.error       = data.error || 'Invalid expression';
        }
      } catch (e) {
        this.error = 'Network error — is the server running?';
      } finally {
        this.loading = false;
      }
    },

    async copyExpression() {
      if (!this.expression) return;
      await navigator.clipboard.writeText(this.expression);
      this.copied = true;
      setTimeout(() => { this.copied = false; }, 1500);
    },

    highlightSegment(key) { this.highlightedSegment = key; },
    clearHighlight()      { this.highlightedSegment = null; },

    toggleTheme() {
      this.theme = this.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = this.theme;
    },

    updateUrl() {
      const params = new URLSearchParams();
      if (this.expression) params.set('expr', this.expression);
      if (this.timezone !== 'Europe/Warsaw') params.set('tz',     this.timezone);
      if (this.mode !== 'explainer')         params.set('mode',   this.mode);
      if (this.locale !== 'en')              params.set('locale', this.locale);
      const qs = params.toString();
      history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
    },
  };
}
