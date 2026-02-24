'use client';

import { useEffect, useMemo, useState } from 'react';
import styles from './report.module.css';

const BACKEND_URL =
  process.env.NEXT_PUBLIC_BACKEND_URL ?? 'http://localhost:8000';

type KpiResponse = {
  tenant: string;
  from?: string | null;
  to?: string | null;
  sessions: number;
  messages_total: number;
  messages_user: number;
  messages_assistant: number;
  fallback_messages: number;
  fallback_rate_percent: number;
  top_topics?: Record<string, number>;
  daily?: Array<{
    date: string;
    messages_total: number;
    messages_user: number;
    messages_assistant: number;
    fallback_messages: number;
    sessions: number;
  }>;
  topic_daily?: Record<string, Record<string, number>>;
};

type TenantOption = {
  id: string;
  name: string;
};

type SessionSummary = {
  session_id: string;
  messages_total: number;
  messages_user: number;
  messages_assistant: number;
  last_at: string;
};

type SessionDetail = {
  id: number;
  role: 'user' | 'assistant';
  content: string;
  source: string;
  topics: string[];
  created_at: string | null;
};

export default function ReportPage() {
  const [tenant, setTenant] = useState('default');
  const [tenantOptions, setTenantOptions] = useState<TenantOption[]>([]);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [rangePreset, setRangePreset] = useState('custom');
  const [theme, setTheme] = useState<'light' | 'dark'>('light');
  const [sessionTopic, setSessionTopic] = useState('');
  const [sessionFallbackOnly, setSessionFallbackOnly] = useState(false);
  const [kpi, setKpi] = useState<KpiResponse | null>(null);
  const [sessions, setSessions] = useState<SessionSummary[]>([]);
  const [selectedSession, setSelectedSession] = useState('');
  const [sessionDetail, setSessionDetail] = useState<SessionDetail[]>([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (tenant) params.set('tenant', tenant);
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    return params.toString();
  }, [tenant, from, to]);

  const sessionsQueryString = useMemo(() => {
    const params = new URLSearchParams();
    if (tenant) params.set('tenant', tenant);
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (sessionTopic) params.set('topic', sessionTopic);
    if (sessionFallbackOnly) params.set('fallback', '1');
    return params.toString();
  }, [tenant, from, to, sessionTopic, sessionFallbackOnly]);

  useEffect(() => {
    const stored = typeof window !== 'undefined'
      ? window.localStorage.getItem('report-theme')
      : null;
    if (stored === 'dark' || stored === 'light') {
      setTheme(stored);
    }
  }, []);

  const toggleTheme = () => {
    setTheme((current) => {
      const next = current === 'dark' ? 'light' : 'dark';
      if (typeof window !== 'undefined') {
        window.localStorage.setItem('report-theme', next);
      }
      return next;
    });
  };

  useEffect(() => {
    const controller = new AbortController();
    fetch(`${BACKEND_URL}/api/report/tenants`, {
      signal: controller.signal,
    })
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

  const applyRange = (preset: string) => {
    setRangePreset(preset);
    if (preset === 'custom') {
      return;
    }
    const today = new Date();
    const end = today.toISOString().slice(0, 10);
    let startDate = new Date();
    if (preset === 'today') {
      startDate = today;
    } else if (preset === '7d') {
      startDate.setDate(today.getDate() - 6);
    } else if (preset === '30d') {
      startDate.setDate(today.getDate() - 29);
    } else if (preset === 'month') {
      startDate = new Date(today.getFullYear(), today.getMonth(), 1);
    }
    const start = startDate.toISOString().slice(0, 10);
    setFrom(start);
    setTo(end);
  };

  const fetchSessions = async () => {
    const sessionsResponse = await fetch(
      `${BACKEND_URL}/api/report/sessions?${sessionsQueryString}`,
      {},
    );
    if (sessionsResponse.ok) {
      const sessionsPayload = await sessionsResponse.json();
      const list = Array.isArray(sessionsPayload.sessions)
        ? sessionsPayload.sessions
        : [];
      setSessions(list);
      if (list.length > 0) {
        setSelectedSession(list[0].session_id);
      } else {
        setSelectedSession('');
        setSessionDetail([]);
      }
    }
  };

  const handleFetch = async () => {
    setError('');
    setLoading(true);
    try {
      const response = await fetch(
        `${BACKEND_URL}/api/report/kpi?${queryString}`,
        {},
      );

      if (!response.ok) {
        throw new Error(`Errore ${response.status}`);
      }

      const payload = (await response.json()) as KpiResponse;
      setKpi(payload);

      await fetchSessions();
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Errore nel recupero KPI',
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (!selectedSession) {
      return;
    }
    const controller = new AbortController();
    fetch(
      `${BACKEND_URL}/api/report/session/${encodeURIComponent(
        selectedSession,
      )}?tenant=${encodeURIComponent(tenant)}`,
      { signal: controller.signal },
    )
      .then((response) => (response.ok ? response.json() : null))
      .then((payload) => {
        const messages = Array.isArray(payload?.messages)
          ? payload.messages
          : [];
        setSessionDetail(messages);
      })
      .catch(() => {});

    return () => controller.abort();
  }, [selectedSession, tenant]);

  useEffect(() => {
    if (!kpi) {
      return;
    }
    fetchSessions().catch(() => {});
  }, [sessionsQueryString, kpi]);

  const handleExport = async () => {
    setError('');
    try {
      const response = await fetch(
        `${BACKEND_URL}/api/report/export?${queryString}`,
        {},
      );

      if (!response.ok) {
        throw new Error(`Errore export ${response.status}`);
      }

      const blob = await response.blob();
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `report_${tenant || 'default'}.csv`;
      link.click();
      window.URL.revokeObjectURL(url);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Errore export',
      );
    }
  };

  const dailySeries = useMemo(() => {
    const rows = kpi?.daily ?? [];
    if (!rows.length) return [];
    const max = Math.max(...rows.map((row) => row.messages_total));
    return rows.map((row) => ({
      ...row,
      height: max > 0 ? Math.round((row.messages_total / max) * 100) : 0,
      value: row.messages_total,
    }));
  }, [kpi]);

  const sessionSeries = useMemo(() => {
    const rows = kpi?.daily ?? [];
    if (!rows.length) return [];
    const max = Math.max(...rows.map((row) => row.sessions));
    return rows.map((row) => ({
      date: row.date,
      value: row.sessions,
      height: max > 0 ? Math.round((row.sessions / max) * 100) : 0,
    }));
  }, [kpi]);

  const topTopics = useMemo(() => {
    if (!kpi?.top_topics) return [];
    return Object.entries(kpi.top_topics);
  }, [kpi]);

  const topicColors = [
    '#0f766e',
    '#2563eb',
    '#9333ea',
    '#ea580c',
    '#16a34a',
  ];

  const topicLegend = useMemo(() => {
    return topTopics.map(([topic], index) => ({
      topic,
      color: topicColors[index % topicColors.length],
    }));
  }, [topTopics]);

  const topicDailySeries = useMemo(() => {
    if (!kpi?.topic_daily || !kpi?.daily) return [];
    return kpi.daily.map((row) => {
      const topicCounts = kpi.topic_daily?.[row.date] ?? {};
      const total = Object.values(topicCounts).reduce(
        (sum, value) => sum + value,
        0,
      );
      const segments = topicLegend.map(({ topic, color }) => ({
        topic,
        color,
        value: topicCounts[topic] ?? 0,
      }));
      return {
        date: row.date,
        total,
        segments,
      };
    });
  }, [kpi, topicLegend]);

  return (
    <div className={styles.page} data-theme={theme}>
      <div className={styles.shell}>
        <header className={styles.header}>
          <div>
            <div className={styles.title}>Usage & KPI</div>
            <div className={styles.subtitle}>
              Monitoraggio realtime per tenant e topic.
            </div>
          </div>
          <div className={styles.headerActions}>
            <button
              className={styles.buttonSecondary}
              type="button"
              onClick={toggleTheme}
            >
              {theme === 'dark' ? 'Light mode' : 'Dark mode'}
            </button>
            <button
              className={styles.buttonSecondary}
              type="button"
              onClick={handleExport}
            >
              Export CSV
            </button>
          </div>
        </header>

        <div className={styles.panel}>
          <div className={styles.filters}>
            <div>
              <div className={styles.label}>Tenant</div>
              <select
                className={styles.select}
                value={tenant}
                onChange={(event) => setTenant(event.target.value)}
              >
                {tenantOptions.length === 0 ? (
                  <option value={tenant}>{tenant}</option>
                ) : (
                  tenantOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.name}
                    </option>
                  ))
                )}
              </select>
            </div>
            <div>
              <div className={styles.label}>Range</div>
              <select
                className={styles.select}
                value={rangePreset}
                onChange={(event) => applyRange(event.target.value)}
              >
                <option value="custom">Personalizzato</option>
                <option value="today">Oggi</option>
                <option value="7d">Ultimi 7 giorni</option>
                <option value="30d">Ultimi 30 giorni</option>
                <option value="month">Questo mese</option>
              </select>
            </div>
            <div>
              <div className={styles.label}>Da</div>
              <input
                className={styles.input}
                value={from}
                onChange={(event) => setFrom(event.target.value)}
                type="date"
              />
            </div>
            <div>
              <div className={styles.label}>A</div>
              <input
                className={styles.input}
                value={to}
                onChange={(event) => setTo(event.target.value)}
                type="date"
              />
            </div>
            <button
              className={styles.button}
              type="button"
              onClick={handleFetch}
              disabled={loading}
            >
              {loading ? 'Caricamento...' : 'Carica KPI'}
            </button>
          </div>

          {error ? <div className={styles.error}>{error}</div> : null}

          {kpi ? (
            <div className={styles.layout}>
              <div className={styles.kpiRow}>
                <div className={styles.kpiItem}>
                  <div className={styles.kpiLabel}>Sessioni</div>
                  <div className={styles.kpiValue}>{kpi.sessions}</div>
                </div>
                <div className={styles.kpiItem}>
                  <div className={styles.kpiLabel}>Messaggi user</div>
                  <div className={styles.kpiValue}>{kpi.messages_user}</div>
                </div>
                <div className={styles.kpiItem}>
                  <div className={styles.kpiLabel}>Messaggi assistant</div>
                  <div className={styles.kpiValue}>{kpi.messages_assistant}</div>
                </div>
                <div className={styles.kpiItem}>
                  <div className={styles.kpiLabel}>Fallback</div>
                  <div className={styles.kpiValue}>{kpi.fallback_messages}</div>
                </div>
                <div className={styles.kpiItem}>
                  <div className={styles.kpiLabel}>Fallback rate %</div>
                  <div className={styles.kpiValue}>{kpi.fallback_rate_percent}</div>
                </div>
              </div>

              <div className={styles.chartsRow}>
                <div className={styles.chartCard}>
                  <div className={styles.chartHeader}>
                    <div>
                      <div className={styles.chartTitle}>Messaggi per giorno</div>
                      <div className={styles.chartSubtitle}>
                        Totale messaggi, raggruppati per data.
                      </div>
                    </div>
                    <div className={styles.badge}>
                      {kpi.messages_total} messaggi
                    </div>
                  </div>
                  <div className={styles.chart}>
                    {dailySeries.length > 0 ? (
                      dailySeries.map((row) => (
                        <div key={row.date} className={styles.barWrap}>
                          <span className={styles.barValue}>{row.value}</span>
                          <div
                            className={styles.bar}
                            style={{
                              height:
                                row.value > 0
                                  ? `max(${row.height}%, 6px)`
                                  : '0%',
                            }}
                            title={`${row.date}: ${row.value} messaggi`}
                          />
                          <span className={styles.barLabel}>{row.date}</span>
                        </div>
                      ))
                    ) : (
                      <div className={styles.emptyChart}>Nessun dato</div>
                    )}
                  </div>
                  <div className={styles.stackedCard}>
                    <div className={styles.topicTitle}>Topic per giorno</div>
                    <div className={styles.legend}>
                      {topicLegend.map((item) => (
                        <div key={item.topic} className={styles.legendItem}>
                          <span
                            className={styles.legendDot}
                            style={{ backgroundColor: item.color }}
                          />
                          <span>{item.topic}</span>
                        </div>
                      ))}
                    </div>
                    <div className={styles.stackedChart}>
                      {topicDailySeries.length > 0 ? (
                        topicDailySeries.map((row) => (
                          <div key={row.date} className={styles.stackedRow}>
                            <span className={styles.stackedLabel}>{row.date}</span>
                            <div className={styles.stackedBar}>
                              {row.segments.map((segment) => (
                                <div
                                  key={`${row.date}-${segment.topic}`}
                                  className={styles.stackedSegment}
                                  style={{
                                    width: row.total > 0
                                      ? `${Math.round((segment.value / row.total) * 100)}%`
                                      : '0%',
                                    backgroundColor: segment.color,
                                  }}
                                  title={`${segment.topic}: ${segment.value}`}
                                />
                              ))}
                            </div>
                            <span className={styles.stackedTotal}>{row.total}</span>
                          </div>
                        ))
                      ) : (
                        <div className={styles.emptyChart}>Nessun dato</div>
                      )}
                    </div>
                  </div>
                </div>

                <div className={styles.sideColumn}>
                  <div className={`${styles.miniChartCard} ${styles.miniChartCardLarge}`}>
                    <div className={styles.topicTitle}>Sessioni per giorno</div>
                    <div className={`${styles.miniChart} ${styles.miniChartLarge}`}>
                      {sessionSeries.length > 0 ? (
                        sessionSeries.map((row) => (
                          <div key={row.date} className={styles.miniBarWrap}>
                            <span className={styles.miniValue}>{row.value}</span>
                            <div
                              className={styles.miniBar}
                              style={{
                                height:
                                  row.value > 0
                                    ? `max(${row.height}%, 6px)`
                                    : '0%',
                              }}
                              title={`${row.date}: ${row.value} sessioni`}
                            />
                            <span className={styles.miniLabel}>{row.date}</span>
                          </div>
                        ))
                      ) : (
                        <div className={styles.emptyChart}>Nessun dato</div>
                      )}
                    </div>
                  </div>

                  <div className={styles.topicCard}>
                    <div className={styles.topicTitle}>Top 5 topic</div>
                    {topTopics.length > 0 ? (
                      <ul className={styles.topicList}>
                        {topTopics.map(([topic, count]) => (
                          <li key={topic} className={styles.topicItem}>
                            <span>{topic}</span>
                            <span className={styles.topicCount}>{count}</span>
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <div className={styles.emptyChart}>Nessun topic</div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          ) : null}

          {sessions.length > 0 ? (
            <div className={styles.sessionCard}>
              <div className={styles.sessionHeader}>
                <div>
                  <div className={styles.topicTitle}>Dettaglio sessione</div>
                  <div className={styles.sessionSubtitle}>
                    Seleziona una sessione per vedere la chat completa.
                  </div>
                </div>
                <select
                  className={styles.select}
                  value={selectedSession}
                  onChange={(event) => setSelectedSession(event.target.value)}
                >
                  {sessions.map((item) => (
                    <option key={item.session_id} value={item.session_id}>
                      {item.last_at
                        ? `Sessione ${item.session_id} - ${item.last_at
                            .slice(0, 16)
                            .replace('T', ' ')} - ${item.messages_total} msg`
                        : `Sessione ${item.session_id} - ${item.messages_total} msg`}
                    </option>
                  ))}
                </select>
              </div>
              <div className={styles.sessionFilters}>
                <div className={styles.filterBlock}>
                  <div className={styles.label}>Filtro topic</div>
                  <select
                    className={styles.select}
                    value={sessionTopic}
                    onChange={(event) => setSessionTopic(event.target.value)}
                  >
                    <option value="">Tutti i topic</option>
                    {topTopics.map(([topic]) => (
                      <option key={topic} value={topic}>
                        {topic}
                      </option>
                    ))}
                  </select>
                </div>
                <label className={styles.checkboxLabel}>
                  <input
                    type="checkbox"
                    checked={sessionFallbackOnly}
                    onChange={(event) => setSessionFallbackOnly(event.target.checked)}
                  />
                  Solo sessioni con fallback
                </label>
              </div>

              <div className={styles.sessionStats}>
                {sessions
                  .filter((item) => item.session_id === selectedSession)
                  .map((item) => (
                    <div key={item.session_id} className={styles.sessionStat}>
                      <span>Messaggi</span>
                      <strong>{item.messages_total}</strong>
                    </div>
                  ))}
              </div>

              <div className={styles.sessionMessages}>
                {sessionDetail.length > 0 ? (
                  sessionDetail.map((message) => (
                    <div
                      key={message.id}
                      className={`${styles.sessionBubble} ${styles[message.role]}`}
                    >
                      <div className={styles.sessionMeta}>
                        <span>{message.role}</span>
                        <span>{message.created_at?.slice(0, 16) ?? ''}</span>
                      </div>
                      <p>{message.content}</p>
                    </div>
                  ))
                ) : (
                  <div className={styles.emptyChart}>Nessun messaggio</div>
                )}
              </div>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}
