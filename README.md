# CronVision

Cron expression explainer and interactive builder. Type an expression, get a human-readable description and the next 10 scheduled run times. Or use the builder to compose an expression field by field.

**Live features:**
- Explains any 5-field cron expression in English and Polish
- Shows next 10 runs with real dates, timezone-aware
- Interactive builder (Minutes / Hours / Day / Month / Weekday)
- Color-coded segment display
- Common presets including n8n and GitHub Actions patterns
- Shareable permalink - expression state lives in the URL
- Dark / light mode

**Stack:** PHP 8.3 · Alpine.js 3 · Custom CSS · Docker (PHP-FPM + nginx) · No framework, no build step.

---

## Running locally

Requires Docker and Docker Compose.

```bash
git clone https://github.com/your-username/cronvision.git
cd cronvision
docker compose up -d
```

Open [http://localhost:8080](http://localhost:8080).

```bash
docker compose down               # stop
docker compose logs -f            # follow logs
docker compose exec php sh        # shell into PHP container
```

---

## Project structure

```
public/
├── index.php        # Single-page app (HTML + Alpine.js)
├── api/
│   └── cron.php     # GET /api/cron.php?expr=...&tz=...&locale=...
├── tokens.css       # Design tokens (CSS custom properties)
└── style.css        # Application styles
src/
└── CronSchedule.php # Cron parser, nextRuns(), explain()
nginx/
└── default.conf     # nginx config
docker-compose.yml
```

---

## API

```
GET /api/cron.php?expr=*/5+9-17+*+*+1-5&tz=Europe/Warsaw&locale=en
```

```json
{
  "valid": true,
  "explanation": "Every 5 minutes, from 09:00 through 17:00, Monday through Friday",
  "next_runs": ["Mon, 27 Apr 2026  09:00", "..."],
  "error": null
}
```

Parameters:
| Param | Default | Description |
|---|---|---|
| `expr` | - | 5-field cron expression (required) |
| `tz` | `Europe/Warsaw` | Any valid PHP timezone identifier |
| `locale` | `en` | `en` or `pl` |

---

## Supported cron syntax

| Syntax | Example | Meaning |
|---|---|---|
| Wildcard | `*` | Every value |
| Number | `5` | Specific value |
| Range | `9-17` | 9 through 17 |
| Step | `*/5` | Every 5 |
| Step on range | `1-5/2` | 1, 3, 5 |
| List | `1,3,5` | 1, 3, and 5 |

All five fields combined: `minute hour day-of-month month day-of-week`

---

## Production deploy

```bash
docker compose up -d
```
