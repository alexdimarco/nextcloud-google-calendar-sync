# Google Calendar Sync — Nextcloud App

Sync Google Calendar events with your Nextcloud calendar. No admin help required — every user sets up their own Google OAuth credentials independently.

---

## Features

| Feature | Details |
|---|---|
| Per-user OAuth | Each user connects their own Google account |
| One-to-One sync | One Google calendar ↔ one Nextcloud calendar (bidirectional by default) |
| Many-to-One sync | Multiple Google calendars → one Nextcloud calendar (read-only pull) |
| Read-only mode | Pull-only: Google → Nextcloud without pushing back |
| Bidirectional | Events created in Nextcloud are pushed to Google |
| Configurable interval | Default 5 min; settable per mapping (minimum 1 min) |
| Incremental sync | Uses Google's sync tokens — only changed events transferred |
| Secure storage | Tokens and credentials encrypted with Nextcloud's built-in crypto |

---

## Requirements

- Nextcloud 27, 28, 29, or 30
- PHP 8.1+
- Nextcloud background jobs running (cron or webcron)
- A Google Cloud project with the Calendar API enabled

---

## Installation

### 1. Install the app

**Option A — Nextcloud App Store** *(once published)*

Go to **Apps → Search** for "Google Calendar Sync" and click Install.

**Option B — Manual**

```bash
# In your Nextcloud apps directory:
git clone https://github.com/you/googlecalsync.git
cd googlecalsync
composer install --no-dev --optimize-autoloader
```

Then enable it in **Apps → Disabled apps → Google Calendar Sync → Enable**.

---

### 2. Set up Google Cloud credentials (per user — no admin needed)

Each user does this themselves in their own Google account:

1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create a project (or pick an existing one).
3. Enable the **Google Calendar API**:
   - APIs & Services → Library → search "Google Calendar API" → Enable.
4. Create OAuth credentials:
   - APIs & Services → Credentials → **Create Credentials → OAuth client ID**.
   - Application type: **Desktop app**.
   - Click Create, then **Download JSON** (`credentials.json`).
5. On the OAuth consent screen, add your own Google account as a **Test user** (required while the app is in "Testing" mode).

---

### 3. Connect in Nextcloud

1. Open **Settings → Google Calendar Sync** (personal settings).
2. Open your downloaded `credentials.json` in a text editor, copy all the contents.
3. Paste into the "Connect Google Account" box and click **Connect Google Account**.
4. A Google sign-in page opens — log in and grant calendar access.
5. You're redirected back to Nextcloud, now connected.

---

### 4. Add calendar mappings

#### One-to-One (bidirectional)
- Mode: **One-to-One**
- Pick one Google calendar and one Nextcloud calendar.
- Leave "Read-only" unchecked for bidirectional sync.
- Set your sync interval (default: 5 minutes).

#### One-to-One (read-only pull)
- Same as above but check **Read-only**.
- Google events appear in Nextcloud; Nextcloud events are NOT pushed back.

#### Many-to-One (merge multiple Google calendars)
- Mode: **Many-to-One**.
- Hold **Ctrl/Cmd** and select multiple Google calendars.
- Pick one Nextcloud calendar as the target.
- Always read-only (Google → Nextcloud).

Click **Add Mapping** to save.

---

### 5. Sync

- Syncs run automatically in the background per the configured interval.
- Click **Sync Now** to trigger an immediate sync at any time.

---

## Background Job Setup

The plugin registers a Nextcloud background job that runs every minute and checks which mappings are due.

Make sure Nextcloud cron is properly configured:

```
# Recommended: system cron (add to www-data's crontab)
*/5 * * * * php -f /var/www/nextcloud/cron.php
```

Or enable **Webcron** in Nextcloud Admin → Basic Settings.

---

## How Sync Works

### Pull (Google → Nextcloud)
- Uses Google's **incremental sync tokens** so only changed/new/deleted events are transferred after the first full sync.
- First sync pulls events from 6 months ago to 12 months ahead.
- Deleted Google events are removed from Nextcloud.

### Push (Nextcloud → Google) — one_to_one non-read-only only
- After each pull, any Nextcloud events modified since the last sync are pushed to Google.
- New Nextcloud events get a Google Event ID saved internally to avoid duplicates.

### Conflict strategy
- Google is treated as the authority for existing events.
- Nextcloud-originated events (those without a Google ID) are pushed to Google.

---

## Security

- OAuth tokens and `credentials.json` are stored encrypted using Nextcloud's `ICrypto` (AES-256-CBC + HMAC-SHA256).
- Each user's credentials are isolated — users cannot see each other's tokens.
- No data is sent to any third-party server; all sync traffic goes directly between your Nextcloud server and Google's APIs.
- CSRF protection on all state-changing endpoints (uses Nextcloud's `requesttoken`).
- OAuth state parameter prevents CSRF on the callback.

---

## Troubleshooting

| Problem | Solution |
|---|---|
| "No Google credentials stored" | Paste your `credentials.json` and reconnect |
| "Token refresh failed" | Disconnect and reconnect (re-run the OAuth flow) |
| "Access blocked" by Google | Add your Google account as a Test User in the OAuth consent screen |
| Events not syncing | Check that Nextcloud cron is running (`php cron.php`) |
| Sync token expired (410 error) | Plugin automatically falls back to a full sync |
| Calendar not listed | Ensure the calendar is shared with the Google account you authorized |

---

## Development

```bash
# Install dependencies
composer install

# Run Nextcloud's built-in DB migration after install
php occ migrations:migrate googlecalsync

# Enable the app
php occ app:enable googlecalsync
```

---

## License

AGPL-3.0-or-later — same as Nextcloud core.
