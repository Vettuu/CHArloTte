FTP deploy (Aruba) — Backend only

Summary
- Sync the local built `dist/backend` to the remote path `/acoinazionale2026/backend` via FTP using `lftp mirror`.
- It uploads only changed files and cleans removed ones.

Setup
1) Install lftp
   - Ubuntu/WSL: `sudo apt-get update && sudo apt-get install -y lftp`
2) Copy credentials template
   - `cp .deploy.env.example .deploy.env`
   - Edit `.deploy.env` with your FTP details:
     - `FTP_HOST`, `FTP_USER`, `FTP_PASS`, `FTP_REMOTE_DIR=/acoinazionale2026`

Deploy
1) Build + upload
   - Esegui: `bash scripts/deploy_backend_ftp.sh`
   - Effetto: contenuto di `dist/backend` viene mirrorato su `$FTP_REMOTE_DIR/backend`.
2) Dry run (anteprima senza modifiche)
   - `bash scripts/deploy_backend_ftp.sh --dry-run`
   - Mostra cosa verrebbe sincronizzato, senza toccare il server.
3) Includere anche `.env` (di default è escluso)
   - `bash scripts/deploy_backend_ftp.sh --include-env`
   - Usa con attenzione: sovrascrive il `.env` remoto.

Notes
- The script does NOT touch your web root index.php/.htaccess; it only updates `backend/`.
- The first time you deployed, you already moved `public/` to web root and fixed includes. You won’t need to redo that for subsequent deploys.
- It uses `--delete` to remove files on the server that were removed locally from `dist/backend`.

Troubleshooting
- If credentials are wrong or FTP is blocked, lftp exits with error.
- If you see permissions issues, ensure the server allows writing to `backend/` and its subfolders.
