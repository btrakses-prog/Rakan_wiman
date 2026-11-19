# Upgrade RAKAN WIMAN 0.4.12 → 0.4.13

## Fixes

1. RouterOS API parsing explicitly supports the `!empty` reply sentence introduced in RouterOS 7.18 and continues reading until the final `!done` sentence. This preserves the protocol's response framing for empty results.
2. HotSpot profile reads now request only `.id`, `name`, `shared-users`, `rate-limit`, `idle-timeout`, `keepalive-timeout`, `status-autorefresh`, `add-mac-cookie`, `mac-cookie-timeout`, `address-pool`, `address-list`, and `transparent-proxy` through `.proplist`. MikroTik recommends `.proplist` to avoid fetching all properties, including some that can have slow access times.
3. Profile sync checks the target profile by name rather than fetching the entire profile list before add/set.
4. The Router test button now checks basic API connectivity and RouterOS identity/version only. Profile discovery stays in the HotSpot Profile page under **Baca Profil MikroTik**.

## Install patch

1. Back up the application folder and database.
2. Upload `RAKAN-WIMAN-v0.4.13-UPDATE-PATCH.zip` into the project root (same directory as `app`, `config`, `public`, `resources`).
3. Extract and allow overwrite.
4. Do not overwrite `.env` or `storage`.
5. No SQL import or migration is required.
6. Open **Router MikroTik → Uji Koneksi**, then **HotSpot Profile → Baca Profil MikroTik**.

## RouterOS API permissions

The API user needs `api` and `read` to inspect profiles. It also needs `write` for creating/updating profiles and provisioning HotSpot users. Restrict the API service/firewall source address to the application server or VPN.

## If profile scan still fails

Run these commands in the RouterOS terminal and share the output (remove any sensitive values):

```routeros
/user print detail where name="API_USERNAME"
/user group print detail where name="GROUP_NAME"
/ip hotspot user profile print detail
```

The selected API account must have `read` policy; for write/sync it must also have `write` and `api`. If simple API commands work but profile reads still time out after this update, inspect whether the router is unusually slow or whether its profile entries contain expensive script/configuration data; the app now requests only the profile fields it compares.
