import { useState, useEffect, useRef } from '@wordpress/element';
import { getStatus, getLogs } from '../api';
import Tabs from '../components/Tabs';

const getAppUrl = () => {
    return 'https://snowseo.com/dashboard';
};

export default function Dashboard() {
    const [status, setStatus] = useState(null);
    const [recentLogs, setRecentLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [manageOpen, setManageOpen] = useState(false);
    const dropdownRef = useRef(null);

    useEffect(() => {
        async function fetchData() {
            try {
                const [statusRes, logsRes] = await Promise.all([
                    getStatus(),
                    getLogs(1, 3),
                ]);
                setStatus(statusRes);
                setRecentLogs((logsRes.logs || []).slice(0, 3));
            } catch (err) {
                console.error('Failed to fetch dashboard data:', err);
            } finally {
                setLoading(false);
            }
        }
        fetchData();
    }, []);

    // Close dropdown when clicking outside
    useEffect(() => {
        function handleClickOutside(event) {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setManageOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const connectedDate = status?.data?.connectedAt
        ? new Date(status.data.connectedAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        : '—';
    const pluginVersion = status?.data?.pluginVersion || window.snowseoData?.version || '1.0.0';
    const brandLabel = status?.data?.teamName || '—';
    const siteLabel = status?.data?.teamWebsite || status?.data?.siteUrl || '—';
    const connectionTitle = loading && !status ? 'Checking connection...' : (status?.data ? 'Connected to SnowSEO' : 'Connection status unavailable');
    const connectionSubtitle = loading && !status ? 'Please wait.' : (status?.data ? 'System is online and actively syncing content.' : 'Refresh the page or check your connection.');
    const syncStatusLabel = status?.data ? 'Active' : (loading ? 'Checking...' : '—');

    const handleManageAction = (action) => {
        setManageOpen(false);
        switch (action) {
            case 'settings':
                window.open(`${getAppUrl()}?openSettings=true&tab=integrations&subTab=cms-publishing&expand=wp`, '_blank', 'noopener,noreferrer');
                break;
            case 'disconnect':
                window.location.hash = '#/disconnect';
                break;
            default:
                break;
        }
    };

    return (
        <div className="snowseo-dashboard">
            {/* Breadcrumb */}
            <div className="snowseo-breadcrumb">
                <a href="#/dashboard" className="snowseo-breadcrumb__link">SnowSEO</a>
                <span className="snowseo-breadcrumb__sep">/</span>
                <span className="snowseo-breadcrumb__current">Dashboard</span>
            </div>

            {/* Page Title */}
            <h1 className="snowseo-dashboard__title">Dashboard</h1>

            {/* Tab Navigation */}
            <Tabs current="dashboard" />

            {/* Blue Banner */}
            <div className="snowseo-banner">
                <div className="snowseo-banner__content">
                    <div className="snowseo-banner__icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
                            <path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                    </div>
                    <div>
                        <h3 className="snowseo-banner__title">Publishing is managed by SnowSEO</h3>
                        <p className="snowseo-banner__text">
                            Your publishing strategy and automation rules are streamed directly from your SnowSEO cloud
                            account to ensure consistency.
                        </p>
                    </div>
                </div>
                <a
                    href={`${getAppUrl()}?openSettings=true&tab=automation-settings&subTab=article`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="snowseo-banner__btn"
                >
                    Manage in SnowSEO
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            {/* Main Grid: Connection Status + Recent Activity */}
            <div className="snowseo-dashboard__grid">
                {/* Connection Status Card */}
                <div className="snowseo-status-card">
                    {/* Connected Header */}
                    <div className="snowseo-status-card__header">
                        <div className="snowseo-status-card__check">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" fill="#22c55e" />
                                <path d="M8 12l3 3 5-5" stroke="#fff" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" />
                            </svg>
                        </div>
                        <div>
                            <h3 className="snowseo-status-card__title">{connectionTitle}</h3>
                            <p className="snowseo-status-card__subtitle">{connectionSubtitle}</p>
                        </div>
                    </div>

                    {/* Stats Row */}
                    <div className="snowseo-status-card__stats">
                        <div className="snowseo-status-card__stat">
                            <span className="snowseo-status-card__stat-label">BRAND</span>
                            <span className="snowseo-status-card__stat-value">{brandLabel}</span>
                        </div>
                        <div className="snowseo-status-card__stat">
                            <span className="snowseo-status-card__stat-label">SITE</span>
                            <span className="snowseo-status-card__stat-value snowseo-status-card__stat-value--url" title={siteLabel}>{siteLabel}</span>
                        </div>
                        <div className="snowseo-status-card__stat">
                            <span className="snowseo-status-card__stat-label">CONNECTED SINCE</span>
                            <span className="snowseo-status-card__stat-value">{connectedDate}</span>
                        </div>
                        <div className="snowseo-status-card__stat">
                            <span className="snowseo-status-card__stat-label">SYNC STATUS</span>
                            <span className={`snowseo-status-card__stat-value${syncStatusLabel === 'Active' ? ' snowseo-status-card__stat-value--active' : ''}`}>
                                {syncStatusLabel === 'Active' && <span className="snowseo-status-card__active-dot"></span>}
                                {syncStatusLabel}
                            </span>
                        </div>
                    </div>

                    {/* Footer Row */}
                    <div className="snowseo-status-card__footer">
                        <span className="snowseo-status-card__version">Version {pluginVersion} • Validated</span>
                        <div className="snowseo-status-card__manage-wrap" ref={dropdownRef}>
                            <button
                                className="snowseo-status-card__manage"
                                type="button"
                                onClick={() => setManageOpen(!manageOpen)}
                            >
                                Manage Connection
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <polyline points="6 9 12 15 18 9" />
                                </svg>
                            </button>
                            {manageOpen && (
                                <div className="snowseo-status-card__dropdown">
                                    <button
                                        type="button"
                                        onClick={() => handleManageAction('settings')}
                                        style={{
                                            display: 'flex', alignItems: 'center', gap: '8px',
                                            width: '100%', padding: '10px 14px', border: 'none', background: 'none',
                                            cursor: 'pointer', fontSize: '13px', color: '#1d2327', textAlign: 'left',
                                        }}
                                        onMouseEnter={(e) => { e.currentTarget.style.background = '#f8fafc'; }}
                                        onMouseLeave={(e) => { e.currentTarget.style.background = 'none'; }}
                                    >
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="3" />
                                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
                                </svg>
                                View Settings
                            </button>
                            <div style={{ borderTop: '1px solid #e2e8f0' }} />
                            <button
                                        type="button"
                                        onClick={() => handleManageAction('disconnect')}
                                        style={{
                                            display: 'flex', alignItems: 'center', gap: '8px',
                                            width: '100%', padding: '10px 14px', border: 'none', background: 'none',
                                            cursor: 'pointer', fontSize: '13px', color: '#dc2626', textAlign: 'left',
                                        }}
                                        onMouseEnter={(e) => { e.currentTarget.style.background = '#fef2f2'; }}
                                        onMouseLeave={(e) => { e.currentTarget.style.background = 'none'; }}
                                    >
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                            <path d="M18.36 6.64a9 9 0 11-12.73 0" />
                                            <line x1="12" y1="2" x2="12" y2="12" />
                                        </svg>
                                        Disconnect
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Recent Activity Card - ensure card doesn't clip dropdown */}
                <div className="snowseo-activity-card">
                    <div className="snowseo-activity-card__header">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <polyline points="12 6 12 12 16 14" />
                        </svg>
                        <h3 className="snowseo-activity-card__title">Recent Activity</h3>
                    </div>

                    {/* Activity Items */}
                    <div className="snowseo-activity-list">
                        {recentLogs.length > 0 ? (
                            recentLogs.slice(0, 3).map((log) => (
                                <div className="snowseo-activity-item" key={log.id}>
                                    <span className="snowseo-activity-item__dot"></span>
                                    <div className="snowseo-activity-item__content">
                                        <span className="snowseo-activity-item__title">{log.message}</span>
                                        <span className="snowseo-activity-item__desc">{log.date}</span>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="snowseo-activity-item">
                                <span className="snowseo-activity-item__dot"></span>
                                <div className="snowseo-activity-item__content">
                                    <span className="snowseo-activity-item__title">No recent activity</span>
                                    <span className="snowseo-activity-item__desc">Activity will appear here once content is published.</span>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Pro Tip Banner — disabled: "Auto-Internal Linking" claim is not accurate, revisit copy before re-enabling.
            <div className="snowseo-protip">
                <div className="snowseo-protip__icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" fill="#fbbf24" />
                        <path d="M12 8v4M12 16h.01" stroke="#1d2327" strokeWidth="2.5" strokeLinecap="round" />
                    </svg>
                </div>
                <div className="snowseo-protip__content">
                    <h3 className="snowseo-protip__title">Pro Tip</h3>
                    <p className="snowseo-protip__text">
                        Enable "Auto-Internal Linking" in the cloud app to boost your SEO score by up to 15%. This helps
                        distribute link equity throughout your site automatically.
                    </p>
                </div>
                <a
                    href="https://snowseo.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="snowseo-protip__btn"
                >
                    Learn More
                </a>
            </div>
            */}
        </div>
    );
}
