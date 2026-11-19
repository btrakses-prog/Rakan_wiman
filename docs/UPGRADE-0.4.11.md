# Upgrade RAKAN WIMAN 0.4.10 → 0.4.11

## What is fixed

1. RouterOS API login supports both:
   - RouterOS 6.43 and newer: username/password login.
   - RouterOS 6.42 and older: MD5 challenge-response login.
2. RouterOS `!trap` and `!fatal` responses are reported with their actual messages. The error also identifies the operation phase (login or API command), making diagnosis easier.
3. Router API response framing has more defensive length parsing and clearer EOF/timeout handling.
4. Added **Hapus** to the router list. This archives the router from active management instead of physically deleting its database row.

## Update steps

1. Back up the project directory and database.
2. Upload `RAKAN-WIMAN-v0.4.11-UPDATE-PATCH.zip` to the project root (the directory containing `app`, `config`, `public`, and `resources`).
3. Extract and allow overwrite.
4. Do not overwrite `.env` or `storage`.
5. No SQL import, migration, or installer run is required.
6. Open **Router MikroTik** and press **Uji koneksi**.

## Important about router removal

- The **Hapus** button removes the router from the active list by setting `is_active=0`.
- Voucher, payment and voucher-batch history is preserved with its router relation intact.
- Pending synchronization jobs for that router are cancelled and its pending vouchers are marked as failed sync, so they are not silently treated as activated.
- Existing HotSpot user accounts on the physical MikroTik are not deleted.
- An archived router is no longer selected for new voucher generation, expiry synchronization, or queue processing.

## What the TCP test means

If a command from the application server reports `TCP OPEN` to the router API port, the network path and TCP listener are reachable. If the application still cannot log in, check the specific RouterOS API error now shown by v0.4.11. The API user must have the `api` policy; add only the necessary policies and restrict `/ip service api address` or the firewall to the application server/VPN source address where possible.
