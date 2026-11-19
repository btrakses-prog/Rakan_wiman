# Upgrade RAKAN WIMAN 0.4.6 → 0.4.7

1. Back up the application folder and database.
2. Upload `RAKAN-WIMAN-v0.4.7-UPDATE-PATCH.zip` to the project root (the directory containing `app`, `config`, `public`, and `resources`).
3. Extract and allow overwrite.
4. Keep `.env`, `storage`, and the database unchanged. No SQL migration is required.
5. Open **WhatsApp Gateway**, try adding the same sender number again, then use **QRCode**.

This update fixes parsing of the gateway's current JSON response format (`status: true`, `code: 200`) and keeps compatibility with older numeric `status: 200` responses. QR login now requests `output=json`.
