# VANTIQ API Documentation

## Envelope

```json
{
  "success": true,
  "data": {},
  "error": null
}
```

Errors:

```json
{
  "success": false,
  "data": null,
  "error": {
    "code": "validation_error",
    "message": "The request contains invalid fields.",
    "details": {},
    "referenceId": null
  }
}
```

## Public endpoints

- `GET /api/health`
- `GET /actuator/health`
- `GET /v3/api-docs`
- `GET /swagger-ui`

## Compatibility endpoints

The Spring implementation is required to preserve the existing PHP route families where practical:

- `/api/auth/*`
- `/api/finance/*`
- `/api/operations/*`
- `/api/reports/*`
- `/api/forecast/*`
- `/api/risk/*`
- `/api/decisions/*`
- `/api/connect/*`
- `/api/admin/*`
- `/api/documents/*`
- `/api/imports/*`
- `/api/integrations/*`
- `/api/events/stream`

## Authentication migration

Existing browser/session clients use HttpOnly cookies and CSRF. The target Spring API uses short-lived JWT access tokens and refresh tokens. The frontend must be updated centrally if the authentication mechanism changes.

Until the JWT bridge is complete, existing PHP endpoints remain a compatibility reference and must not be documented as already implemented in Spring.
