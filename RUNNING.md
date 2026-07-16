# RevReply — How to Run This Application

This document covers everything you need to start the application, understand the architecture, tunnel it publicly, and configure the environment correctly.

---

## Architecture — Read This First

**This is one single service, not two.**

The backend (Laravel) and the frontend (React) are the same application running on the same port. There is no separate frontend server. There is no frontend URL. There is no frontend container.

Here is what happens when you open the app in a browser:

1. Your browser hits `localhost:8000`
2. Laravel receives the request and returns one HTML file — a shell page (`app.blade.php`) that contains an empty `<div id="app">` and a `<script>` tag pointing to the compiled React JavaScript
3. Your browser executes that JavaScript
4. React takes over the empty div and draws everything you see — the user picker, the dashboard, the account cards
5. All subsequent navigation (switching from user picker to dashboard, clicking buttons) happens entirely inside the browser. The URL stays at `localhost:8000/`. No page reloads.
6. The only time Laravel is called again is for data — fetching the accounts list, triggering OAuth, or disconnecting an account. Those are background data calls, not page navigations.

**Frontend and backend are not two different services.** In some Python setups you may have had FastAPI running separately from a React/Next.js app — those are two processes, two ports, two containers. This is not that. This is one Laravel application that both serves the React app and handles all API calls.

---

## What `npm run dev` Is and Why You Don't Need It

`npm run dev` starts the Vite development server on port 5173. Its only purpose is hot module replacement — when you edit a React component while actively developing, the browser updates instantly without you having to rebuild.

**You do not open `localhost:5173` in your browser. Ever.**

When `npm run dev` is running, you still open `localhost:8000`. The Vite server runs invisibly in the background and streams file changes to the browser. You never interact with it directly.

**For running the app, tunneling, or showing it to someone else — you do not need `npm run dev` at all.**

---

## How to Run the Application

### Step 1 — Start the Database

```bash
docker compose up -d
```

This starts the MySQL container. Run this once. It stays running in the background.

### Step 2 — Compile the Frontend (only needed once, or after any frontend code change)

```bash
npm run build
```

This compiles all React and TypeScript code into optimised static JavaScript and CSS files inside `public/build/`. Laravel serves these files directly. After this runs, the frontend is ready. You do not need to run it again unless you change frontend code.

### Step 3 — Start the Backend

```bash
php artisan serve
```

This starts the Laravel server at `http://localhost:8000`.

### Step 4 — Open the App

Open your browser and go to:

```
http://localhost:8000
```

That is it. That URL shows you both the frontend (React UI) and connects to the backend (Laravel API). There is no second URL.

---

## Cloudflare Tunnel Setup

A Cloudflare tunnel exposes your local server to the internet so Google OAuth callbacks can reach it and so others can test the app.

**You only need one tunnel.** Since frontend and backend are the same service on the same port, one tunnel on port 8000 exposes everything.

### Run the Tunnel

```bash
cloudflared tunnel --url localhost:8000
```

This will print a URL that looks like:

```
https://some-random-words.trycloudflare.com
```

This URL changes every time you run this command. Every time it changes, you must update three things.

---

## What to Update When the Tunnel URL Changes

Open `.env` and change these three values to your new tunnel URL:

```env
APP_URL=https://your-new-tunnel-url.trycloudflare.com
FRONTEND_URL=https://your-new-tunnel-url.trycloudflare.com
GOOGLE_REDIRECT_URI=https://your-new-tunnel-url.trycloudflare.com/auth/gmail/callback
```

`APP_URL` and `FRONTEND_URL` are the same value — your tunnel URL.

### Why Are Both `APP_URL` and `FRONTEND_URL` the Same?

`FRONTEND_URL` is used by the backend's OAuth callback controller. After Google redirects back to Laravel with the authorisation code, Laravel processes it and needs to redirect the user's browser somewhere. That somewhere is the app — which lives at the same URL as the backend. So both values are identical.

