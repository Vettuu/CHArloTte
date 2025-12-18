# Charlotte – Voice & Text Realtime Concierge

Charlotte è un assistente AI pensato per desk informativi in congressi ed eventi. Combina un backend Laravel che si occupa di sicurezza, orchestration dei tool e knowledge base, con un frontend Next.js che offre un'unica UI per chat testuale e interazione vocale WebRTC con il modello `gpt-realtime`.

## Visione e architettura

- **Esperienza unificata**: la stessa UI gestisce sia domande scritte sia conversazioni vocali full duplex. Lo stream dei messaggi (utente, assistente, prompt di sistema) è sincronizzato in un unico pannello.
- **backend Laravel**
  - Genera token effimeri (`/api/realtime/token`) per sessioni web (text/audio) e tiene traccia delle sessioni (`realtime_sessions`).
  - Espone webhook `/api/realtime/invoke-tool` per ricevere gli eventi server → tool call. I payload passano dal `KnowledgeService`, alimentato da file Markdown/PDF in `resources/knowledge`.
  - `OpenAIRealtimeService` gestisce end-to-end: client secrets, eventi `conversation.item.create`, `response.create`, logging e invio dei risultati dei tool al modello.
  - Suite di test Pest/PhpUnit copre token issuance e webhook queue/job.
- **Frontend Next.js**
  - Single page con `RealtimeAgent` + `RealtimeSession` (SDK `@openai/agents/realtime`). Modalità text usa WebSocket, la modalità audio usa WebRTC, entrambe condividono prompt/instructions.
  - UI a pannelli: header stato, chat scrollabile, composer sempre visibile; theme coerente (gradiente, scrollbar personalizzata).
  - State management locale sincronizza history per sorgente (`text` / `voice`) con fallback messaggi di sistema.
- **Knowledge base modulare**
  - File Markdown/descrizioni + `metadata.json` per indicizzare contenuti.
  - `KnowledgeService` implementa i tool `conference.general_info`, `conference.schedule_lookup`, `conference.location_lookup`, restituendo testi strutturati e dati aggiuntivi.
  - Facile estensione: basta aggiungere file e tool definitions.

## Feature principali

1. **Chat testuale realtime**: usa `gpt-realtime` via WebSocket mantenendo history e tool call automatiche.
2. **Conversazione vocale**: WebRTC attiva microfono/speaker, mostra trascrizioni in chat e sincronizza le risposte audio con il log testuale.
3. **Tool orchestration**: quando il modello richiede informazioni su agenda o location, il backend esegue il tool, logga l'evento e risponde al modello per completare la turn response.
4. **Monitoraggio & auditing**: tutte le sessioni sono registrate in DB con metadata, status e last_event. Log strutturati su token issued / webhook processing.
5. **Testing & configurabilità**: env `config/realtime.php` controlla parametri MVC (voce, turn detection, instructions). Test automatici (HTTP mocking, queue fake) garantiscono regressioni minime.

## Quickstart locale

Prerequisiti:
- PHP >= 8.3, Composer
- Node.js >= 20, npm
- SQLite o altro DB supportato da Laravel
- Chiave OpenAI con accesso a `gpt-realtime`

Passi:

1. **Clona il repository**
   ```bash
   git clone <repo-url> charlotte
   cd charlotte
   ```

2. **Backend (Laravel)**
   ```bash
   cd apps/backend
   cp .env.example .env
   # imposta OPENAI_API_KEY, OPENAI_REALTIME_MODEL ecc.
   composer install
   php artisan key:generate
   php artisan migrate
   php artisan serve --host=0.0.0.0 --port=8000
   ```

3. **Frontend (Next.js)**
   ```bash
   cd ../frontend
   cp .env.local.example .env.local   # definisci NEXT_PUBLIC_BACKEND_URL (es. http://localhost:8000)
   npm install
   npm run dev
   ```

4. **Accesso**
   - Apri `http://localhost:3000` per usare la UI.
   - Verifica che la chat testuale risponda, poi prova il microfono per attivare la sessione vocale.

## Estensioni suggerite

- Registrare nuovi tool (es. `sponsors.list`, `faq.transport`) aggiornando `KnowledgeService`.
- Collegare un DB esterno o CRM per arricchire le risposte prima di inviarle al modello.
- Aggiungere metriche e dashboard (es. Horizon, Prometheus) basandosi sui log e la tabella `realtime_sessions`.
- Integrare SIP o postazioni telefoniche sfruttando lo stesso webhook / service layer.

Charlotte è progettata per essere estendibile e modulare: basta aggiornare i file in `resources/knowledge`, registrare i tool e aggiungere logica server-side nel job `ProcessRealtimeWebhookJob` per coprire nuovi use case congressuali. Buon lavoro!
