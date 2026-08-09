import { useState, useEffect, useCallback } from '@wordpress/element';
import Tabs from '../components/Tabs';
import { getPerfSettings, updatePerfSettings } from '../api';

/**
 * Site-level performance optimizations.
 *
 * A permission screen, not a control panel. Fixes are applied from the SnowSEO
 * dashboard, where the site owner is already looking at the PageSpeed score that
 * prompted them - so the only control here is consent, and this tab's job is to
 * say honestly what SnowSEO may change and what it has changed.
 *
 * Server-authoritative like the AI Crawlers screen: every mutation replaces the
 * whole local state with the payload the server returns, so the UI can never
 * claim a state the site is not actually in. That matters more here than for a
 * tracking toggle, because one of these optimizations writes to .htaccess.
 *
 * Laid out with the Dashboard tab's own components - banner, status card,
 * activity card, config card - so the four tabs read as one product.
 */

const getAppUrl = () => {
    return 'https://snowseo.com/dashboard';
};

const DOCS_URL = 'https://snowseo.com/docs/user-guide/analysis/website-audit';

/*
 * The fix ids this plugin can report on, used only to count how many are live.
 *
 * What each one does is deliberately NOT described here: the dashboard already
 * explains every optimization next to the PageSpeed finding that motivates it,
 * and a second, shorter description on a screen that cannot act on it reads as a
 * read-only list of things the owner is not being allowed to touch.
 */
const CAPABILITY_IDS = [
    'robots-txt',
    'text-compression',
    'cache-headers',
    'font-display',
    'preconnect',
    'render-blocking',
];

function relativeTime(ms) {
    if (!ms) {
        return 'Never';
    }
    const seconds = Math.floor((Date.now() - ms) / 1000);
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    return `${Math.floor(seconds / 86400)}d ago`;
}

/*
 * The plugin has no shared alert component - Settings.js styles its one error
 * inline - so this keeps that convention rather than introducing a class the
 * stylesheet would have to grow for two callers.
 */
function Notice({ tone = 'error', children, style }) {
    const tones = {
        error: { color: '#b91c1c', background: '#fef2f2', border: '1px solid #fecaca' },
        warning: { color: '#b45309', background: '#fffbeb', border: '1px solid #fde68a' },
    };
    return (
        <div style={{ fontSize: '13px', lineHeight: 1.5, padding: '10px 14px', borderRadius: '8px', ...tones[tone], ...style }}>
            {children}
        </div>
    );
}

function Stat({ label, value, tone }) {
    return (
        <div className="snowseo-status-card__stat">
            <span className="snowseo-status-card__stat-label">{label}</span>
            <span className={`snowseo-status-card__stat-value${tone === 'active' ? ' snowseo-status-card__stat-value--active' : ''}`}>
                {tone === 'active' && <span className="snowseo-status-card__active-dot"></span>}
                {value}
            </span>
        </div>
    );
}

/** Header icon for the status card: mirrors the Dashboard's green check. */
function StatusIcon({ tone }) {
    if (tone === 'ok') {
        return (
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" fill="#22c55e" />
                <path d="M8 12l3 3 5-5" stroke="#fff" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
        );
    }
    if (tone === 'warn') {
        return (
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" fill="#f59e0b" />
                <path d="M12 7v6M12 16.5h.01" stroke="#fff" strokeWidth="2.5" strokeLinecap="round" />
            </svg>
        );
    }
    return (
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" fill="#cbd5e1" />
            <path d="M8 12h8" stroke="#fff" strokeWidth="2.5" strokeLinecap="round" />
        </svg>
    );
}

function Shell({ children }) {
    return (
        <div className="snowseo-dashboard">
            <div className="snowseo-breadcrumb">
                <a href="#/dashboard" className="snowseo-breadcrumb__link">SnowSEO</a>
                <span className="snowseo-breadcrumb__sep">/</span>
                <span className="snowseo-breadcrumb__current">Page Speed</span>
            </div>

            <h1 className="snowseo-dashboard__title">Page Speed</h1>

            <Tabs current="performance" />

            {children}
        </div>
    );
}

