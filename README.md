# BandBrief

BandBrief is a plain-PHP + plain-JS system that generates a deterministic intelligence brief for an artist/band by aggregating multiple sources, normalizing metrics, scoring them, and building a structured booking-oriented report.

## Architecture

- Backend: PHP JSON API only (`public/api.php`) using PDO + MySQL prepared statements.
- Frontend: plain HTML/CSS/JS, native ES modules, Web Components, LARC event bus.
- No frameworks, no SSR, no Node requirement, no build process.

## Project Structure

- `public/api.php`
- `public/index.html`
- `public/assets/css/app.css`
- `public/assets/js/*`
- `app/Api/*`
- `app/Core/*`
- `app/Services/*`
- `app/Repositories/*`
- `app/Adapters/*`
- `app/Scoring/*`
- `app/Reporting/*`
- `app/Support/*`
- `sql/schema.sql`

## Source Integrations (MVP)

- Spotify (`official_api`) via Client Credentials
- Last.fm (`official_api`) via API key
- MusicBrainz (`official_api`) with compliant `User-Agent`
- Wikipedia (`official_api`) MediaWiki + Wikidata
- Reddit (`search_api`) public JSON search
- Bandcamp (`scraping`) HTML search parse
- Official website discovery from Wikipedia/Wikidata (P856)

All adapters return deterministic normalized structures with:

- `source`
- `collection_method`
- `status` (`ok`/`partial`/`error`)
- `confidence`
- `fetched_at`
- `payload`
- `errors`

## 1. MySQL Setup

```sql
CREATE DATABASE bandbrief CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
mysql -u root -p bandbrief < sql/schema.sql
```

If you already have an existing database, apply incremental migrations too:

```bash
mysql -u root -p bandbrief < sql/migrations/20260412_source_snapshot_provenance.sql
```

## 2. Environment Setup

```bash
cp .env.example .env
```

Set credentials as available:

- `SPOTIFY_CLIENT_ID`
- `SPOTIFY_CLIENT_SECRET`
- `LASTFM_API_KEY`
- `REDDIT_USER_AGENT`
- `MUSICBRAINZ_USER_AGENT` (required by MusicBrainz usage policy)

The system still works with partial data when credentials are missing.

## 3. Run with Apache (example)

- Point your vhost `DocumentRoot` to `.../bandbrief/public`
- Ensure PHP is enabled
- Request APIs at `/api.php/{resource}/{action}`

Example Apache vhost:

```apache
<VirtualHost *:80>
    ServerName bandbrief.local
    DocumentRoot /Users/you/Projects/bandbrief/public
    <Directory /Users/you/Projects/bandbrief/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## 4. Run with nginx + php-fpm (example)

```nginx
server {
    listen 80;
    server_name bandbrief.local;
    root /Users/you/Projects/bandbrief/public;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ ^/api\.php(?:/.*)?$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

## 5. Open the Frontend

Open:

- `http://bandbrief.local/`

`index.html` is a pure frontend shell that calls the JSON API.

## API Examples

### Health/route index

```bash
curl http://bandbrief.local/api.php
```

### Search artists

```bash
curl "http://bandbrief.local/api.php/artists/search?q=khruangbin&limit=5"
```

### Resolve artist identity

```bash
curl -X POST http://bandbrief.local/api.php/artists/resolve \
  -H "Content-Type: application/json" \
  -d '{"name":"khruangbin"}'
```

### Create report

```bash
curl -X POST http://bandbrief.local/api.php/reports/create \
  -H "Content-Type: application/json" \
  -d '{"name":"khruangbin","force":false}'
```

### View report

```bash
curl http://bandbrief.local/api.php/reports/view/42
```

### Report status

```bash
curl http://bandbrief.local/api.php/reports/status/42
```

## JSON Response Format

Success:

```json
{
  "ok": true,
  "data": {},
  "meta": {}
}
```

Error:

```json
{
  "ok": false,
  "error": {
    "code": "string_code",
    "message": "Human readable message"
  }
}
```

## Example Payloads

- `docs/examples/success_response.json`
- `docs/examples/error_response.json`
- `docs/examples/report_output.json`
