# BandBrief API Routing Plan

## Goal
Use a single front controller (`public/api.php`) that routes requests by extra path info so URLs like:

- `GET /api.php/venues/list`

resolve to:

- class: `App\\Api\\VenuesApi`
- method: `list`

This allows adding endpoints by creating new API classes without modifying global router logic.

## Routing Contract
- Route format: `/api.php/{resource}/{action}/{optional_params...}`
- `resource` and `action` are lowercase snake_case (`^[a-z][a-z0-9_]*$`)
- Class mapping:
  - `venues` -> `VenuesApi`
  - `artist_profiles` -> `ArtistProfilesApi`
- Method mapping:
  - `list` -> `list()`
  - `get_by_id` -> `get_by_id()`

## Dispatch Rules
1. Validate `resource` and `action` with strict regex.
2. Build class name dynamically and autoload from `app/Api`.
3. Refuse unknown classes/methods with 404.
4. Refuse magic/private methods.
5. Pass route tail segments as params array to API action.

## JSON Response Envelope
All endpoints return:

```json
{
  "ok": true,
  "data": {},
  "warnings": [],
  "meta": {}
}
```

Error shape:

```json
{
  "ok": false,
  "error": {
    "code": "not_found",
    "message": "API resource not found"
  },
  "warnings": []
}
```

## API Class Guidelines
- Keep API classes thin and deterministic.
- Put DB access behind prepared statements.
- Use explicit HTTP method checks (`GET`, `POST`, etc).
- Throw `ApiException` for expected API errors.
- Handle partial failures by returning warnings where possible.

## Initial Implementation Scope
- `public/api.php`: front controller
- `app/Core/Autoload.php`: PSR-4 style autoloader for `App\\`
- `app/Core/ApiResponder.php`: uniform JSON responses
- `app/Core/ApiException.php`: typed API errors
- `app/Core/Db.php`: PDO connector (MySQL)
- `app/Api/BaseApi.php`: shared helpers
- `app/Api/VenuesApi.php`: `GET /api.php/venues/list`

## Next Steps
1. Add auth middleware hooks in `api.php`.
2. Add `ArtistsApi`, `RunsApi`, and `ReportsApi`.
3. Add request logging with run IDs for traceability.
4. Add rate limiting and per-endpoint input schemas.
