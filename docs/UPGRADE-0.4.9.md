# Upgrade RAKAN WIMAN 0.4.8 → 0.4.9

1. Back up the application folder and database.
2. Upload `RAKAN-WIMAN-v0.4.9-UPDATE-PATCH.zip` to the project root (the folder containing `app`, `config`, `public`, and `resources`).
3. Extract the ZIP and allow overwrite.
4. Keep `.env`, `storage`, and the database unchanged. No SQL migration is required.
5. Open **Template Voucher**. Set the Test Print quantity from 1 to 500 (default 52) and click **Test Print**. The preview creates dummy test-only cards and paginates at 52 cards per A4 page.
6. Open a generated voucher batch and choose **Print 52 / A4** for the compact 4-column × 13-row layout. Larger batches are automatically divided into additional pages, with up to 52 existing vouchers on each page.
7. QR and Small remain as alternate larger-card print layouts.

The Test Print never creates voucher records, never calls MikroTik, and does not save unsaved template changes. Actual batch printing uses existing credentials from MySQL and never generates or regenerates vouchers. For best results, select A4 portrait, scale 100% / Actual size, and disable browser headers and footers in the print dialog.
