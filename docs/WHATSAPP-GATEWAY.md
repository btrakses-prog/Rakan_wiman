# WhatsApp Gateway — Go WhatsApp Multi-Device REST

RAKAN WIMAN uses the same endpoint flow as the PHPNuxBill plugin for `dimaskiddo/go-whatsapp-multidevice-rest`.

## Configuration

1. In the Go gateway `.env`, set `AUTH_BASIC_PASSWORD`.
2. Keep `AUTH_JWT_SECRET` as a separate JWT-signing secret. Do not put the JWT secret in RAKAN WIMAN.
3. The repository's default `HTTP_BASE_URL` is `/api/v1/whatsapp`; enter the full reachable URL in RAKAN WIMAN, for example:

   `https://gateway.example.com/api/v1/whatsapp`

4. In RAKAN WIMAN open **WhatsApp Gateway → Configuration** and enter:
   - Server URL: the full `HTTP_BASE_URL` URL above.
   - Auth Basic Password: exactly the value of `AUTH_BASIC_PASSWORD`.
   - Country Code Phone: for Indonesia, `62`.

The Auth Basic Password is encrypted in the RAKAN WIMAN settings table. An empty value is not accepted on the first configuration. On subsequent edits, the existing value is loaded into the password field; leave it unchanged or replace it with the same new value used in the Go gateway `.env`.

## Sender pairing

1. Open the WhatsApp Gateway main page.
2. Add a sender using the complete international number, e.g. `62812xxxxxxx`.
3. RAKAN WIMAN sends `GET /auth` with Basic authentication. The sender phone number is the Basic username and `AUTH_BASIC_PASSWORD` is the password.
4. Use **QRCode** to request `POST /login`, or **Paircode** to request `POST /login/pair`.
5. Complete device linking in WhatsApp. The sender becomes eligible for the message queue when the gateway reports `WhatsApp Client is Reconnected`.

## Sending

Messages use `POST /send/text` with `msisdn` and `message`, authenticated with the sender's JWT Bearer token. The cron worker selects a sender whose state is `connected` and retries failed queue jobs.

The **API to send directly** endpoint is a compatibility helper. It accepts `to`, `msg`, and `secret=md5(AUTH_BASIC_PASSWORD)`, then queues the message for cron delivery. Use HTTPS and keep this URL private. Prefer POST for new integrations so message content and the compatibility token are not placed in browser history or proxy URL logs.

## Compatibility notes

- Server URL must be the Go gateway's `HTTP_BASE_URL`, not only its hostname, unless the gateway is configured with an empty/root path.
- `AUTH_BASIC_PASSWORD` and `AUTH_JWT_SECRET` are different values.
- RAKAN WIMAN does not auto-read the remote Go gateway `.env`; it must be configured with the same `AUTH_BASIC_PASSWORD` value.
- Sender JWT tokens remain encrypted in the RAKAN WIMAN database.


## Response schema compatibility (v0.4.7)

The current Go repository returns JSON with `status` as a boolean and `code` as the HTTP-style numeric response code, for example `{"status":true,"code":200,"message":"Successfully Authenticated","data":{"token":"..."}}`. RAKAN WIMAN validates `code` and boolean `status`, while retaining compatibility with older responses that used numeric `status: 200`. QR login sends `output=json` so the QR code is returned as structured data instead of HTML.
