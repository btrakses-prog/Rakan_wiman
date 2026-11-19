# Upgrade RAKAN WIMAN 0.4.7 → 0.4.8

1. Back up the application folder.
2. Upload `RAKAN-WIMAN-v0.4.8-UPDATE-PATCH.zip` to the project root (the folder containing `app`, `config`, `public`, and `resources`).
3. Extract and allow overwrite.
4. Keep `.env`, `storage`, and the database unchanged. No SQL migration is required.
5. Open **Template Voucher** and click **Test Print**.

The test opens a separate browser tab with two sample voucher cards and automatically opens the browser print dialog. The sample usernames/passwords are clearly marked as test-only and are not active vouchers. The print view uses current form values, even before saving. If the browser blocks the popup, allow pop-ups for the RAKAN WIMAN domain and click Test Print again.
