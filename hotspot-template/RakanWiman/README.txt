RAKAN WIMAN HOTSPOT LOGIN TEMPLATE

Upload all files in this folder to the active MikroTik HotSpot HTML directory.
The latest archive generated from the RAKAN WIMAN admin page replaces the
purchase and QR scanner placeholders with URLs based on APP_URL.

For QR login, APP_URL must be valid HTTPS and the application host must be
allowed in MikroTik HotSpot Walled Garden. The login page opens the HTTPS
/hotspot-scan page so supported mobile browsers can access the camera.
Use Chrome or Edge on Android and allow camera access. The scanner returns
voucher credentials to the originating login page and submits the standard
HotSpot login form (including CHAP when enabled).

QR codes contain voucher credentials; protect printed cards and images.