`FRONTEND_URL` is not a separate frontend address. It is the backend telling itself "after handling the OAuth callback, redirect the user back to this URL."

### Update Google Cloud Console

Every time the tunnel URL changes, you also need to update the Google Cloud Console:

1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Navigate to APIs & Services → Credentials → your OAuth client
3. Under "Authorised redirect URIs", remove the old URL and add:
   ```
   https://your-new-tunnel-url.trycloudflare.com/auth/gmail/callback
   ```
4. Save

After updating `.env`, clear the Laravel config cache:

```bash
php artisan config:clear
```

Then restart `php artisan serve`.

---

## CORS — Why There Is None

CORS (Cross-Origin Resource Sharing) is only needed when two different origins communicate. An origin is a combination of protocol, domain, and port.

Since the frontend and backend are the same application on the same port (`localhost:8000` or the same tunnel URL), every request from the React app to the Laravel API is same-origin. The browser never blocks same-origin requests. CORS configuration is irrelevant for this architecture.

The `config/cors.php` file that exists in the project is not needed and not active. It was created during a session where the architecture was misunderstood. It does nothing.

---

## Frontend Navigation — How It Works

The frontend has no URL-based pages. There is no React Router. The URL stays at `localhost:8000/` (or the tunnel URL) the entire time you use the app.

Navigation is controlled by a single state variable in `resources/js/app.tsx`:

- If no user has been selected → the User Picker screen is shown
- If a user has been selected → the Dashboard screen is shown

Selecting a user saves their identity to `localStorage`. Refreshing the browser reads from `localStorage` and skips the user picker if a session already exists.

### Where "Pages" Are Defined

There are no page files. The views are React components:

| What you see | File |
|---|---|
| User picker (the two-card profile selection) | `resources/js/components/UserPicker.tsx` |
| Dashboard (connected accounts list) | `resources/js/components/Dashboard.tsx` |
| Individual account card | `resources/js/components/AccountCard.tsx` |
| Toast notifications | `resources/js/components/Toast.tsx` |
| Entry point that controls which view shows | `resources/js/app.tsx` |

---

## Environment Variables Reference

| Variable | Purpose | Example |
|---|---|---|
| `APP_URL` | The public URL of this application | `https://xyz.trycloudflare.com` |
| `FRONTEND_URL` | Where the backend redirects after OAuth callback. Same as `APP_URL`. | `https://xyz.trycloudflare.com` |
| `GOOGLE_REDIRECT_URI` | The full callback path registered in Google Cloud Console | `https://xyz.trycloudflare.com/auth/gmail/callback` |
| `GOOGLE_CLIENT_ID` | Your Google OAuth client ID | — |
| `GOOGLE_CLIENT_SECRET` | Your Google OAuth client secret | — |

---

## Full Startup Sequence (Quick Reference)

```bash
# 1. Start database
docker compose up -d

# 2. Compile frontend assets (after any frontend code change)
npm run build

# 3. Start backend (serves frontend too)
php artisan serve

# 4. Open in browser
# http://localhost:8000

# 5. (Optional) Tunnel for public access
cloudflared tunnel --url localhost:8000
# → Update APP_URL, FRONTEND_URL, GOOGLE_REDIRECT_URI in .env
# → Update Google Cloud Console redirect URI
# → php artisan config:clear
# → Restart php artisan serve
```

---

## What `npm run dev` Is For (If You Ever Need It)

If you are actively writing frontend code (React components, TypeScript) and want your browser to reflect changes instantly without running `npm run build` after every save, run this alongside `php artisan serve`:

```bash
npm run dev
```

Still open `localhost:8000` in your browser — not `localhost:5173`. The Vite server runs invisibly. When you save a file, the browser updates in real time.

Once you are done making frontend changes, run `npm run build` again to compile the final bundle. This is the step that makes your changes permanent and ready for tunneling or production.
