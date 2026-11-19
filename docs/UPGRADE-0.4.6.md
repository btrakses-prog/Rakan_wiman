# Upgrade RAKAN WIMAN 0.4.5 → 0.4.6

1. Back up the project directory and database.
2. Upload and extract `RAKAN-WIMAN-v0.4.6-UPDATE-PATCH.zip` into the project root (the directory containing `app`, `config`, `public`, and `resources`). Allow overwrite.
3. Do not overwrite `.env`, `storage`, or the database directory.
4. Sign in, then open **WhatsApp Gateway → Configuration**.
5. Enter the Go WhatsApp gateway `HTTP_BASE_URL` (default path `/api/v1/whatsapp`), `AUTH_BASIC_PASSWORD` from the Go gateway `.env`, and the country code (62 for Indonesia).
6. Add the sender with its international phone number, then choose QRCode or Paircode to link the WhatsApp device.

No database migration is required. Existing `wa_gateway_url`, encrypted `wa_gateway_secret`, `wa_country_code`, senders, JWTs, message templates, and queue records are preserved.

The old combined WhatsApp settings POST is retained as a compatibility route. The new Configuration page follows the PHPNuxBill field layout.
