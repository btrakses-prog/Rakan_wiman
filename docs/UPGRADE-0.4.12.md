# Upgrade RAKAN WIMAN 0.4.11 → 0.4.12

## What's included

1. HotSpot Profile Manager: profile records in MySQL are the master data.
2. Router profile discovery after **Uji koneksi** or with **Baca Profil MikroTik**.
3. Per-router states: synced, missing, different, failed, or legacy/unmanaged.
4. Router-only profiles are shown for reference only; they are not imported into MySQL.
5. New/edited/adopted managed profiles are saved to MySQL, queued, then created/updated on active routers.
6. Manual one-way synchronization: Database RAKAN WIMAN → MikroTik.
7. Cron retries failed/offline profile synchronization jobs. The web request processes only a small batch immediately; remaining jobs stay queued for cron.
8. Package form now selects profiles from the master database.

## Update steps

1. Back up the project directory and database.
2. Upload `RAKAN-WIMAN-v0.4.12-UPDATE-PATCH.zip` to the project root (the directory containing `app`, `config`, `public`, and `resources`).
3. Extract and allow overwrite. Do not overwrite `.env` or `storage`.
4. Open the RAKAN WIMAN dashboard. The app automatically runs additive migration `006_hotspot_profile_manager.sql` on first request.
5. No manual SQL import or installer rerun is required. Ensure `database/migrations/006_hotspot_profile_manager.sql` is uploaded.
6. Open **Router MikroTik** and press **Uji koneksi**. This also reads HotSpot profiles and records their comparison status.
7. Open **HotSpot Profile** to review the states and any profiles found only on MikroTik.

## How to adopt legacy package profiles

Existing `packages.mikrotik_profile` names are created as `is_managed=0` legacy records in the master profile table. This preserves existing package references and avoids overwriting current RouterOS settings automatically.

Open **HotSpot Profile → Atur** for a legacy profile. Review/set the desired parameters, then save. The profile is changed to managed master data and a database-to-router sync is queued.

## One-way synchronization rules

- `Baca Profil MikroTik` is read-only; it compares and displays but does not import router profiles into the database.
- `Sinkronkan DB → MikroTik` only processes profiles marked managed in the database.
- Missing profiles are created. Profiles with different managed fields are updated. Matching profiles are left unchanged.
- Router-only profiles are not removed or changed.
- Unrelated RouterOS HotSpot user profiles, HotSpot servers, users, and active sessions are not touched.
- Uptime and calendar validity remain controlled by RAKAN WIMAN package/expiry logic; `session-timeout` is not written by this profile manager.

## Router permissions

The RouterOS API user requires read access to `/ip/hotspot/user/profile` and write access for the `add` and `set` operations. Keep the API service restricted to the application server/VPN source IP.
