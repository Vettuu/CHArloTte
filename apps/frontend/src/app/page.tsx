'use client';

import type { KeyboardEvent } from "react";
import { useEffect, useMemo, useRef, useState } from "react";
import {
  RealtimeAgent,
  RealtimeItem,
  RealtimeSession,
} from "@openai/agents/realtime";
import styles from "./page.module.css";

type Role = "user" | "assistant" | "system";

type MessageSource = "system" | "text" | "voice";

type Message = {
  id: string;
  role: Role;
  content: string;
  timestamp: string;
  source: MessageSource;
};

const INTRO_TIMESTAMP = "2026-06-15T08:00:00.000Z";

const EVENT_CONTEXT = `Evento: Congresso Demo di Chirurgia Generale – "Update in General Surgery".
Date: 15–17 giugno 2026, Roma (Centro Congressi San Marco, Via Roma 123).
Desk Info Point AI: piano terra hall principale, orari 08:00–18:30 (ult. giorno 16:00).
Obiettivo: fornire informazioni verificate sul programma, logistica, ECM e orientamento.`.trim();

const AGENT_INSTRUCTIONS = `${EVENT_CONTEXT}

Sei CHArlotTe, assistente AI ufficiale del congresso. Rispondi SEMPRE in italiano,
con tono cordiale e risposte sintetiche (max 3 frasi) includendo dati ufficiali.
Se non hai certezza di un dato, dichiaralo e proponi alternative (es. inviare mail a segreteria@demo-chirurgia2026.it).
Quando la domanda riguarda orari, sale, crediti ECM o spazi fisici, cita il titolo completo dell'evento nella prima risposta.`.trim();

const INITIAL_MESSAGES: Message[] = [
  {
    id: "intro",
    role: "assistant",
    content:
      "Ciao, sono CHArlotTe. Posso aiutarti con informazioni sul congresso, le sale o il programma. Scrivi o usa il microfono per iniziare.",
    timestamp: INTRO_TIMESTAMP,
    source: "system",
  },
];

const BACKEND_URL =
  process.env.NEXT_PUBLIC_BACKEND_URL ?? "http://localhost:8000";

type KnowledgeHit = {
  id: string;
  title: string;
  excerpt: string;
};

async function fetchRealtimeToken(mode: "text" | "audio") {
  const response = await fetch(`${BACKEND_URL}/api/realtime/token`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ mode }),
  });

  if (!response.ok) {
    throw new Error(`Errore backend (${response.status})`);
  }

  return response.json();
}

async function fetchKnowledgeContext(query: string): Promise<string> {
  if (!query.trim()) {
    return "";
  }

  try {
    const response = await fetch(`${BACKEND_URL}/api/knowledge/search`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ query, limit: 3 }),
    });

    if (!response.ok) {
      return "";
    }

    const payload = await response.json();
    const hits: KnowledgeHit[] = Array.isArray(payload.data)
      ? payload.data
      : [];

    if (hits.length === 0) {
      return "";
    }

    return hits
      .map(
        (hit) =>
          `Fonte: ${hit.title}\n${hit.excerpt}`
      )
      .join("\n\n");
  } catch (error) {
    console.error("Knowledge fetch failed", error);
    return "";
  }
}

const INTRO_MESSAGE = INITIAL_MESSAGES[0];

