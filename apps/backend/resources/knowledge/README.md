# Archivio conoscenza Info Point AI

Posiziona qui tutti i file testuali o descrizioni di immagini utili alle risposte dell'assistente.

- I file di testo possono essere Markdown, txt o JSON.
- Le immagini possono essere accompagnate da un file `.md` o `.txt` con la descrizione da usare durante la generazione della risposta.
- Il file `metadata.json` mantiene l'elenco dei documenti disponibili per semplificare l'indicizzazione.

Esegui il comando di build dell'indice ogni volta che aggiorni i contenuti.


# Come aggiornare le informazioni del modello

cd backend
php artisan app:build-knowledge-index
