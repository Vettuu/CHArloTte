# Deploy su Aruba (FTP)

Questa guida spiega come pubblicare Charlotte su un hosting Aruba basato su FTP, caricando solo gli artefatti necessari (Laravel + Next export). Il processo è orchestrato dallo script `scripts/deploy_ftp.sh`.

## Prerequisiti locali

1. **Dipendenze**: installa `lftp`, `rsync`, `composer`, `node`/`npm`.
   ```bash
   sudo apt-get update && sudo apt-get install -y lftp rsync
   ```
2. **Credenziali FTP**: copia il template e compilalo.
   ```bash
   cp .env.deploy.example .env.deploy
   # edit .env.deploy con FTP_HOST, FTP_USER, FTP_PASS, FTP_REMOTE_DIR, FTP_SSL_ALLOW
   ```
3. **Configurazioni produzione**:
   - Backend: crea `apps/backend/.env.production` (puoi partire da `.env.example`) con le credenziali MySQL Aruba e chiavi OpenAI.
   - Frontend: se il sito vive sotto un sottocartella (es. `/charlotte`), imposta `apps/frontend/.env.production` con `NEXT_PUBLIC_BASE_PATH=/nome-cartella`.

## Database MySQL Aruba

1. Dal pannello di controllo Aruba crea un database MySQL dedicato (annota host, nome DB, utente, password).
2. Inserisci i dati in `apps/backend/.env.production`:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=sqlXXX.aruba.it
   DB_PORT=3306
   DB_DATABASE=nome_db
   DB_USERNAME=utente_db
   DB_PASSWORD=password_db
   ```
3. Esegui le migrazioni una volta (puoi farlo dal tuo PC puntando al DB remoto):
   ```bash
   cd apps/backend
   php artisan migrate --force --env=production
   ```
   In alternativa importa manualmente le tabelle da `database/migrations` via phpMyAdmin.

## Esecuzione deploy

1. Costruisci e sincronizza (backend + frontend):
   ```bash
   bash scripts/deploy_ftp.sh
   ```
   Lo script:
   - copia `apps/backend` in `dist/backend`, esegue `composer install --no-dev`, pulisce storage/logs e copia `.env.production` come `.env`;
   - esegue `npm run build && npm run export` in `apps/frontend`, copiando l'output statico `out/` in `dist/frontend`;
   - usa `lftp mirror` per caricare `dist/backend` su `$FTP_REMOTE_DIR/backend` e `dist/frontend` su `$FTP_REMOTE_DIR/frontend`.
2. Dry-run (anteprima senza upload):
   ```bash
   bash scripts/deploy_ftp.sh --dry-run
   ```
3. Riutilizzare build esistenti (`dist/` già pronto):
   ```bash
   bash scripts/deploy_ftp.sh --skip-build
   ```

### Note

- Lo script non tocca eventuali `.htaccess`/index nella root: se il tuo hosting usa un `.htaccess` per redirigere su `frontend/index.html`, lascialo nella cartella principale (`FTP_REMOTE_DIR`).
- Assicurati che `storage/` sul server sia scrivibile (imposta permessi da pannello Aruba).
- Per aggiornamenti rapidi del solo backend/frontend puoi lanciare lo script normalmente: la fase di build rigenera entrambe le parti.

## Post-deploy

- Verifica che `https://tuodominio` punti alla cartella corretta (es. `.htaccess` che inoltra a `frontend/index.html`).
- Se modifichi `.env.production`, riesegui il deploy per propagare le modifiche.
- In caso di problemi di permessi con FTP Aruba, controlla che l'utente abbia diritti di scrittura su `FTP_REMOTE_DIR/backend` e `FTP_REMOTE_DIR/frontend`.
