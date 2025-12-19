Backend deploy to Aruba (via File Manager ZIP)

What you’ll upload
- A single ZIP containing the entire `backend/` app, ready to extract on the server.
- Includes `vendor/` (so you don’t need Composer on Aruba).

Recommended document root
- Best: point your (sub)domain document root to `backend/public`.
- If you cannot change the document root, move the contents of `backend/public` to the web root and adjust `index.php` paths as below.

Server requirements
- PHP >= 8.1
- Writable: `backend/storage` and `backend/bootstrap/cache`

Health check
- After upload/extraction and `.env` in place, open `/health/db` to verify DB connectivity.

If you cannot point document root to backend/public
- Move files from `backend/public` to the site web root.
- In that `index.php`, update paths:
  - `require __DIR__.'/backend/vendor/autoload.php';`
  - `$app = require_once __DIR__.'/backend/bootstrap/app.php';`
  (These are relative to the web root.)

.env (production)
- Already configured in this repo with:
  - `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`
  - DB params you provided (host/user/db/password)
- You can edit it on the server if needed (`backend/.env`).

Aruba tip
- Prefer uploading a single ZIP, then use the Aruba File Manager Extract tool. Uploading thousands of files individually often fails or is too slow.

