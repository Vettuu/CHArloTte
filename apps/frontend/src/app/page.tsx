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
  isLocal?: boolean;
};

const INTRO_TIMESTAMP = "2026-06-15T08:00:00.000Z";

const DEFAULT_AGENT_INSTRUCTIONS = `Sei CHArlotTe. Rispondi SEMPRE in italiano,
con tono cordiale e risposte sintetiche (max 3 frasi) includendo dati ufficiali.
Se non hai certezza di un dato, dichiaralo e proponi alternative.`
  .trim();

const DEFAULT_INTRO_MESSAGE =
  "Ciao, sono CHArlotTe. Posso aiutarti con informazioni utili. Scrivi o usa il microfono per iniziare.";

const BACKEND_URL =
  process.env.NEXT_PUBLIC_BACKEND_URL ?? "http://localhost:8000";

type KnowledgeHit = {
  id: string;
  title: string;
  excerpt: string;
  score?: number;
};

type TenantConfig = {
  id: string;
  name?: string;
  intro_message?: string | null;
  support_email?: string | null;
  fallback_message?: string | null;
  instructions?: string | null;
};

async function fetchRealtimeToken(mode: "text" | "audio", tenant?: string) {
  const response = await fetch(`${BACKEND_URL}/api/realtime/token`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      mode,
      metadata: tenant ? { tenant } : undefined,
    }),
  });

  if (!response.ok) {
    throw new Error(`Errore backend (${response.status})`);
  }

  return response.json();
}

async function fetchKnowledgeContext(query: string, tenant?: string): Promise<KnowledgeHit[]> {
  if (!query.trim()) {
    return [];
  }

  try {
    const response = await fetch(`${BACKEND_URL}/api/knowledge/search`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        query,
        limit: 5,
        tenant,
      }),
    });

    if (!response.ok) {
      return [];
    }

    const payload = await response.json();
    const hits: KnowledgeHit[] = Array.isArray(payload.data)
      ? payload.data
      : [];

    return hits;
  } catch (error) {
    console.error("Knowledge fetch failed", error);
    return [];
  }
}

const CONTEXT_MARKER = "__CHARLOTTE_CONTEXT__";

async function logChatMessage(payload: {
  sessionId: string;
  tenantId: string;
  messageId: string;
  role: "user" | "assistant";
  content: string;
  source: MessageSource;
  timestamp: string;
  metadata?: Record<string, unknown>;
}) {
  try {
    await fetch(`${BACKEND_URL}/api/report/log`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        session_id: payload.sessionId,
        tenant_id: payload.tenantId,
        message_id: payload.messageId,
        role: payload.role,
        content: payload.content,
        source: payload.source,
        timestamp: payload.timestamp,
        metadata: payload.metadata,
      }),
    });
  } catch (error) {
    console.warn("Report log failed", error);
  }
}

const buildIntroMessage = (content: string): Message => ({
  id: "intro",
  role: "assistant",
  content,
  timestamp: INTRO_TIMESTAMP,
  source: "system",
});

function formatKnowledgeContext(hits: KnowledgeHit[]): string {
  return hits
    .map((hit) => {
      const score = typeof hit.score === "number" ? ` (score ${hit.score})` : "";
      return `Fonte: ${hit.title}${score}\n${hit.excerpt}`;
    })
    .join("\n\n");
}

async function sendContextInstruction(
  session: RealtimeSession,
  query: string,
  hits: KnowledgeHit[],
  supportEmail?: string | null,
  fallbackMessage?: string | null,
) {
  const fallbackContact = supportEmail
    ? `Invita a contattare ${supportEmail} per approfondimenti.`
    : "Invita a richiedere un contatto per ulteriori dettagli.";
  const context = hits.length > 0
    ? `${CONTEXT_MARKER} Usa esclusivamente questi estratti verificati per rispondere alla domanda "${query}":\n${formatKnowledgeContext(hits)}`
    : `${CONTEXT_MARKER} Non hai trovato fonti affidabili per "${query}". ${fallbackMessage ? `Puoi usare questo messaggio generale: "${fallbackMessage}".` : "Spiega che l'informazione non è presente nei documenti ufficiali."} ${fallbackContact}`;

  await session.sendMessage({
    type: "message",
    role: "user",
    content: [
      {
        type: "input_text",
        text: context,
      },
    ],
  });
}