export default function Home() {
  const [messages, setMessages] = useState<Message[]>(INITIAL_MESSAGES);
  const [inputValue, setInputValue] = useState("");
  const [isRecording, setIsRecording] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [sessionState, setSessionState] = useState<
    "idle" | "connecting" | "ready" | "error"
  >("idle");
  const [voiceState, setVoiceState] = useState<
    "idle" | "connecting" | "ready" | "error"
  >("idle");
  const sessionRef = useRef<RealtimeSession | null>(null);
  const voiceSessionRef = useRef<RealtimeSession | null>(null);

  const formattedMessages = useMemo(
    () =>
      messages.map((message) => ({
        ...message,
        time: new Intl.DateTimeFormat("it-IT", {
          hour: "2-digit",
          minute: "2-digit",
        }).format(new Date(message.timestamp)),
      })),
    [messages],
  );

  useEffect(
    () => () => {
      sessionRef.current?.close();
      voiceSessionRef.current?.close();
    },
    [],
  );

  type MessagePart =
    | { type: "input_text"; text: string }
    | { type: "output_text"; text: string }
    | { type: "input_audio"; transcript: string | null }
    | { type: "output_audio"; transcript?: string | null };

  const mapHistoryToMessages = (
    history: RealtimeItem[],
    source: MessageSource,
  ): Message[] => {
    const dynamicMessages = history
      .filter((item) => item.type === "message")
      .map((item) => {
        const textContent = ((item.content || []) as MessagePart[])
          .map((part) => {
            if (part.type === "input_text" || part.type === "output_text") {
              return part.text;
            }
            if (part.type === "input_audio") {
              return part.transcript ?? "[audio]";
            }
            if (part.type === "output_audio") {
              return part.transcript ?? "[audio]";
            }
            return "";
          })
          .join(" ")
          .trim();

        let content = textContent;
        const contextMarker =
          "\n\nContesto ufficiale (usalo per rispondere citando i dati):";

        if (content.includes(contextMarker)) {
          content = content.split(contextMarker)[0]?.trim() ?? content;
        }

        return {
          id: `${source}-${item.itemId}`,
          role: item.role as Role,
          content,
          timestamp: new Date().toISOString(),
          source,
        };
      })
      .filter((message) => message.content.length > 0);

    return dynamicMessages;
  };

  const syncHistoryMessages = (
    history: RealtimeItem[],
    source: MessageSource,
  ) => {
    const mapped = mapHistoryToMessages(history, source);
    setMessages((prev) => {
      const filtered = prev.filter((message) => message.source !== source);
      const merged = [...filtered, ...mapped];
      merged.sort(
        (a, b) =>
          new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime(),
      );
      return [INTRO_MESSAGE, ...merged.filter((msg) => msg.id !== "intro")];
    });
  };

  const ensureTextSession = async (): Promise<RealtimeSession> => {
    if (sessionRef.current) {
      return sessionRef.current;
    }

    setSessionState("connecting");
    const agent = new RealtimeAgent({
      name: "CHArlotTe",
      instructions: AGENT_INSTRUCTIONS,
    });

    const session = new RealtimeSession(agent, {
      transport: "websocket",
    });

    session.on("history_updated", (history) => {
      syncHistoryMessages(history, "text");
    });

    session.on("error", (event) => {
      console.error("Realtime session error", event);
      setSessionState("error");
    });

    const token = await fetchRealtimeToken("text");

    await session.connect({ apiKey: token.value });
    setSessionState("ready");
    sessionRef.current = session;

    return session;
  };

  const pushMessage = (
    role: Role,
    content: string,
    source: MessageSource = "text",
  ) => {
    setMessages((prev) => [
      ...prev,
      {
        id: crypto.randomUUID(),
        role,
        content,
        timestamp: new Date().toISOString(),
        source,
      },
    ]);
  };

  const ensureVoiceSession = async (): Promise<RealtimeSession> => {
    if (voiceSessionRef.current) {
      return voiceSessionRef.current;
    }

    setVoiceState("connecting");
    const agent = new RealtimeAgent({
      name: "CHArlotTe",
      instructions: AGENT_INSTRUCTIONS,
    });

    const session = new RealtimeSession(agent);

    session.on("history_updated", (history) => {
      syncHistoryMessages(history, "voice");
    });

    session.on("error", (event) => {
      console.error("Voice session error", event);
      setVoiceState("error");
    });

    const token = await fetchRealtimeToken("audio");
    await session.connect({ apiKey: token.value });
    setVoiceState("ready");
    voiceSessionRef.current = session;
    return session;
  };

  const handleSend = async () => {
    const trimmed = inputValue.trim();
    if (!trimmed) return;

    pushMessage("user", trimmed, "text");
    setInputValue("");
    setIsSending(true);

    try {
      const session = await ensureTextSession();

      const knowledge = await fetchKnowledgeContext(trimmed);

      if (knowledge) {
      session.sendMessage({
        type: "message",
        role: "user",
        content: [
          {
            type: "input_text",
            text: `${trimmed}\n\nContesto ufficiale (usalo per rispondere citando i dati):\n${knowledge}`,
          },
        ],
      });
    } else {
      session.sendMessage({
        type: "message",
        role: "user",
        content: [{ type: "input_text", text: trimmed }],
      });
    }
    } catch (error) {
      console.error(error);
      pushMessage(
        "system",
        "Non riesco a contattare CHArlotTe in questo momento. Riprova tra poco.",
        "system",
      );
    } finally {
      setIsSending(false);
    }
  };

  const handleMicToggle = async () => {
    if (isRecording) {
      voiceSessionRef.current?.close();
      voiceSessionRef.current = null;
      setIsRecording(false);
      setVoiceState("idle");
      pushMessage("system", "Registrazione vocale interrotta.", "system");
      return;
    }

    try {
      setIsRecording(true);
      await ensureVoiceSession();
      pushMessage(
        "system",
        "Modalità voce attiva: parla pure, ti sto ascoltando.",
        "system",
      );
    } catch (error) {
      console.error(error);
      pushMessage(
        "system",
        "Impossibile inizializzare la modalità voce. Controlla la connessione.",
        "system",
      );
      setIsRecording(false);
      setVoiceState("error");
    }
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      handleSend();
    }
  };

  return (
    <div className={styles.page}>
      <div className={styles.shell}>
        <header className={styles.header}>
          <div>
            <p className={styles.kicker}>AI Info Point</p>
            <h1>CHArlotTe</h1>
            <span>Assistenza congressuale in tempo reale</span>
          </div>
          <div
            aria-live="polite"
            className={`${styles.status} ${isRecording ? styles.active : ""}`}
          >
            {isRecording
              ? "Voce attiva"
              : voiceState === "connecting"
                ? "Connessione vocale..."
                : voiceState === "error"
                  ? "Errore voce"
                  : sessionState === "ready"
                    ? "Chat connessa"
                    : sessionState === "connecting"
                      ? "Connessione chat..."
                      : "Voce in standby"}
          </div>
        </header>

        <main className={styles.main}>
          <section className={styles.chatPane} aria-live="polite">
            {formattedMessages.length === 0 ? (
              <div className={styles.placeholder}>
                Inizia la conversazione: digita un messaggio oppure premi il
                microfono.
              </div>
            ) : (
              <ul className={styles.messages}>
                {formattedMessages.map((message) => (
                  <li
                    key={message.id}
                    className={`${styles.message} ${styles[message.role]}`}
                  >
                    <div className={styles.messageHeader}>
                      <span>
                        {message.role === "user"
                          ? "Tu"
                          : message.role === "assistant"
                            ? "CHArlotTe"
                            : "Sistema"}
                      </span>
                      <time>{message.time}</time>
                    </div>
                    <p>{message.content}</p>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </main>

        <footer className={styles.composer}>
          <label htmlFor="CHArlotTe-input" className={styles.visuallyHidden}>
            Scrivi un messaggio per CHArlotTe
          </label>
          <textarea
            id="CHArlotTe-input"
            className={styles.input}
            placeholder="Chiedi orari, sale o informazioni logistiche..."
            value={inputValue}
            onChange={(event) => setInputValue(event.target.value)}
            onKeyDown={handleKeyDown}
          />
          <div className={styles.actions}>
            <button
              type="button"
              onClick={handleSend}
              className={styles.sendButton}
              disabled={!inputValue.trim() || isSending}
            >
              {isSending ? "Invio..." : "Invia"}
            </button>
            <button
              type="button"
              onClick={handleMicToggle}
              className={`${styles.micButton} ${
                isRecording ? styles.recording : ""
              }`}
              aria-pressed={isRecording}
            >
              <span aria-hidden="true">🎙️</span>
              <span>{isRecording ? "Stop" : "Parla"}</span>
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}
