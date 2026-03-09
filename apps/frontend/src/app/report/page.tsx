'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import styles from './report.module.css';

const BACKEND_URL =
  process.env.NEXT_PUBLIC_BACKEND_URL ?? 'http://localhost:8000';

type KpiResponse = {
  tenant: string;
  tenants?: string[];
  from?: string | null;
  to?: string | null;
  sessions: number;
  users_unique?: number;
  messages_total: number;
  messages_user: number;
  messages_assistant: number;
  fallback_messages: number;
  fallback_rate_percent: number;
  contradiction_messages?: number;
  contradiction_rate_percent?: number;
  confidence_avg?: number;
  latency_avg_ms?: number;
  latency_p95_ms?: number;
  token_in_avg?: number;
  token_out_avg?: number;
  cost_estimated_per_session?: number;
  rag_hits_avg?: number;
  top_score_avg?: number;
  accepted_hits_avg?: number;
  diagnostic_hits_avg?: number;
  top_topics?: Record<string, number>;
  confidence_buckets?: Record<string, number>;
  semantic_levels?: Record<string, number>;
  intent_distribution?: Record<string, number>;
  histograms?: {
    top_score?: Array<{ from: number; to: number; count: number }>;
    confidence_score?: Array<{ from: number; to: number; count: number }>;
    latency_ms?: Array<{ from: number; to: number; count: number }>;
  };
  correlations?: {
    confidence_vs_top_score?: Array<{ x: number; y: number; fallback?: boolean; semantic_level?: string | null }>;
    latency_vs_reply_len?: Array<{ x: number; y: number }>;
    rag_hits_vs_confidence?: Array<{ x: number; y: number }>;
  };
  daily?: Array<{
    date: string;
    messages_total: number;
    messages_user: number;
    messages_assistant: number;
    fallback_messages: number;
    fallback_rate_percent?: number;
    sessions: number;
    confidence_avg?: number;
    latency_avg_ms?: number;
  }>;
  topic_daily?: Record<string, Record<string, number>>;
  confidence_by_topic?: Array<{
    topic: string;
    confidence_avg: number;
    sessions?: number;
  }>;
  confidence_by_semantic_level?: Array<{
    semantic_level: string;
    confidence_avg: number;
    count: number;
  }>;
  business_coverage?: {
    queries_total?: number;
    queries_uncovered_count?: number;
    queries_uncovered_percent?: number;
    queries_low_top_score_count?: number;
    queries_low_confidence_count?: number;
    recurring_keywords?: Array<{ keyword: string; count: number }>;
    queries_not_covered?: Array<{
      session_id: string;
      query: string;
      fallback?: boolean;
      top_score?: number | null;
      confidence_score?: number | null;
    }>;
    problematic_topics?: Array<{
      topic: string;
      volume: number;
      fallback_count: number;
      fallback_rate_percent: number;
    }>;
  };
};

type TenantOption = {
  id: string;
  name: string;
  pipeline?: string | null;
  chat_model?: string | null;
  knowledge_tenant?: string | null;
};

type SessionSummary = {
  session_id: string;
  messages_total: number;
  messages_user: number;
  messages_assistant: number;
  last_at: string;
  pipeline?: string | null;
  model?: string | null;
  knowledge_tenant?: string | null;
  intent?: string | null;
  fallback_count?: number;
  contradiction_count?: number;
  avg_confidence?: number;
  max_latency_ms?: number;
};

type SessionDetailMessage = {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  source: string;
  tokens_est?: number | null;
  metadata?: Record<string, unknown>;
  created_at: string | null;
};

type SessionDetailEvent = {
  id: number;
  event_at: string | null;
  role: string;
  pipeline?: string | null;
  model?: string | null;
  intent?: string | null;
  fallback?: boolean;
  contradiction_flag?: boolean;
  confidence_score?: number | null;
  rag_hits?: number | null;
  top_score?: number | null;
  latency_ms?: number | null;
  policy_path?: string | null;
  metadata?: Record<string, unknown>;
};

type SessionDetailResponse = {
  tenant: string;
  session_id: string;
  messages: SessionDetailMessage[];
  events: SessionDetailEvent[];
  timeline: Array<{
    type: 'message' | 'event';
    at: string | null;
    role: string;
    summary: string;
    payload: unknown;
  }>;
};

function n(value: number | undefined | null, digits = 0): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '0';
  return value.toLocaleString('it-IT', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  });
}

function shortSession(id: string): string {
  if (!id) return '-';
  if (id.length <= 16) return id;
  return `${id.slice(0, 8)}...${id.slice(-6)}`;
}

function formatMs(value: number | undefined | null): string {
  if (!Number.isFinite(Number(value)) || Number(value) <= 0) {
    return '- ms';
  }

  return `${n(Number(value))} ms`;
}