export default function Home() {
  const [isEmbed, setIsEmbed] = useState(false);
  const [glassMode, setGlassMode] = useState<"off" | "medium" | "liquid21" | "liquid21color">("off");
  const [tenantId, setTenantId] = useState<string | null>(null);
  const [tenantConfig, setTenantConfig] = useState<TenantConfig | null>(null);
  const [tenantResolved, setTenantResolved] = useState(false);
  const [introMessage, setIntroMessage] = useState<string | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [inputValue, setInputValue] = useState("");
  const [isRecording, setIsRecording] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [isAssistantThinking, setIsAssistantThinking] = useState(false);
  const [sessionState, setSessionState] = useState<
    "idle" | "connecting" | "ready" | "error"
  >("idle");
  const [voiceState, setVoiceState] = useState<
    "idle" | "connecting" | "ready" | "error"
  >("idle");
  const sessionRef = useRef<RealtimeSession | null>(null);
  const sessionPromiseRef = useRef<Promise<RealtimeSession> | null>(null);
  const voiceSessionRef = useRef<RealtimeSession | null>(null);
  const voiceSessionPromiseRef = useRef<Promise<RealtimeSession> | null>(null);
  const messagesEndRef = useRef<HTMLDivElement | null>(null);
  const processedVoiceMessages = useRef<Set<string>>(new Set());
  const textSessionIdRef = useRef<string | null>(null);
  const loggedMessageIds = useRef<Set<string>>(new Set());
  const pendingFallbackFlags = useRef<boolean[]>([]);
  const seenAssistantIdsRef = useRef<Set<string>>(new Set());

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
  useEffect(() => {
    if (typeof window === "undefined") {
      return;
    }
    const params = new URLSearchParams(window.location.search);
    setIsEmbed(params.get("embed") === "1");
    const glassParam = params.get("glass");
    if (glassParam === "1" || glassParam === "medium") {
      setGlassMode("medium");
    } else if (glassParam === "21color" || glassParam === "liquid21color") {
      setGlassMode("liquid21color");
    } else if (glassParam === "21" || glassParam === "liquid" || glassParam === "dock") {
      setGlassMode("liquid21");
    } else {
      setGlassMode("off");
    }
    setTenantId(params.get("tenant"));
    setTenantResolved(true);
  }, []);

  useEffect(() => {
    if (!tenantResolved) {
      return;
    }
    const controller = new AbortController();
    const tenantParam = tenantId ? `?tenant=${encodeURIComponent(tenantId)}` : "";

    fetch(`${BACKEND_URL}/api/tenant/config${tenantParam}`, {
      signal: controller.signal,
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((payload) => {
        const config = payload?.tenant as TenantConfig | undefined;
        if (!config) {
          setIntroMessage((prev) => prev ?? DEFAULT_INTRO_MESSAGE);
          return;
        }
        setTenantConfig(config);
        setIntroMessage(config.intro_message ?? DEFAULT_INTRO_MESSAGE);
      })
      .catch(() => {
        setIntroMessage((prev) => prev ?? DEFAULT_INTRO_MESSAGE);
      });

    return () => controller.abort();
  }, [tenantId, tenantResolved]);

  useEffect(() => {
    if (!introMessage) {
      return;
    }
    setMessages((prev) => {
      const hasIntro = prev.some((message) => message.id === "intro");
      if (hasIntro) {
        return prev.map((message) =>
          message.id === "intro" ? { ...message, content: introMessage } : message,
        );
      }
      return [buildIntroMessage(introMessage), ...prev];
    });
  }, [introMessage]);


  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [formattedMessages]);

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
        if (item.role === "system") {
          return null;
        }

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
        if (content.includes(CONTEXT_MARKER)) {
          content = content.split(CONTEXT_MARKER)[0]?.trim() ?? "";
          if (!content) {
            return null;
          }
        }

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
      .filter((message): message is Message => {
        if (!message) {
          return false;
        }

        return message.content.length > 0;
      });

    return dynamicMessages;
  };

  const syncHistoryMessages = (
    history: RealtimeItem[],
    source: MessageSource,
  ) => {
    const mapped = mapHistoryToMessages(history, source);
    if (source === "text") {
      const newAssistantMessages = mapped.filter(
        (message) =>
          message.role === "assistant" &&
          !seenAssistantIdsRef.current.has(message.id),
      );
      mapped
        .filter((message) => message.role === "assistant")
        .forEach((message) => seenAssistantIdsRef.current.add(message.id));

      if (newAssistantMessages.length > 0) {
        setIsAssistantThinking(false);
      }
    }
    if (source === "text" && textSessionIdRef.current && tenantId) {
      mapped.forEach((message) => {
        if (message.role !== "user" && message.role !== "assistant") {
          return;
        }
        if (loggedMessageIds.current.has(message.id)) {
          return;
        }
        loggedMessageIds.current.add(message.id);
        const fallbackFlag = message.role === "assistant"
          ? (pendingFallbackFlags.current.shift() ?? false)
          : false;
        void logChatMessage({
          sessionId: textSessionIdRef.current as string,
          tenantId,
          messageId: message.id,
          role: message.role,
          content: message.content,
          source: message.source,
          timestamp: message.timestamp,
          metadata: fallbackFlag ? { fallback: true } : undefined,
        });
      });
    }
    setMessages((prev) => {
      const map = new Map(prev.map((message) => [message.id, message]));
      mapped.forEach((message) => {
        const existing = map.get(message.id);
        map.set(message.id, existing ? { ...existing, ...message } : message);
      });
      let merged = Array.from(map.values());
      if (source === "text") {
        const confirmedUser = new Set(
          mapped
            .filter((message) => message.role === "user")
            .map((message) => message.content),
        );
        merged = merged.filter(
          (message) =>
            !(
              message.isLocal &&
              message.role === "user" &&
              confirmedUser.has(message.content)
            ),
        );
      }
      return merged;
    });

    if (source === "voice") {
      mapped
        .filter((message) => message.role === "user" && message.content.trim())
        .forEach((message) => {
          if (processedVoiceMessages.current.has(message.id)) {
            return;
          }

          processedVoiceMessages.current.add(message.id);
          void attachContextToVoiceMessage(message.content);
        });
    }
  };

  const ensureTextSession = async (): Promise<RealtimeSession> => {
    if (sessionRef.current) {
      return sessionRef.current;
    }
    if (sessionPromiseRef.current) {
      return sessionPromiseRef.current;
    }

    const setup = async () => {
      setSessionState("connecting");
      const agent = new RealtimeAgent({
        name: "CHArlotTe",
        instructions: tenantConfig?.instructions ?? DEFAULT_AGENT_INSTRUCTIONS,
      });

      const session = new RealtimeSession(agent, {
        transport: "websocket",
      });
      sessionRef.current = session;

      session.on("history_updated", (history) => {
        syncHistoryMessages(history, "text");
      });

      session.on("error", (event) => {
        console.error("Realtime session error", event);
        setSessionState("error");
        sessionRef.current = null;
        sessionPromiseRef.current = null;
      });

      const token = await fetchRealtimeToken("text", tenantId ?? undefined);
      textSessionIdRef.current = token.session?.id ?? null;
      await session.connect({ apiKey: token.value });
      setSessionState("ready");

      sessionPromiseRef.current = null;
      return session;
    };

    sessionPromiseRef.current = setup();
    return sessionPromiseRef.current;
  };

  const pushMessage = (
    role: Role,
    content: string,
    source: MessageSource = "text",
    isLocal: boolean = false,
  ) => {
    setMessages((prev) => [
      ...prev,
      {
        id: crypto.randomUUID(),
        role,
        content,
        timestamp: new Date().toISOString(),
        source,
        isLocal,
      },
    ]);
  };

  const ensureVoiceSession = async (): Promise<RealtimeSession> => {
    if (voiceSessionRef.current) {
      return voiceSessionRef.current;
    }
    if (voiceSessionPromiseRef.current) {
      return voiceSessionPromiseRef.current;
    }

    const setup = async () => {
      setVoiceState("connecting");
      const agent = new RealtimeAgent({
        name: "CHArlotTe",
        instructions: tenantConfig?.instructions ?? DEFAULT_AGENT_INSTRUCTIONS,
      });

      const session = new RealtimeSession(agent);
      voiceSessionRef.current = session;

      session.on("history_updated", (history) => {
        syncHistoryMessages(history, "voice");
      });

      session.on("error", (event) => {
        console.error("Voice session error", event);
        setVoiceState("error");
        voiceSessionRef.current = null;
        voiceSessionPromiseRef.current = null;
      });

      const token = await fetchRealtimeToken("audio", tenantId ?? undefined);
      await session.connect({ apiKey: token.value });
      setVoiceState("ready");
      voiceSessionPromiseRef.current = null;
      return session;
    };

    voiceSessionPromiseRef.current = setup();
    return voiceSessionPromiseRef.current;
  };

  const attachContextToVoiceMessage = async (utterance: string) => {
    if (!utterance.trim()) {
      return;
    }

    try {
      const session = await ensureVoiceSession();
      const hits = await fetchKnowledgeContext(utterance, tenantId ?? undefined);
      await sendContextInstruction(
        session,
        utterance,
        hits,
        tenantConfig?.support_email,
        tenantConfig?.fallback_message,
      );
    } catch (error) {
      console.error("Voice context enrichment failed", error);
    }
  };

  const handleSend = async () => {
    const trimmed = inputValue.trim();
    if (!trimmed) return;

    pushMessage("user", trimmed, "text", true);
    setInputValue("");
    setIsSending(true);
    setIsAssistantThinking(true);

    try {
      const session = await ensureTextSession();

      const hits = await fetchKnowledgeContext(trimmed, tenantId ?? undefined);
      pendingFallbackFlags.current.push(hits.length === 0);
      const fallbackContact = tenantConfig?.support_email
        ? `Invita a contattare ${tenantConfig.support_email} per approfondimenti.`
        : "Invita a richiedere un contatto per ulteriori dettagli.";
      const fallbackMessage = tenantConfig?.fallback_message;
      const contextBlock = hits.length > 0
        ? `${CONTEXT_MARKER} Usa esclusivamente questi estratti verificati per rispondere alla domanda "${trimmed}":\n${formatKnowledgeContext(hits)}`
        : `${CONTEXT_MARKER} Non hai trovato fonti affidabili per "${trimmed}". ${fallbackMessage ? `Puoi usare questo messaggio generale: "${fallbackMessage}".` : "Spiega che l'informazione non è presente nei documenti ufficiali."} ${fallbackContact}`;

      await session.sendMessage({
        type: "message",
        role: "user",
        content: [{ type: "input_text", text: `${trimmed}\n\n${contextBlock}` }],
      });
    } catch (error) {
      console.error(error);
      setIsAssistantThinking(false);
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
       processedVoiceMessages.current.clear();
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
    <div className={`${styles.page} ${isEmbed ? styles.embedPage : ""}`}>
      <div className={`${styles.shell} ${isEmbed ? styles.embedShell : ""}`}>
        {/*!isEmbed && (
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
        )*/}

        <main
          className={`${styles.main} ${isEmbed ? styles.embedMain : ""}`}
        >
          <section
            className={`${styles.chatPane} ${isEmbed ? styles.embedChatPane : ""} ${
              isEmbed && glassMode === "medium"
                ? styles.embedChatPaneGlassMedium
                : isEmbed && glassMode === "liquid21color"
                  ? styles.embedChatPaneGlass21Colored
                : isEmbed && glassMode === "liquid21"
                  ? styles.embedChatPaneGlass21
                  : ""
            }`}
            aria-live="polite"
          >
            {formattedMessages.length === 0 ? (
              introMessage ? (
                <div className={styles.placeholder}>
                  Inizia la conversazione: digita un messaggio oppure premi il
                  microfono.
                </div>
              ) : null
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
                {isAssistantThinking ? (
                  <li className={`${styles.message} ${styles.assistant} ${styles.typing}`}>
                    <div className={styles.typingDots} aria-label="CHArlotTe sta scrivendo">
                      <span />
                      <span />
                      <span />
                    </div>
                  </li>
                ) : null}
                <div ref={messagesEndRef} />
              </ul>
            )}
          </section>
        </main>

        <footer
          className={`${styles.composer} ${isEmbed ? styles.embedComposer : ""}`}
        >
          <label htmlFor="CHArlotTe-input" className={styles.visuallyHidden}>
            Scrivi un messaggio per CHArlotTe
          </label>
          <textarea
            id="CHArlotTe-input"
            className={styles.input}
            placeholder="Digita qui la tua domanda...."
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
          </div>
        </footer>
      </div>
    </div>
  );
}
