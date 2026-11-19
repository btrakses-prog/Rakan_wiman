# Upgrade RAKAN WIMAN 0.4.9 → 0.4.10

This is a code-only update. No database migration is required.

1. Back up the current application folder and database.
2. Upload `RAKAN-WIMAN-v0.4.10-UPDATE-PATCH.zip` to the project root (the folder containing `app`, `config`, `public`, and `resources`).
3. Extract the ZIP and allow files to be overwritten.
4. Keep `.env`, `storage`, and the database unchanged.
5. Open **Router MikroTik**. Each router row now has an **Edit** action.
6. Save the corrected address/port/API username or SSL setting, then choose **Uji koneksi**.

## Password handling

The router API password is never displayed in the edit form. Leave the password field empty to keep the existing encrypted password. Enter a new password only when you intend to replace it. New passwords are encrypted using the existing `APP_KEY` with the same AES-256-GCM format used by the application.

After saving router settings, the router connection status is reset to `unknown`; run **Uji koneksi** to verify the updated settings. A successful test changes the status to online. A failed test is shown as an error alert.

No voucher, payment, package, sender, or other router records are regenerated or deleted by this update.