export default function Performance() {
    const [settings, setSettings] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setSettings(await getPerfSettings());
            setError('');
        } catch (err) {
            setError(err.message || 'Could not load performance settings.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const save = async (changes) => {
        setSaving(true);
        setError('');
        try {
            setSettings(await updatePerfSettings(changes));
        } catch (err) {
            setError(err.message || 'Could not save that change.');
            // The refusal payload carries the current state; re-read it so the
            // screen reflects why rather than staying on a stale value.
            await load();
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <Shell>
                <div className="snowseo-config-card">
                    <div style={{ padding: '40px', textAlign: 'center', color: '#64748b' }}>
                        Loading…
                    </div>
                </div>
            </Shell>
        );
    }

    const fixes = settings?.fixes || {};
    const consent = Boolean(settings?.remoteEnabled);
    const server = settings?.server || {};
    const caches = settings?.caches || [];
    const actions = settings?.lastActions || [];

    const present = CAPABILITY_IDS.filter((id) => fixes[id]);
    const activeCount = present.filter((id) => 'applied' === fixes[id].status).length;
    const lastChangedAt = actions.reduce((newest, entry) => {
        const at = entry.date ? new Date(entry.date).getTime() : 0;
        return at > newest ? at : newest;
    }, 0);

    let tone = 'ok';
    let title = `${activeCount} of ${present.length} optimizations active`;
    let subtitle = 'SnowSEO applies these from your dashboard when a PageSpeed report calls for them.';
    if (!consent) {
        tone = 'warn';
        title = 'SnowSEO cannot change anything yet';
        subtitle = 'Turn on the permission below to let your dashboard apply these optimizations.';
    } else if (0 === activeCount) {
        tone = 'idle';
        title = 'Ready, nothing applied yet';
        subtitle = 'Run a PageSpeed report in SnowSEO and apply the fixes it suggests.';
    }

    return (
        <Shell>
            {error && <Notice style={{ marginBottom: '20px' }}>{error}</Notice>}

            {/* The fixes themselves are applied from the dashboard, not here. */}
            <div className="snowseo-banner">
                <div className="snowseo-banner__content">
                    <div className="snowseo-banner__icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M13 2L4.09 12.97a1 1 0 00.77 1.64h6.03l-1.9 7.39 8.91-10.97a1 1 0 00-.77-1.64h-6.03L13 2z" />
                        </svg>
                    </div>
                    <div>
                        <h3 className="snowseo-banner__title">Fixes are applied from SnowSEO</h3>
                        <p className="snowseo-banner__text">
                            This tab decides what SnowSEO is allowed to change. The optimizations
                            themselves are applied, and undone, from the PageSpeed screen in your
                            dashboard.
                        </p>
                    </div>
                </div>
                <a
                    href={`${getAppUrl()}/page-speed`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="snowseo-banner__btn"
                >
                    Open PageSpeed
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            {/* Status + explainer, same two-column grid as the Dashboard tab */}
            <div className="snowseo-dashboard__grid">
                <div className="snowseo-status-card">
                    <div className="snowseo-status-card__header">
                        <div className="snowseo-status-card__check">
                            <StatusIcon tone={tone} />
                        </div>
                        <div>
                            <h3 className="snowseo-status-card__title">{title}</h3>
                            <p className="snowseo-status-card__subtitle">{subtitle}</p>
                        </div>
                    </div>

                    <div className="snowseo-status-card__stats">
                        <Stat
                            label="ACTIVE"
                            value={`${activeCount} of ${present.length}`}
                            tone={activeCount > 0 ? 'active' : undefined}
                        />
                        <Stat label="DASHBOARD ACCESS" value={consent ? 'Allowed' : 'Not allowed'} />
                        <Stat label="WEB SERVER" value={server.label || 'Unknown'} />
                        <Stat label="LAST CHANGE" value={relativeTime(lastChangedAt)} />
                    </div>

                    <div className="snowseo-status-card__footer">
                        <span className="snowseo-status-card__version">
                            {server.readsHtaccess
                                ? `Configuration file ${server.htaccessWritable ? 'is writable' : 'cannot be written'}`
                                : `${server.label || 'This server'} does not read .htaccess`}
                            {caches.length > 0 && ` - page cache: ${caches.join(', ')}`}
                        </span>
                    </div>
                </div>

                {/*
                 * Answers the question the permission toggle provokes: what
                 * exactly am I agreeing to, and can I take it back.
                 */}
                <div className="snowseo-activity-card">
                    <div className="snowseo-activity-card__header">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                        </svg>
                        <h3 className="snowseo-activity-card__title">What SnowSEO can change</h3>
                    </div>

                    <div className="snowseo-activity-list">
                        <div className="snowseo-activity-item">
                            <span className="snowseo-activity-item__dot"></span>
                            <div className="snowseo-activity-item__content">
                                <span className="snowseo-activity-item__title">A fixed list, built into this plugin</span>
                                <span className="snowseo-activity-item__desc">
                                    Only the optimizations below. SnowSEO can never send its own
                                    file contents or server directives.
                                </span>
                            </div>
                        </div>
                        <div className="snowseo-activity-item">
                            <span className="snowseo-activity-item__dot"></span>
                            <div className="snowseo-activity-item__content">
                                <span className="snowseo-activity-item__title">Nothing changes without a report</span>
                                <span className="snowseo-activity-item__desc">
                                    Each one is applied only when you choose it in your dashboard.
                                </span>
                            </div>
                        </div>
                        <div className="snowseo-activity-item">
                            <span className="snowseo-activity-item__dot"></span>
                            <div className="snowseo-activity-item__content">
                                <span className="snowseo-activity-item__title">Reversible at any time</span>
                                <span className="snowseo-activity-item__desc">
                                    Undo from your dashboard, or from Settings &gt; Reading in this
                                    site's admin if you cannot reach it.
                                </span>
                            </div>
                        </div>
                    </div>

                    <a
                        href={DOCS_URL}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="snowseo-activity-card__view-all"
                    >
                        How this works
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M5 12h14M12 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>
            </div>

            {settings?.fileModsAllowed === false && (
                <Notice tone="warning" style={{ marginBottom: '24px' }}>
                    This site has <code>DISALLOW_FILE_MODS</code> set, so WordPress cannot change
                    any files. Optimizations that write to disk stay unavailable until that is
                    removed.
                </Notice>
            )}

            <div className="snowseo-config-card">
                <div className="snowseo-config-card__header">
                    <h3 className="snowseo-config-card__title">Permission</h3>
                    <p className="snowseo-config-card__subtitle">
                        Off by default. This is the only switch on this screen.
                    </p>
                </div>

                <div className="snowseo-config-table">
                    <div className="snowseo-config-table__row">
                        <span className="snowseo-config-table__col snowseo-config-table__col--setting">
                            Apply fixes from my dashboard
                        </span>
                        <span className="snowseo-config-table__col snowseo-config-table__col--value">
                            <label>
                                <input
                                    type="checkbox"
                                    checked={consent}
                                    disabled={saving}
                                    onChange={(e) => save({ remoteEnabled: e.target.checked })}
                                />
                                {' '}
                                {consent ? 'Allowed' : 'Not allowed'}
                            </label>
                            {!consent && ' - your dashboard can report problems but not fix them'}
                        </span>
                    </div>
                </div>

                <div className="snowseo-config-card__footer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" />
                        <path d="M7 11V7a5 5 0 0110 0v4" />
                    </svg>
                    The same switch lives under Settings &gt; Reading, so it stays reachable if this
                    screen ever will not load.
                </div>
            </div>

            <div className="snowseo-config-card" style={{ marginTop: '20px' }}>
                <div className="snowseo-config-card__header">
                    <h3 className="snowseo-config-card__title">This site</h3>
                    <p className="snowseo-config-card__subtitle">
                        What SnowSEO found when it looked at your hosting.
                    </p>
                </div>

                <div className="snowseo-config-table">
                    <div className="snowseo-config-table__row">
                        <span className="snowseo-config-table__col snowseo-config-table__col--setting">Web server</span>
                        <span className="snowseo-config-table__col snowseo-config-table__col--value">
                            {server.label || 'Unknown'}
                        </span>
                    </div>
                    <div className="snowseo-config-table__row">
                        <span className="snowseo-config-table__col snowseo-config-table__col--setting">Configuration file</span>
                        <span className="snowseo-config-table__col snowseo-config-table__col--value">
                            {server.readsHtaccess && server.htaccessPath ? (
                                <>
                                    <code>{server.htaccessPath}</code>
                                    {server.htaccessWritable ? ' - writable' : ' - not writable'}
                                </>
                            ) : (
                                'This server does not read .htaccess'
                            )}
                        </span>
                    </div>
                    <div className="snowseo-config-table__row">
                        <span className="snowseo-config-table__col snowseo-config-table__col--setting">Page cache</span>
                        <span className="snowseo-config-table__col snowseo-config-table__col--value">
                            {caches.length > 0 ? caches.join(', ') : 'None detected'}
                        </span>
                    </div>
                </div>
            </div>

            {actions.length > 0 && (
                <div className="snowseo-activity-card" style={{ marginTop: '20px' }}>
                    <div className="snowseo-activity-card__header">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <path d="M12 6v6l4 2" />
                        </svg>
                        <h3 className="snowseo-activity-card__title">Recent changes</h3>
                    </div>

                    <div className="snowseo-activity-list">
                        {actions.map((entry) => (
                            <div className="snowseo-activity-item" key={entry.id}>
                                <span className="snowseo-activity-item__dot"></span>
                                <div className="snowseo-activity-item__content">
                                    <span className="snowseo-activity-item__title">{entry.message}</span>
                                    <span className="snowseo-activity-item__desc">
                                        {entry.date ? new Date(entry.date).toLocaleString() : ''}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Shell>
    );
}
