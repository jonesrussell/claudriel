# Ingestion Specification

## File Map

| File | Purpose |
|------|---------|
| `src/Ingestion/GmailMessageNormalizer.php` | Converts raw Gmail API message to `Envelope` |
| `src/Ingestion/EventHandler.php` | Persists `Envelope` as `McEvent`, upserts `Person` |
| `src/Ingestion/CommitmentHandler.php` | Saves `Commitment` entities from AI extraction candidates |

## Interface Signatures

```php
// GmailMessageNormalizer
public function normalize(array $raw, string $tenantId): Envelope

// EventHandler
public function handle(Envelope $envelope): McEvent

// CommitmentHandler
public function handle(
    array $candidates,   // [{title: string, confidence: float}]
    McEvent $event,
    string $personId,
    string $tenantId
): void
```

## Data Flow

```
Gmail API response (raw array)
    → GmailMessageNormalizer::normalize($raw, $tenantId)
    → Envelope(source='gmail', type='message.received', payload=[...], ...)
    → EventHandler::handle(Envelope)
    → saves McEvent, upserts Person (by email uniqueness check)
    → returns McEvent

McEvent + AI extraction output
    → CommitmentHandler::handle($candidates, $event, $personId, $tenantId)
    → filters: confidence < 0.7 → skipped
    → saves Commitment per accepted candidate
```

## Envelope Structure

```php
new Envelope(
    source:    'gmail',
    type:      'message.received',
    payload:   [
        'message_id' => string,   // Gmail message ID
        'thread_id'  => string,
        'from_email' => string,   // extracted from "From" header
        'from_name'  => string,   // extracted from "From" header
        'subject'    => string,
        'date'       => string,   // raw Date header value
        'body'       => string,   // base64url decoded body text
    ],
    timestamp: string,            // ISO 8601, set at normalization time
    traceId:   string,            // uniqid('gmail-', true)
    tenantId:  string,
);
```

## Gmail Raw Payload Notes

- `$raw['payload']['headers']` is an array of `{name, value}` — must lowercase keys
- `$raw['payload']['body']['data']` is base64url encoded — decode with `strtr($data, '-_', '+/')`
- "From" header formats: `"Name <email>"` or just `"email"` — both handled by regex

## Commitment Confidence Threshold

`CommitmentHandler::CONFIDENCE_THRESHOLD = 0.7`

Candidates with `confidence < 0.7` are silently skipped. This is the only filter — there is no deduplication against existing commitments.

## Person Upsert Logic

`EventHandler::upsertPerson()` checks `$personRepo->count(['email' => $email]) > 0`. If the person already exists by email, no insert is made. No update of name on conflict.

## Dependencies

- `EventHandler` needs two repos injected: `$eventRepo` (mc_event) + `$personRepo` (person)
- `CommitmentHandler` needs one repo: `$repo` (commitment)
- `GmailMessageNormalizer` has no dependencies (pure function)
