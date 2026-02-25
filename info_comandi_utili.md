# frontend
npm run dev

# backend
php artisan serve (start del server laravel)

# Aggiornamento tabella del RAG
  **azienda rev1**
curl -X POST "https://www.echelonitaliaweb.it/charlotte/backend/public/api/knowledge/rebuild?token=echelon&tenant=azienda_rev1" \
  -H "Accept: application/json"
    **charlotte (ufficiale)**
curl -X POST "https://www.echelonitaliaweb.it/charlotte/backend/public/api/knowledge/rebuild?token=echelon&tenant=charlotte" \
  -H "Accept: application/json"

# Test del modello
  **charlotte_text**
curl -X POST "https://www.echelonitaliaweb.it/charlotte/backend/public/api/chat/respond" \
  -H "Content-Type: application/json" \
  -d '{
    "tenant": "charlotte_text",
    "message": "Ciao, lavorate anche alla Nuvola di Roma?"
  }'

# Aggiornamento FTP
bash /home/daniele/CharloTte/scripts/deploy_ftp.sh

# Reportistica chat tenant azienda
curl -u report:change-me \
  "https://www.echelonitaliaweb.it/charlotte/backend/public/api/report/export?tenant=azienda" \
  -o report.csv
credenziali URL
- report
- echelon

# Aggiorna con i topic le vecchi sessioni perse
curl -u report:echelon \
  -X POST "https://www.echelonitaliaweb.it/charlotte/backend/public/api/report/retag"

# Preset per iframe
?embed=1&tenant=charlotte&glass=21 (liquid glass classico milky)
?embed=1&tenant=charlotte&glass=21color (liquid glass colorato)