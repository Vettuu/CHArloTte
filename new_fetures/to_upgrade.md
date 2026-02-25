# risposte del modello per info prese online 
Se metti URL complete, il filtro può non funzionare come vuoi.

Link cliccabili in risposta
Il modello può restituire URL testuali, sì.
Ma nel tuo frontend attuale la risposta viene mostrata come testo semplice, quindi non hai ancora link “renderizzati” con <a>.
Quindi:
può restituire link,
per renderli cliccabili bene serve una piccola modifica frontend (linkify).
Immagini da social in chat
Di default no, non come “allegato immagine” dentro la tua UI attuale text-only.
Può invece restituire:
link del post social,
eventualmente URL immagine se disponibile nelle fonti.
Per mostrare immagini in chat devi estendere frontend (renderer immagini) + backend (passare/validare URL immagine).