function buildAxisLevels(maxValue: number, steps = 3): number[] {
  const safeMax = Math.max(0, maxValue);
  if (safeMax === 0) {
    return [0, 0, 0];
  }

  const values: number[] = [];
  for (let i = steps; i >= 1; i -= 1) {
    values.push(Math.round((safeMax * i) / steps));
  }
  return values;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

function safeMax(values: Array<number | null | undefined>, fallback = 1): number {
  const cleaned = values
    .map((value) => Number(value))
    .filter((value) => Number.isFinite(value) && value > 0);

  return cleaned.length > 0 ? Math.max(...cleaned) : fallback;
}

function TitleWithInfo({ title, infoTitle, lines }: { title: string; infoTitle: string; lines: string[] }) {
  return (
    <div className={styles.titleWithInfo}>
      <div className={styles.blockTitle}>{title}</div>
      <span className={styles.infoHint}>
        <span className={styles.infoIcon}>i</span>
        <span className={styles.infoCard}>
          <strong>{infoTitle}</strong>
          {lines.map((line) => (
            <span key={`${title}-${line}`}>{line}</span>
          ))}
        </span>
      </span>
    </div>
  );
}

export default function ReportPage() {
  const [tenant, setTenant] = useState('charlotte_text');
  const [tenantOptions, setTenantOptions] = useState<TenantOption[]>([]);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [rangePreset, setRangePreset] = useState('7d');
  const [viewMode, setViewMode] = useState<'business' | 'technical'>('business');

  const [pipeline] = useState('');
  const [model] = useState('');
  const [knowledgeTenant] = useState('');

  const [sessionTopic, setSessionTopic] = useState('');
  const [sessionFallbackOnly, setSessionFallbackOnly] = useState(false);
  const [sessionContradictionOnly, setSessionContradictionOnly] = useState(false);
  const [sessionLowConfidenceOnly, setSessionLowConfidenceOnly] = useState(false);
  const [sessionHighLatencyOnly, setSessionHighLatencyOnly] = useState(false);

  const [kpi, setKpi] = useState<KpiResponse | null>(null);
  const [sessions, setSessions] = useState<SessionSummary[]>([]);
  const [selectedSession, setSelectedSession] = useState('');
  const [sessionDetail, setSessionDetail] = useState<SessionDetailResponse | null>(null);
  const [scatterTooltip, setScatterTooltip] = useState<{ left: number; top: number; text: string } | null>(null);
  const [techScatterTooltip, setTechScatterTooltip] = useState<{ chart: 'a' | 'b' | 'c'; left: number; top: number; text: string } | null>(null);
  const scatterFrameRef = useRef<HTMLDivElement | null>(null);
  const scatterFrameSmARef = useRef<HTMLDivElement | null>(null);
  const scatterFrameSmBRef = useRef<HTMLDivElement | null>(null);
  const scatterFrameSmCRef = useRef<HTMLDivElement | null>(null);

  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (tenant) params.set('tenant', tenant);
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (pipeline) params.set('pipeline', pipeline);
    if (model) params.set('model', model);
    if (knowledgeTenant) params.set('knowledge_tenant', knowledgeTenant);
    return params.toString();
  }, [tenant, from, to, pipeline, model, knowledgeTenant]);

  const sessionsQueryString = useMemo(() => {
    const params = new URLSearchParams(queryString);
    if (sessionTopic) params.set('topic', sessionTopic);
    if (sessionFallbackOnly) params.set('fallback', '1');
    if (sessionContradictionOnly) params.set('contradiction', '1');
    if (sessionLowConfidenceOnly) params.set('low_confidence', '1');
    if (sessionHighLatencyOnly) params.set('high_latency', '1');
    return params.toString();
  }, [
    queryString,
    sessionTopic,
    sessionFallbackOnly,
    sessionContradictionOnly,
    sessionLowConfidenceOnly,
    sessionHighLatencyOnly,
  ]);

  useEffect(() => {
    const controller = new AbortController();
    fetch(`${BACKEND_URL}/api/report/tenants`, { signal: controller.signal })
      .then((response) => (response.ok ? response.json() : null))
      .then((payload) => {
        const list = Array.isArray(payload?.tenants) ? payload.tenants : [];
        setTenantOptions(list);
        if (list.length > 0 && !list.find((item: TenantOption) => item.id === tenant)) {
          setTenant(list[0].id);
        }
      })
      .catch(() => {});

    return () => controller.abort();
  }, [tenant]);

  useEffect(() => {
    if (rangePreset === 'custom') return;
    const today = new Date();
    const end = today.toISOString().slice(0, 10);
    let startDate = new Date();
    if (rangePreset === 'today') {
      startDate = today;
    } else if (rangePreset === '7d') {
      startDate.setDate(today.getDate() - 6);
    } else if (rangePreset === '30d') {
      startDate.setDate(today.getDate() - 29);
    } else if (rangePreset === 'month') {
      startDate = new Date(today.getFullYear(), today.getMonth(), 1);
    }
    setFrom(startDate.toISOString().slice(0, 10));
    setTo(end);
  }, [rangePreset]);

  const fetchSessions = useCallback(async () => {
    const response = await fetch(`${BACKEND_URL}/api/report/sessions?${sessionsQueryString}`);
    if (!response.ok) return;

    const payload = await response.json();
    const list = Array.isArray(payload.sessions) ? payload.sessions : [];
    setSessions(list);
    if (list.length > 0) {
      setSelectedSession((prev) => prev || list[0].session_id);
    } else {
      setSelectedSession('');
      setSessionDetail(null);
    }
  }, [sessionsQueryString]);

  const handleFetch = async () => {
    setError('');
    setLoading(true);
    try {
      const response = await fetch(`${BACKEND_URL}/api/report/kpi?${queryString}`);
      if (!response.ok) throw new Error(`Errore ${response.status}`);
      const payload = (await response.json()) as KpiResponse;
      setKpi(payload);
      await fetchSessions();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Errore nel recupero KPI');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!selectedSession) return;
    const controller = new AbortController();
    fetch(
      `${BACKEND_URL}/api/report/session/${encodeURIComponent(selectedSession)}?tenant=${encodeURIComponent(tenant)}`,
      { signal: controller.signal },
    )
      .then((response) => (response.ok ? response.json() : null))
      .then((payload) => setSessionDetail(payload as SessionDetailResponse))
      .catch(() => {});

    return () => controller.abort();
  }, [selectedSession, tenant]);

  useEffect(() => {
    if (!kpi) return;
    fetchSessions().catch(() => {});
  }, [kpi, fetchSessions]);

  const handleExport = async () => {
    try {
      const response = await fetch(`${BACKEND_URL}/api/report/export?${queryString}`);
      if (!response.ok) throw new Error(`Errore export ${response.status}`);
      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `report_${tenant || 'default'}.csv`;
      link.click();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Errore export');
    }
  };

  const topTopics = useMemo(() => Object.entries(kpi?.top_topics ?? {}).slice(0, 10), [kpi]);
  const intents = useMemo(() => Object.entries(kpi?.intent_distribution ?? {}).slice(0, 8), [kpi]);
  const daily = useMemo(() => kpi?.daily ?? [], [kpi]);

  const sessionBars = useMemo(() => {
    if (!daily.length) return [];
    const max = Math.max(...daily.map((d) => d.sessions));
    return daily.map((d) => ({ date: d.date, value: d.sessions, pct: max > 0 ? (d.sessions / max) * 100 : 0 }));
  }, [daily]);

  const fallbackBars = useMemo(() => {
    if (!daily.length) return [];
    const max = Math.max(...daily.map((d) => d.fallback_messages));
    return daily.map((d) => ({
      date: d.date,
      value: d.fallback_messages,
      rate: Number.isFinite(Number(d.fallback_rate_percent)) ? Number(d.fallback_rate_percent) : 0,
      pct: max > 0 ? (d.fallback_messages / max) * 100 : 0,
    }));
  }, [daily]);

  const latencyBars = useMemo(() => {
    const histogram = kpi?.histograms?.latency_ms ?? [];
    const max = Math.max(1, ...histogram.map((b) => b.count));
    return histogram.map((b) => ({
      label: `${Math.round(b.from)}-${Math.round(b.to)}`,
      value: b.count,
      pct: (b.count / max) * 100,
    }));
  }, [kpi]);

  const confidenceBars = useMemo(() => {
    const histogram = kpi?.histograms?.confidence_score ?? [];
    const max = Math.max(1, ...histogram.map((b) => b.count));
    return histogram.map((b) => ({
      label: `${Math.round(b.from)}-${Math.round(b.to)}`,
      value: b.count,
      pct: (b.count / max) * 100,
    }));
  }, [kpi]);

  const kpiCards = [
    { label: 'Sessioni', value: n(kpi?.sessions) },
    { label: 'Utenti Unici', value: n(kpi?.users_unique) },
    { label: 'Messaggi User', value: n(kpi?.messages_user) },
    { label: 'Fallback Rate', value: `${n(kpi?.fallback_rate_percent, 1)}%` },
    { label: 'Confidence Media', value: n(kpi?.confidence_avg, 1) },
    { label: 'Latenza Media', value: formatMs(kpi?.latency_avg_ms) },
  ];

  const selectedSessionMeta = sessions.find((s) => s.session_id === selectedSession);
  const businessCoverage = kpi?.business_coverage;
  const confidenceBucketsList = Object.entries(kpi?.confidence_buckets ?? {});
  const semanticLevelsList = Object.entries(kpi?.semantic_levels ?? {});
  const maxSessionValue = sessionBars.length > 0 ? Math.max(...sessionBars.map((item) => item.value)) : 0;
  const maxFallbackValue = fallbackBars.length > 0 ? Math.max(...fallbackBars.map((item) => item.value)) : 0;
  const sessionAxisLevels = buildAxisLevels(maxSessionValue, 3);
  const fallbackAxisLevels = buildAxisLevels(maxFallbackValue, 3);
  const confidenceTopicRows = useMemo(
    () => (kpi?.confidence_by_topic ?? []).slice(0, 8),
    [kpi],
  );
  const confidenceSemanticRows = useMemo(
    () => (kpi?.confidence_by_semantic_level ?? []),
    [kpi],
  );
  const confidenceHistogramSamples = confidenceBars.reduce((sum, item) => sum + item.value, 0);
  const maxConfidenceHistogramValue = confidenceBars.length > 0 ? Math.max(...confidenceBars.map((item) => item.value)) : 0;
  const confidenceHistogramAxisLevels = buildAxisLevels(maxConfidenceHistogramValue, 3);
  const maxLatencyHistogramValue = latencyBars.length > 0 ? Math.max(...latencyBars.map((item) => item.value)) : 0;
  const latencyHistogramAxisLevels = buildAxisLevels(maxLatencyHistogramValue, 3);

  const correlationA = kpi?.correlations?.confidence_vs_top_score ?? [];
  const correlationB = kpi?.correlations?.latency_vs_reply_len ?? [];
  const correlationC = kpi?.correlations?.rag_hits_vs_confidence ?? [];
  const maxLatencyCorrX = safeMax(correlationB.map((point) => point.x), 1);
  const maxReplyLenCorrY = safeMax(correlationB.map((point) => point.y), 1);
  const maxRagHitsCorrX = safeMax(correlationC.map((point) => point.x), 1);
  const latencyDailyRows = useMemo(() => {
    return daily.slice(-14).map((row) => ({
      date: row.date,
      value: Number.isFinite(Number(row.latency_avg_ms)) && Number(row.latency_avg_ms) > 0
        ? Number(row.latency_avg_ms)
        : null,
    }));
  }, [daily]);
  const maxLatencyDailyValue = latencyDailyRows.length > 0
    ? Math.max(...latencyDailyRows.map((row) => row.value ?? 0))
    : 0;
  const latencyDailyAxisLevels = buildAxisLevels(maxLatencyDailyValue, 3);
  const hasLatencyDailyData = latencyDailyRows.some((row) => row.value !== null);
  const scatterChart = {
    left: 28,
    right: 344,
    top: 10,
    bottom: 138,
  };
  const scatterXTicks = [0, 0.2, 0.4, 0.6, 0.8, 1];
  const scatterYTicks = [0, 20, 40, 60, 80, 100];
  const scatterWidth = scatterChart.right - scatterChart.left;
  const scatterHeight = scatterChart.bottom - scatterChart.top;
  const scatterThresholdX = scatterChart.left + (0.6 * scatterWidth);
  const scatterThresholdY = scatterChart.bottom - ((60 / 100) * scatterHeight);

  const onScatterPointHover = (
    event: React.MouseEvent<SVGCircleElement>,
    point: { x: number; y: number; fallback?: boolean },
  ) => {
    if (!scatterFrameRef.current) return;

    const rect = scatterFrameRef.current.getBoundingClientRect();
    const left = clamp(event.clientX - rect.left + 12, 14, rect.width - 14);
    const top = clamp(event.clientY - rect.top - 10, 12, rect.height - 12);

    setScatterTooltip({
      left,
      top,
      text: `Top score: ${n(point.x, 3)} | Confidence: ${n(point.y, 1)} | Fallback: ${point.fallback ? 'si' : 'no'}`,
    });
  };

  const onTechScatterPointHover = (
    event: React.MouseEvent<SVGCircleElement>,
    frameRef: React.RefObject<HTMLDivElement | null>,
    chart: 'a' | 'b' | 'c',
    text: string,
  ) => {
    if (!frameRef.current) return;

    const rect = frameRef.current.getBoundingClientRect();
    const left = clamp(event.clientX - rect.left + 10, 12, rect.width - 12);
    const top = clamp(event.clientY - rect.top - 10, 10, rect.height - 10);

    setTechScatterTooltip({
      chart,
      left,
      top,
      text,
    });
  };

  return (
    <div className={styles.page}>
        <div className={styles.topbar}>
        <div className={styles.brand}>CHArlotTe Analytics</div>
        <div className={styles.topActions}>
          <button
            className={`${styles.topBtn} ${viewMode === 'business' ? styles.topBtnActive : ''}`}
            onClick={() => setViewMode('business')}
          >
            Vista Business
          </button>
          <button
            className={`${styles.topBtn} ${viewMode === 'technical' ? styles.topBtnActive : ''}`}
            onClick={() => setViewMode('technical')}
          >
            Vista Tecnica
          </button>
          <button className={styles.topBtn} onClick={handleExport}>Export CSV</button>
        </div>
      </div>

      <div className={styles.shell}>
        <section className={styles.controlCard}>
          <div className={styles.controlTitle}>Overview KPI Generali</div>
          <div className={styles.filtersRow}>
            <div className={styles.field}>
              <label>Tenant</label>
              <select value={tenant} onChange={(e) => setTenant(e.target.value)}>
                {tenantOptions.length === 0
                  ? <option value={tenant}>{tenant}</option>
                  : tenantOptions.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}
              </select>
            </div>
            <div className={styles.field}>
              <label>Range</label>
              <select value={rangePreset} onChange={(e) => setRangePreset(e.target.value)}>
                <option value="custom">Personalizzato</option>
                <option value="today">Oggi</option>
                <option value="7d">Ultimi 7 giorni</option>
                <option value="30d">Ultimi 30 giorni</option>
                <option value="month">Questo mese</option>
              </select>
            </div>
            <div className={styles.field}>
              <label>Da</label>
              <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </div>
            <div className={styles.field}>
              <label>A</label>
              <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </div>
            <button className={styles.loadBtn} onClick={handleFetch} disabled={loading}>
              {loading ? 'Caricamento...' : 'Aggiorna'}
            </button>
          </div>
          {error ? <div className={styles.error}>{error}</div> : null}
        </section>

        <section className={styles.gridTop}>
          <div className={`${styles.panel} ${styles.overviewPanel}`}>
            <div className={styles.titleWithInfo}>
              <div className={styles.panelTitle}>Overview KPI Generali</div>
              <span className={styles.infoHint}>
                <span className={styles.infoIcon}>i</span>
                <span className={styles.infoCard}>
                  <strong>Cosa mostrano i KPI</strong>
                  <span>Sessioni: numero di sessioni aperte.</span>
                  <span>Utenti Unici: utenti univoci che hanno interagito.</span>
                  <span>Messaggi User: messaggi inviati dagli utenti.</span>
                  <span>Fallback Rate: quota risposte in fallback.</span>
                  <span>Confidence Media: confidenza media risposte assistant.</span>
                  <span>Latenza Media: tempo medio risposta assistant.</span>
                </span>
              </span>
            </div>
            <div className={styles.kpiGrid}>
              {kpiCards.map((card) => (
                <div key={card.label} className={styles.kpiCard}>
                  <div className={styles.kpiLabel}>{card.label}</div>
                  <div className={styles.kpiValue}>{card.value}</div>
                </div>
              ))}
            </div>
            <div className={styles.overviewChartsRow}>
              <div className={styles.overviewChartCol}>
                <div className={styles.titleWithInfo}>
                  <div className={styles.panelSubTitle}>Sessioni negli ultimi giorni</div>
                  <span className={styles.infoHint}>
                    <span className={styles.infoIcon}>i</span>
                    <span className={styles.infoCard}>
                      <strong>Sessioni negli ultimi giorni</strong>
                      <span>Grafico a barre del numero di sessioni per giorno nel periodo selezionato.</span>
                    </span>
                  </span>
                </div>
                <div className={`${styles.chartWithAxis} ${styles.overviewChartPrimary}`}>
                  <div className={styles.yAxis}>
                    <div className={styles.yAxisTitle}>Sessioni</div>
                    <div className={styles.yTicks}>
                      {sessionAxisLevels.map((level, idx) => (
                        <div key={`sess-level-${idx}`} className={styles.yTick}>{level}</div>
                      ))}
                    </div>
                  </div>
                  <div className={styles.chartArea}>
                    <div className={styles.miniBars}>
                      <div className={styles.gridOverlay}>
                        <span />
                        <span />
                        <span />
                      </div>
                      {sessionBars.length > 0 ? sessionBars.slice(-14).map((bar) => (
                        <div key={`sess-${bar.date}`} className={styles.miniBarCol}>
                          <div
                            className={styles.miniBar}
                            data-tooltip={`${bar.value} sessioni`}
                            style={{ height: `${Math.max(6, bar.pct)}%` }}
                          />
                          <span>{bar.date.slice(5)}</span>
                        </div>
                      )) : <div className={styles.emptyStateTech}>Nessun trend disponibile nel periodo selezionato</div>}
                    </div>
                    <div className={styles.singleLegend}>
                      <span><i className={styles.mixDotAssistant} /> Numero sessioni</span>
                    </div>
                    <div className={styles.xAxisTitle}>Asse X: Giorni</div>
                  </div>
                </div>
              </div>

              <div className={styles.overviewChartCol}>
                <div className={styles.titleWithInfo}>
                  <div className={styles.panelSubTitle}>Latency media giornaliera</div>
                  <span className={styles.infoHint}>
                    <span className={styles.infoIcon}>i</span>
                    <span className={styles.infoCard}>
                      <strong>Latency media giornaliera</strong>
                      <span>Grafico a barre della latenza media (ms) delle risposte assistant per giorno.</span>
                    </span>
                  </span>
                </div>
                <div className={`${styles.chartWithAxis} ${styles.overviewChartPrimary}`}>
                  <div className={styles.yAxis}>
                    <div className={styles.yAxisTitle}>ms</div>
                    <div className={styles.yTicks}>
                      {latencyDailyAxisLevels.map((level, idx) => (
                        <div key={`lat-day-level-${idx}`} className={styles.yTick}>{level}</div>
                      ))}
                    </div>
                  </div>
                  <div className={styles.chartArea}>
                    <div className={styles.miniBars}>
                      <div className={styles.gridOverlay}>
                        <span />
                        <span />
                        <span />
                      </div>
                      {hasLatencyDailyData ? latencyDailyRows.map((row) => (
                        <div key={`lat-day-${row.date}`} className={styles.miniBarCol}>
                          <div
                            className={styles.miniBarLatency}
                            data-tooltip={row.value !== null ? `${Math.round(row.value)} ms` : '- ms'}
                            style={{
                              height: `${maxLatencyDailyValue > 0 && row.value !== null
                                ? Math.max(6, (row.value / maxLatencyDailyValue) * 100)
                                : 6}%`,
                            }}
                          />
                          <span>{row.date.slice(5)}</span>
                        </div>
                      )) : <div className={styles.emptyStateTech}>Nessun dato latenza nel periodo selezionato</div>}
                    </div>
                    <div className={styles.singleLegend}>
                      <span><i className={styles.mixDotUser} /> Latency media (ms)</span>
                    </div>
                    <div className={styles.xAxisTitle}>Asse X: Giorni</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div className={`${styles.panel} ${styles.confidencePanel}`}>
            <div className={styles.panelTitle}>Confidence & Fallback</div>

            <TitleWithInfo
              title="Fallback per giorno"
              infoTitle="Fallback per giorno"
              lines={[
                'Mostra il numero di risposte in fallback per ciascun giorno.',
                'Hover su ogni barra: valore assoluto e percentuale fallback del giorno.',
              ]}
            />
            <div className={styles.chartWithAxis}>
              <div className={styles.yAxis}>
                <div className={styles.yAxisTitle}>Fallback</div>
                <div className={styles.yTicks}>
                  {fallbackAxisLevels.map((level, idx) => (
                    <div key={`fb-level-${idx}`} className={styles.yTick}>{level}</div>
                  ))}
                </div>
              </div>
              <div className={styles.chartArea}>
                <div className={styles.miniBars}>
                  <div className={styles.gridOverlay}>
                    <span />
                    <span />
                    <span />
                  </div>
                  {fallbackBars.length > 0 ? fallbackBars.slice(-14).map((bar) => (
                    <div key={`fb-${bar.date}`} className={styles.miniBarCol}>
                      <div
                        className={`${styles.miniBar} ${styles.warn}`}
                        data-tooltip={`${bar.value} fallback (${n(bar.rate, 1)}%)`}
                        style={{ height: `${Math.max(6, bar.pct)}%` }}
                      />
                      <span>{bar.date.slice(5)}</span>
                    </div>
                  )) : <div className={styles.emptyStateTech}>Nessun fallback registrato nel periodo</div>}
                </div>
                <div className={styles.singleLegend}>
                  <span><i className={styles.mixDotUser} /> Numero fallback</span>
                </div>
                <div className={styles.xAxisTitle}>Asse X: Giorni</div>
              </div>
            </div>

            <div className={styles.confidenceGrid}>
              <div className={styles.confidenceBlock}>
                <TitleWithInfo
                  title="Distribuzione Confidence"
                  infoTitle="Distribuzione Confidence"
                  lines={[
                    'Istogramma dei punteggi confidence delle risposte assistant.',
                    'Asse X: bucket score, Asse Y: numero risposte nel bucket.',
                  ]}
                />
                <div className={styles.chartMeta}>n={n(confidenceHistogramSamples)}</div>
                <div className={styles.chartWithAxis}>
                  <div className={styles.yAxis}>
                    <div className={styles.yAxisTitle}>Count</div>
                    <div className={styles.yTicks}>
                      {confidenceHistogramAxisLevels.map((level, idx) => (
                        <div key={`conf-level-${idx}`} className={styles.yTick}>{level}</div>
                      ))}
                    </div>
                  </div>
                  <div className={styles.chartArea}>
                    <div className={styles.histogram}>
                      {confidenceBars.length > 0 ? confidenceBars.map((bar) => (
                        <div key={`conf-primary-${bar.label}`} className={styles.histBarCol}>
                          <div
                            className={`${styles.histBar} ${styles.info}`}
                            data-tooltip={`${bar.value} risposte`}
                            style={{ height: `${Math.max(6, bar.pct)}%` }}
                          />
                          <span>{bar.label}</span>
                        </div>
                      )) : <div className={styles.emptyStateTech}>Nessun dato confidence nel periodo selezionato</div>}
                    </div>
                    <div className={styles.singleLegend}>
                      <span><i className={styles.mixDotAssistant} /> Risposte per bucket confidence</span>
                    </div>
                    <div className={styles.xAxisTitle}>Asse X: Bucket confidence score</div>
                  </div>
                </div>
              </div>

              <div className={`${styles.confidenceBlock} ${styles.confidenceTopicBlock}`}>
                <TitleWithInfo
                  title="Confidence per Topic"
                  infoTitle="Confidence per Topic"
                  lines={[
                    'Media confidence per argomento (topic).',
                    'Scala fissa 0-100 per confronto coerente tra topic.',
                  ]}
                />
                <div className={`${styles.topicRows} ${styles.topicRowsCompact}`}>
                  {confidenceTopicRows.length > 0 ? confidenceTopicRows.map((item) => {
                    const width = clamp(item.confidence_avg, 0, 100);
                    return (
                      <div key={`ct-${item.topic}`} className={`${styles.topicRow} ${styles.topicRowMetric}`}>
                        <span>{item.topic}</span>
                        <div className={styles.topicBar}>
                          <i
                            style={{ width: `${width}%` }}
                            data-tooltip={`${n(item.confidence_avg, 1)} su 100`}
                          />
                        </div>
                        <strong>{n(item.confidence_avg, 1)} <small>(n={n(item.sessions)})</small></strong>
                      </div>
                    );
                  }) : <div className={styles.emptyStateTech}>Nessun dato topic-confidence disponibile</div>}
                </div>
                <div className={styles.topicScaleHint}>
                  <span>0</span>
                  <span>Scala confidence (0-100)</span>
                  <span>100</span>
                </div>
              </div>
            </div>

            <div className={`${styles.confidenceBlock} ${styles.confidenceSemanticBlock}`}>
              <TitleWithInfo
                title="Confidence per Semantic Level"
                infoTitle="Confidence per Semantic Level"
                lines={[
                  'Media confidence per livello semantico di retrieval.',
                  'Scala fissa 0-100 per confronto proporzionato tra livelli.',
                ]}
              />
              <div className={`${styles.topicRows} ${styles.topicRowsCompact}`}>
                {confidenceSemanticRows.length > 0 ? confidenceSemanticRows.map((item) => {
                  const width = clamp(item.confidence_avg, 0, 100);
                  return (
                    <div key={`cs-${item.semantic_level}`} className={`${styles.topicRow} ${styles.topicRowMetric}`}>
                      <span>{item.semantic_level}</span>
                      <div className={styles.topicBar}>
                        <i
                          style={{ width: `${width}%` }}
                          data-tooltip={`${n(item.confidence_avg, 1)} su 100`}
                        />
                      </div>
                      <strong>{n(item.confidence_avg, 1)} <small>(n={n(item.count)})</small></strong>
                    </div>
                  );
                }) : <div className={styles.emptyStateTech}>Nessun dato semantic-level disponibile</div>}
              </div>
              <div className={styles.topicScaleHint}>
                <span>0</span>
                <span>Scala confidence (0-100)</span>
                <span>100</span>
              </div>
            </div>

            <TitleWithInfo
              title="Confidence vs Top Score"
              infoTitle="Confidence vs Top Score"
              lines={[
                'Scatter: ogni punto è una risposta assistant.',
                'Asse X: top score RAG (0-1), Asse Y: confidence (0-100).',
                'Colori: verde no fallback, arancione fallback.',
              ]}
            />
            <div className={styles.scatterCard}>
              {correlationA.length > 0 ? (
                <div
                  className={styles.scatterFrame}
                  ref={scatterFrameRef}
                  onMouseLeave={() => setScatterTooltip(null)}
                >
                <svg viewBox="0 0 360 160" className={styles.scatterPlotWide}>
                  <line
                    x1={scatterChart.left}
                    y1={scatterChart.bottom}
                    x2={scatterChart.right}
                    y2={scatterChart.bottom}
                    className={styles.scatterAxisLine}
                  />
                  <line
                    x1={scatterChart.left}
                    y1={scatterChart.top}
                    x2={scatterChart.left}
                    y2={scatterChart.bottom}
                    className={styles.scatterAxisLine}
                  />
                  {scatterXTicks.map((tick) => {
                    const x = scatterChart.left + (tick * scatterWidth);
                    return (
                      <g key={`scatter-x-${tick}`}>
                        <line x1={x} y1={scatterChart.bottom} x2={x} y2={scatterChart.bottom + 4} className={styles.scatterTickLine} />
                        <text x={x} y={scatterChart.bottom + 14} textAnchor="middle" className={styles.scatterTickLabel}>
                          {n(tick, 1)}
                        </text>
                      </g>
                    );
                  })}
                  {scatterYTicks.map((tick) => {
                    const y = scatterChart.bottom - ((tick / 100) * scatterHeight);
                    return (
                      <g key={`scatter-y-${tick}`}>
                        <line x1={scatterChart.left - 4} y1={y} x2={scatterChart.left} y2={y} className={styles.scatterTickLine} />
                        <text x={scatterChart.left - 8} y={y + 3} textAnchor="end" className={styles.scatterTickLabel}>
                          {tick}
                        </text>
                      </g>
                    );
                  })}
                  <line x1={scatterThresholdX} y1={scatterChart.top} x2={scatterThresholdX} y2={scatterChart.bottom} className={styles.scatterThresholdX} />
                  <line x1={scatterChart.left} y1={scatterThresholdY} x2={scatterChart.right} y2={scatterThresholdY} className={styles.scatterThresholdY} />
                  {correlationA.slice(0, 350).map((point, idx) => (
                    <g key={`cf-main-${idx}`}>
                      <circle
                        cx={Math.max(scatterChart.left, Math.min(scatterChart.right, point.x * scatterWidth + scatterChart.left))}
                        cy={Math.max(scatterChart.top, Math.min(scatterChart.bottom, scatterChart.bottom - ((point.y ?? 0) / 100) * scatterHeight))}
                        r={3.1}
                        className={point.fallback ? styles.scatterDotFallback : styles.scatterDotA}
                      />
                      <circle
                        cx={Math.max(scatterChart.left, Math.min(scatterChart.right, point.x * scatterWidth + scatterChart.left))}
                        cy={Math.max(scatterChart.top, Math.min(scatterChart.bottom, scatterChart.bottom - ((point.y ?? 0) / 100) * scatterHeight))}
                        r={8}
                        className={styles.scatterPointHover}
                        onMouseEnter={(event) => onScatterPointHover(event, point)}
                        onMouseMove={(event) => onScatterPointHover(event, point)}
                      />
                    </g>
                  ))}
                </svg>
                {scatterTooltip ? (
                  <div
                    className={styles.scatterHoverTooltip}
                    style={{ left: `${scatterTooltip.left}px`, top: `${scatterTooltip.top}px` }}
                  >
                    {scatterTooltip.text}
                  </div>
                ) : null}
                <div className={styles.scatterAxisX}>Asse X: Top Score (0-1)</div>
                <div className={styles.scatterAxisY}>Asse Y: Confidence (0-100)</div>
                </div>
              ) : <div className={styles.emptyStateTech}>Campione insufficiente per scatter confidence/top score</div>}
              <div className={styles.scatterLegend}>
                <span><i className={styles.mixDotAssistant} /> No fallback</span>
                <span><i className={styles.mixDotWarn} /> Fallback</span>
                <span>soglie: top_score 0.6 / confidence 60</span>
              </div>
            </div>
          </div>
        </section>

        <section className={styles.gridMiddle}>
          <div className={styles.panel}>
            <div className={styles.panelTitle}>Analisi Business</div>
            <div className={styles.splitColumns}>
              <div>
                <div className={styles.blockTitle}>Top Argomenti</div>
                <div className={styles.topicRows}>
                  {(businessCoverage?.problematic_topics?.length ?? 0) > 0 ? businessCoverage!.problematic_topics!.map((item) => {
                    const max = Math.max(1, ...(businessCoverage!.problematic_topics!.map((row) => row.fallback_rate_percent)));
                    const width = (item.fallback_rate_percent / max) * 100;
                    return (
                      <div key={item.topic} className={styles.topicRow}>
                        <span>{item.topic}</span>
                        <div className={styles.topicBar}><i style={{ width: `${width}%` }} /></div>
                        <strong>{n(item.fallback_rate_percent, 1)}%</strong>
                      </div>
                    );
                  }) : <div className={styles.emptyStateTech}>Nessun topic problematico rilevato</div>}
                </div>
              </div>
              <div>
                <div className={styles.blockTitle}>Intent Distribution</div>
                <div className={styles.listSimple}>
                  {intents.length > 0 ? intents.map(([name, count]) => (
                    <div key={name} className={styles.listItem}><span>{name}</span><strong>{count}</strong></div>
                  )) : <div className={styles.emptyStateTech}>Nessun intent disponibile nel periodo</div>}
                </div>
              </div>
            </div>
            <div className={styles.blockTitle}>Keyword / Query Coverage</div>
            <div className={styles.coverageGrid}>
              <div className={styles.coverageCard}>
                <div className={styles.coverageLabel}>Query non coperte dal RAG</div>
                <div className={styles.coverageValue}>
                  {n(businessCoverage?.queries_uncovered_count)} / {n(businessCoverage?.queries_total)}
                </div>
                <div className={styles.coverageSub}>
                  {n(businessCoverage?.queries_uncovered_percent, 1)}%
                </div>
              </div>
              <div className={styles.coverageCard}>
                <div className={styles.coverageLabel}>Query con top_score basso</div>
                <div className={styles.coverageValue}>
                  {n(businessCoverage?.queries_low_top_score_count)}
                </div>
              </div>
              <div className={styles.coverageCard}>
                <div className={styles.coverageLabel}>Query con confidence bassa</div>
                <div className={styles.coverageValue}>
                  {n(businessCoverage?.queries_low_confidence_count)}
                </div>
              </div>
            </div>

            <div className={styles.coverageLists}>
              <div className={styles.coverageList}>
                <div className={styles.coverageListTitle}>Keyword Principali</div>
                {(businessCoverage?.recurring_keywords?.length ?? 0) > 0 ? (
                  businessCoverage!.recurring_keywords!.map((item) => (
                    <div key={item.keyword} className={styles.coverageListItem}>
                      <span>{item.keyword}</span>
                      <strong>{item.count}</strong>
                    </div>
                  ))
                ) : <div className={styles.emptyStateTech}>Nessuna keyword significativa nel periodo</div>}
              </div>
              <div className={styles.coverageList}>
                <div className={styles.coverageListTitle}>Query Non Coperte (sample)</div>
                {(businessCoverage?.queries_not_covered?.length ?? 0) > 0 ? (
                  businessCoverage!.queries_not_covered!.map((item, idx) => (
                    <div key={`${item.session_id}-${idx}`} className={styles.coverageQueryItem}>
                      <div>{item.query}</div>
                      <small>
                        s={shortSession(item.session_id)} | top={item.top_score ?? '-'} | conf={item.confidence_score ?? '-'} | fb={item.fallback ? '1' : '0'}
                      </small>
                    </div>
                  ))
                ) : <div className={styles.emptyStateTech}>Nessuna query critica rilevata</div>}
              </div>
            </div>
          </div>

          <div className={styles.panel}>
            <div className={styles.titleWithInfo}>
              <div className={styles.panelTitle}>Analisi Tecnica</div>
              <span className={styles.infoHint}>
                <span className={styles.infoIcon}>i</span>
                <span className={styles.infoCard}>
                  <strong>KPI tecnici</strong>
                  <span>RAG hits medi: media hit recuperati per risposta.</span>
                  <span>Top score medio: rilevanza media miglior chunk.</span>
                  <span>Accepted hits medi: media chunk accettati nel contesto.</span>
                  <span>Diagnostic hits medi: media hit diagnostici per analisi.</span>
                  <span>Contradiction rate: quota risposte con flag contraddizione.</span>
                  <span>Latency p95: latenza al 95° percentile.</span>
                </span>
              </span>
            </div>
            <div className={styles.techSummaryGrid}>
              <div className={styles.techSummaryCard}>
                <span>RAG hits medi</span>
                <strong>{n(kpi?.rag_hits_avg, 2)}</strong>
              </div>
              <div className={styles.techSummaryCard}>
                <span>Top score medio</span>
                <strong>{n(kpi?.top_score_avg, 3)}</strong>
              </div>
              <div className={styles.techSummaryCard}>
                <span>Accepted hits medi</span>
                <strong>{n(kpi?.accepted_hits_avg, 2)}</strong>
              </div>
              <div className={styles.techSummaryCard}>
                <span>Diagnostic hits medi</span>
                <strong>{n(kpi?.diagnostic_hits_avg, 2)}</strong>
              </div>
              <div className={styles.techSummaryCard}>
                <span>Contradiction rate</span>
                <strong>{n(kpi?.contradiction_rate_percent, 1)}%</strong>
              </div>
              <div className={styles.techSummaryCard}>
                <span>Latency p95</span>
                <strong>{n(kpi?.latency_p95_ms)} ms</strong>
              </div>
            </div>

            <TitleWithInfo
              title="Distribuzione Latenza"
              infoTitle="Distribuzione Latenza"
              lines={[
                'Istogramma dei tempi di risposta assistant (ms).',
                'Asse X: bucket latenza, Asse Y: numero risposte nel bucket.',
              ]}
            />
            <div className={styles.chartWithAxis}>
              <div className={styles.yAxis}>
                <div className={styles.yAxisTitle}>Count</div>
                <div className={styles.yTicks}>
                  {latencyHistogramAxisLevels.map((level, idx) => (
                    <div key={`lat-level-${idx}`} className={styles.yTick}>{level}</div>
                  ))}
                </div>
              </div>
              <div className={styles.chartArea}>
                <div className={styles.histogram}>
                  {latencyBars.length > 0 ? latencyBars.map((bar) => (
                    <div key={`lat-${bar.label}`} className={styles.histBarCol}>
                      <div
                        className={styles.histBar}
                        data-tooltip={`${bar.value} risposte`}
                        style={{ height: `${Math.max(6, bar.pct)}%` }}
                      />
                      <span>{bar.label}</span>
                    </div>
                  )) : <div className={styles.emptyStateTech}>Nessun dato latenza nel periodo selezionato</div>}
                </div>
                <div className={styles.singleLegend}>
                  <span><i className={styles.mixDotUser} /> Risposte per bucket latenza</span>
                </div>
                <div className={styles.xAxisTitle}>Asse X: Bucket latenza (ms)</div>
              </div>
            </div>

            <TitleWithInfo
              title="Distribuzione Confidence"
              infoTitle="Distribuzione Confidence"
              lines={[
                'Istogramma dei punteggi confidence delle risposte assistant.',
                'Asse X: bucket confidence, Asse Y: numero risposte.',
              ]}
            />
            <div className={styles.chartWithAxis}>
              <div className={styles.yAxis}>
                <div className={styles.yAxisTitle}>Count</div>
                <div className={styles.yTicks}>
                  {confidenceHistogramAxisLevels.map((level, idx) => (
                    <div key={`conf-tech-level-${idx}`} className={styles.yTick}>{level}</div>
                  ))}
                </div>
              </div>
              <div className={styles.chartArea}>
                <div className={styles.histogram}>
                  {confidenceBars.length > 0 ? confidenceBars.map((bar) => (
                    <div key={`conf-${bar.label}`} className={styles.histBarCol}>
                      <div
                        className={`${styles.histBar} ${styles.info}`}
                        data-tooltip={`${bar.value} risposte`}
                        style={{ height: `${Math.max(6, bar.pct)}%` }}
                      />
                      <span>{bar.label}</span>
                    </div>
                  )) : <div className={styles.emptyStateTech}>Nessun dato confidence nel periodo selezionato</div>}
                </div>
                <div className={styles.singleLegend}>
                  <span><i className={styles.mixDotAssistant} /> Risposte per bucket confidence</span>
                </div>
                <div className={styles.xAxisTitle}>Asse X: Bucket confidence score</div>
              </div>
            </div>

            <div className={styles.techSplit}>
              <div className={styles.techListCard}>
                <div className={styles.titleWithInfo}>
                  <div className={styles.techListTitle}>Confidence Buckets</div>
                  <span className={styles.infoHint}>
                    <span className={styles.infoIcon}>i</span>
                    <span className={styles.infoCard}>
                      <strong>Confidence Buckets</strong>
                      <span>Distribuzione delle risposte assistant per fascia di confidence.</span>
                      <span>Utile per capire quante risposte sono ad alta/bassa confidenza.</span>
                    </span>
                  </span>
                </div>
                {confidenceBucketsList.length > 0 ? confidenceBucketsList.map(([bucket, count]) => (
                  <div key={bucket} className={styles.techListItem}>
                    <span>{bucket}</span>
                    <strong>{n(count)}</strong>
                  </div>
                )) : <div className={styles.emptyStateTech}>Nessun bucket disponibile</div>}
              </div>
              <div className={styles.techListCard}>
                <div className={styles.titleWithInfo}>
                  <div className={styles.techListTitle}>Semantic Levels</div>
                  <span className={styles.infoHint}>
                    <span className={styles.infoIcon}>i</span>
                    <span className={styles.infoCard}>
                      <strong>Semantic Levels</strong>
                      <span>Classificazione dei risultati retrieval (es. high/medium/low).</span>
                      <span>Aiuta a leggere la qualità media del recupero contesto.</span>
                    </span>
                  </span>
                </div>
                {semanticLevelsList.length > 0 ? semanticLevelsList.map(([level, count]) => (
                  <div key={level} className={styles.techListItem}>
                    <span>{level}</span>
                    <strong>{n(count)}</strong>
                  </div>
                )) : <div className={styles.emptyStateTech}>Nessun livello semantico disponibile</div>}
              </div>
            </div>

            {viewMode === 'technical' ? (
              <>
                <TitleWithInfo
                  title="Correlazioni"
                  infoTitle="Correlazioni tecniche"
                  lines={[
                    'Ogni punto rappresenta una risposta assistant.',
                    'Gli scatter mostrano relazioni tra retrieval, quality e performance.',
                  ]}
                />
                <div className={styles.scatterGrid}>
                  <div className={styles.scatterCard}>
                    <div className={styles.titleWithInfo}>
                      <div className={styles.scatterTitle}>Confidence vs Top Score</div>
                      <span className={styles.infoHint}>
                        <span className={styles.infoIcon}>i</span>
                        <span className={styles.infoCard}>
                          <strong>Confidence vs Top Score</strong>
                          <span>Asse X: top score retrieval (0-1).</span>
                          <span>Asse Y: confidence risposta (0-100).</span>
                          <span>Nota: usa lo stesso dataset del grafico in "Confidence & Fallback", ma qui è una vista tecnica compatta senza distinzione fallback/no fallback.</span>
                        </span>
                      </span>
                    </div>
                    {correlationA.length > 0 ? (
                      <div
                        className={styles.scatterFrameSm}
                        ref={scatterFrameSmARef}
                        onMouseLeave={() => setTechScatterTooltip((current) => (current?.chart === 'a' ? null : current))}
                      >
                      <svg viewBox="0 0 220 130" className={styles.scatterPlot}>
                        <line x1="20" y1="112" x2="210" y2="112" className={styles.scatterAxisLine} />
                        <line x1="20" y1="8" x2="20" y2="112" className={styles.scatterAxisLine} />
                        {[0, 0.5, 1].map((tick) => {
                          const x = 20 + tick * 190;
                          return (
                            <g key={`ta-x-${tick}`}>
                              <line x1={x} y1="112" x2={x} y2="116" className={styles.scatterTickLine} />
                              <text x={x} y="126" textAnchor="middle" className={styles.scatterTickLabel}>{n(tick, 1)}</text>
                            </g>
                          );
                        })}
                        {[0, 50, 100].map((tick) => {
                          const y = 112 - (tick / 100) * 104;
                          return (
                            <g key={`ta-y-${tick}`}>
                              <line x1="16" y1={y} x2="20" y2={y} className={styles.scatterTickLine} />
                              <text x="13" y={y + 3} textAnchor="end" className={styles.scatterTickLabel}>{tick}</text>
                            </g>
                          );
                        })}
                        {correlationA.slice(0, 250).map((point, idx) => (
                          <g key={`a-${idx}`}>
                            <circle
                              cx={Math.max(20, Math.min(210, point.x * 190 + 20))}
                              cy={Math.max(8, Math.min(112, 112 - ((point.y ?? 0) / 100) * 104))}
                              r={2.8}
                              className={styles.scatterDotA}
                            />
                            <circle
                              cx={Math.max(20, Math.min(210, point.x * 190 + 20))}
                              cy={Math.max(8, Math.min(112, 112 - ((point.y ?? 0) / 100) * 104))}
                              r={8}
                              className={styles.scatterPointHover}
                              onMouseEnter={(event) => onTechScatterPointHover(event, scatterFrameSmARef, 'a', `top_score: ${n(point.x, 3)} | confidence: ${n(point.y, 1)}`)}
                              onMouseMove={(event) => onTechScatterPointHover(event, scatterFrameSmARef, 'a', `top_score: ${n(point.x, 3)} | confidence: ${n(point.y, 1)}`)}
                            />
                          </g>
                        ))}
                      </svg>
                      {techScatterTooltip?.chart === 'a' ? (
                        <div
                          className={styles.scatterHoverTooltip}
                          style={{ left: `${techScatterTooltip.left}px`, top: `${techScatterTooltip.top}px` }}
                        >
                          {techScatterTooltip.text}
                        </div>
                      ) : null}
                      <div className={styles.scatterAxisXSm}>X: Top Score (0-1)</div>
                      <div className={styles.scatterAxisYSm}>Y: Confidence (0-100)</div>
                      </div>
                    ) : <div className={styles.emptyStateTech}>Campione insufficiente per scatter</div>}
                  </div>
                  <div className={styles.scatterCard}>
                    <div className={styles.titleWithInfo}>
                      <div className={styles.scatterTitle}>Latency vs Reply Len</div>
                      <span className={styles.infoHint}>
                        <span className={styles.infoIcon}>i</span>
                        <span className={styles.infoCard}>
                          <strong>Latency vs Reply Len</strong>
                          <span>Asse X: latenza (ms), Asse Y: lunghezza risposta.</span>
                          <span>Utile per verificare risposte lunghe con latenza alta.</span>
                        </span>
                      </span>
                    </div>
                    {correlationB.length > 0 ? (
                      <div
                        className={styles.scatterFrameSm}
                        ref={scatterFrameSmBRef}
                        onMouseLeave={() => setTechScatterTooltip((current) => (current?.chart === 'b' ? null : current))}
                      >
                      <svg viewBox="0 0 220 130" className={styles.scatterPlot}>
                        <line x1="20" y1="112" x2="210" y2="112" className={styles.scatterAxisLine} />
                        <line x1="20" y1="8" x2="20" y2="112" className={styles.scatterAxisLine} />
                        {[0, 0.5, 1].map((tick) => {
                          const x = 20 + tick * 190;
                          const value = Math.round(maxLatencyCorrX * tick);
                          return (
                            <g key={`tb-x-${tick}`}>
                              <line x1={x} y1="112" x2={x} y2="116" className={styles.scatterTickLine} />
                              <text x={x} y="126" textAnchor="middle" className={styles.scatterTickLabel}>{value}</text>
                            </g>
                          );
                        })}
                        {[0, 0.5, 1].map((tick) => {
                          const y = 112 - tick * 104;
                          const value = Math.round(maxReplyLenCorrY * tick);
                          return (
                            <g key={`tb-y-${tick}`}>
                              <line x1="16" y1={y} x2="20" y2={y} className={styles.scatterTickLine} />
                              <text x="13" y={y + 3} textAnchor="end" className={styles.scatterTickLabel}>{value}</text>
                            </g>
                          );
                        })}
                        {correlationB.slice(0, 250).map((point, idx) => (
                          <g key={`b-${idx}`}>
                            <circle
                              cx={Math.max(20, Math.min(210, (point.x / maxLatencyCorrX) * 190 + 20))}
                              cy={Math.max(8, Math.min(112, 112 - (point.y / maxReplyLenCorrY) * 104))}
                              r={2.8}
                              className={styles.scatterDotB}
                            />
                            <circle
                              cx={Math.max(20, Math.min(210, (point.x / maxLatencyCorrX) * 190 + 20))}
                              cy={Math.max(8, Math.min(112, 112 - (point.y / maxReplyLenCorrY) * 104))}
                              r={8}
                              className={styles.scatterPointHover}
                              onMouseEnter={(event) => onTechScatterPointHover(event, scatterFrameSmBRef, 'b', `latency: ${n(point.x, 0)} ms | reply_len: ${n(point.y, 0)}`)}
                              onMouseMove={(event) => onTechScatterPointHover(event, scatterFrameSmBRef, 'b', `latency: ${n(point.x, 0)} ms | reply_len: ${n(point.y, 0)}`)}
                            />
                          </g>
                        ))}
                      </svg>
                      {techScatterTooltip?.chart === 'b' ? (
                        <div
                          className={styles.scatterHoverTooltip}
                          style={{ left: `${techScatterTooltip.left}px`, top: `${techScatterTooltip.top}px` }}
                        >
                          {techScatterTooltip.text}
                        </div>
                      ) : null}
                      <div className={styles.scatterAxisXSm}>X: Latency (ms)</div>
                      <div className={styles.scatterAxisYSm}>Y: Reply length</div>
                      </div>
                    ) : <div className={styles.emptyStateTech}>Campione insufficiente per scatter</div>}
                  </div>
                  <div className={styles.scatterCard}>
                    <div className={styles.titleWithInfo}>
                      <div className={styles.scatterTitle}>RAG Hits vs Confidence</div>
                      <span className={styles.infoHint}>
                        <span className={styles.infoIcon}>i</span>
                        <span className={styles.infoCard}>
                          <strong>RAG Hits vs Confidence</strong>
                          <span>Asse X: numero hit RAG, Asse Y: confidence (0-100).</span>
                          <span>Serve per capire se più contesto alza la confidence.</span>
                        </span>
                      </span>
                    </div>
                    {correlationC.length > 0 ? (
                      <div
                        className={styles.scatterFrameSm}
                        ref={scatterFrameSmCRef}
                        onMouseLeave={() => setTechScatterTooltip((current) => (current?.chart === 'c' ? null : current))}
                      >
                      <svg viewBox="0 0 220 130" className={styles.scatterPlot}>
                        <line x1="20" y1="112" x2="210" y2="112" className={styles.scatterAxisLine} />
                        <line x1="20" y1="8" x2="20" y2="112" className={styles.scatterAxisLine} />
                        {[0, 0.5, 1].map((tick) => {
                          const x = 20 + tick * 190;
                          const value = Math.round(maxRagHitsCorrX * tick);
                          return (
                            <g key={`tc-x-${tick}`}>
                              <line x1={x} y1="112" x2={x} y2="116" className={styles.scatterTickLine} />
                              <text x={x} y="126" textAnchor="middle" className={styles.scatterTickLabel}>{value}</text>
                            </g>
                          );
                        })}
                        {[0, 50, 100].map((tick) => {
                          const y = 112 - (tick / 100) * 104;
                          return (
                            <g key={`tc-y-${tick}`}>
                              <line x1="16" y1={y} x2="20" y2={y} className={styles.scatterTickLine} />
                              <text x="13" y={y + 3} textAnchor="end" className={styles.scatterTickLabel}>{tick}</text>
                            </g>
                          );
                        })}
                        {correlationC.slice(0, 250).map((point, idx) => (
                          <g key={`c-${idx}`}>
                            <circle
                              cx={Math.max(20, Math.min(210, (point.x / maxRagHitsCorrX) * 190 + 20))}
                              cy={Math.max(8, Math.min(112, 112 - ((point.y ?? 0) / 100) * 104))}
                              r={2.8}
                              className={styles.scatterDotC}
                            />
                            <circle
                              cx={Math.max(20, Math.min(210, (point.x / maxRagHitsCorrX) * 190 + 20))}
                              cy={Math.max(8, Math.min(112, 112 - ((point.y ?? 0) / 100) * 104))}
                              r={8}
                              className={styles.scatterPointHover}
                              onMouseEnter={(event) => onTechScatterPointHover(event, scatterFrameSmCRef, 'c', `rag_hits: ${n(point.x, 0)} | confidence: ${n(point.y, 1)}`)}
                              onMouseMove={(event) => onTechScatterPointHover(event, scatterFrameSmCRef, 'c', `rag_hits: ${n(point.x, 0)} | confidence: ${n(point.y, 1)}`)}
                            />
                          </g>
                        ))}
                      </svg>
                      {techScatterTooltip?.chart === 'c' ? (
                        <div
                          className={styles.scatterHoverTooltip}
                          style={{ left: `${techScatterTooltip.left}px`, top: `${techScatterTooltip.top}px` }}
                        >
                          {techScatterTooltip.text}
                        </div>
                      ) : null}
                      <div className={styles.scatterAxisXSm}>X: RAG hits</div>
                      <div className={styles.scatterAxisYSm}>Y: Confidence (0-100)</div>
                      </div>
                    ) : <div className={styles.emptyStateTech}>Campione insufficiente per scatter</div>}
                  </div>
                </div>
              </>
            ) : null}
          </div>
        </section>

        <section className={styles.gridBottom}>
          <div className={styles.panel}>
            <div className={styles.panelTitle}>Dettaglio Sessioni</div>
            <div className={styles.inlineFilters}>
              <label><input type="checkbox" checked={sessionFallbackOnly} onChange={(e) => setSessionFallbackOnly(e.target.checked)} /> fallback only</label>
              <label><input type="checkbox" checked={sessionContradictionOnly} onChange={(e) => setSessionContradictionOnly(e.target.checked)} /> contradiction only</label>
              <label><input type="checkbox" checked={sessionLowConfidenceOnly} onChange={(e) => setSessionLowConfidenceOnly(e.target.checked)} /> low confidence</label>
              <label><input type="checkbox" checked={sessionHighLatencyOnly} onChange={(e) => setSessionHighLatencyOnly(e.target.checked)} /> high latency</label>
              <select value={sessionTopic} onChange={(e) => setSessionTopic(e.target.value)}>
                <option value="">topic: tutti</option>
                {topTopics.map(([topic]) => <option key={topic} value={topic}>{topic}</option>)}
              </select>
            </div>

            <div className={styles.tableWrap}>
              <table className={styles.table}>
                <thead>
                  <tr>
                    <th>Sessione</th>
                    <th>Pipeline</th>
                    <th>Intent</th>
                    <th>Msg</th>
                    <th>Conf.</th>
                    <th>Lat max</th>
                    <th>Fallback</th>
                  </tr>
                </thead>
                <tbody>
                  {sessions.slice(0, 80).map((s) => (
                    <tr key={s.session_id} onClick={() => setSelectedSession(s.session_id)} className={selectedSession === s.session_id ? styles.activeRow : ''}>
                      <td>{shortSession(s.session_id)}</td>
                      <td>{s.pipeline ?? '-'}</td>
                      <td>{s.intent ?? '-'}</td>
                      <td>{s.messages_total}</td>
                      <td>{n(s.avg_confidence, 1)}</td>
                      <td>{n(s.max_latency_ms)}</td>
                      <td>{n(s.fallback_count)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className={styles.panel}>
            <div className={styles.panelTitle}>Session Inspector</div>
            <div className={styles.inspectMeta}>
              <div><span>ID</span><strong>{shortSession(selectedSessionMeta?.session_id ?? '')}</strong></div>
              <div><span>Model</span><strong>{selectedSessionMeta?.model ?? '-'}</strong></div>
              <div><span>Confidence</span><strong>{n(selectedSessionMeta?.avg_confidence, 1)}</strong></div>
              <div><span>Latency max</span><strong>{n(selectedSessionMeta?.max_latency_ms)} ms</strong></div>
            </div>
            <div className={styles.logBox}>
              {sessionDetail?.timeline?.length ? sessionDetail.timeline.slice(0, 80).map((item, i) => (
                <div key={`tl-${i}`} className={styles.logRow}>
                  <span>{item.at?.slice(11, 19) ?? '--:--:--'}</span>
                  <b>{item.type}</b>
                  <i>{item.role}</i>
                  <em>{item.summary}</em>
                </div>
              )) : <div className={styles.emptyStateTech}>Nessun evento disponibile per la sessione selezionata</div>}
            </div>
          </div>
        </section>

        <section className={styles.panel}>
          <div className={styles.panelTitle}>Dettaglio messaggi (preview)</div>
          <div className={styles.messageFeed}>
            {sessionDetail?.messages?.length ? sessionDetail.messages.slice(0, 24).map((m) => (
              <article key={m.id} className={styles.msgCard}>
                <header>
                  <strong>{m.role}</strong>
                  <span>{m.created_at?.replace('T', ' ').slice(0, 19) ?? '-'}</span>
                </header>
                <p>{m.content}</p>
                {viewMode === 'technical' ? (
                  <details>
                    <summary>JSON tecnico</summary>
                    <pre>{JSON.stringify(m.metadata ?? {}, null, 2)}</pre>
                  </details>
                ) : null}
              </article>
            )) : <div className={styles.emptyStateTech}>Seleziona una sessione per vedere i messaggi</div>}
          </div>
        </section>
      </div>
    </div>
  );
}
