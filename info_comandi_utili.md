# frontend
npm run dev

# backend
php artisan serve (start del server laravel)

# Aggiornamento tabella del RAG
curl -X POST "https://www.echelonitaliaweb.it/charlotte/backend/public/api/knowledge/rebuild?token=echelon" \
  -H "Accept: application/json"

# Aggiornamento FTP
bash path/deploy_ftp.sh