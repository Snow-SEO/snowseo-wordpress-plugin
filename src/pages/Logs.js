import { useState, useEffect } from '@wordpress/element';
import { getLogs, getLogStats } from '../api';

const getAppUrl = () => {
    return 'https://snowseo.com/dashboard';
};

export default function Logs() {
    const [logs, setLogs] = useState([]);
    const [pagination, setPagination] = useState({ page: 1, perPage: 20, total: 0, totalPages: 0 });
    const [filter, setFilter] = useState('all');
    const [loading, setLoading] = useState(true);
    const [stats, setStats] = useState({ total: 0, publishedToday: 0, failed: 0, successRate: 0 });

    const fetchLogs = async (page = 1, status = 'all') => {
        setLoading(true);
        try {
            const res = await getLogs(page, 20, status);
            setLogs(res.logs || []);
            setPagination(res.pagination || { page: 1, perPage: 20, total: 0, totalPages: 0 });
        } catch (err) {
            console.error('Failed to fetch logs:', err);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchLogs(1, filter);
    }, [filter]);

    useEffect(() => {
        getLogStats().then(setStats).catch(console.error);
    }, []);

    const hasLogs = logs.length > 0;

    const STATUS_ICONS = {
        published: { color: '#16a34a', bg: '#dcfce7' },
        connected: { color: '#2563eb', bg: '#eff6ff' },
        disconnected: { color: '#94a3b8', bg: '#f1f5f9' },
        invalidated: { color: '#f59e0b', bg: '#fefce8' },
        error: { color: '#dc2626', bg: '#fef2f2' },
        failed: { color: '#dc2626', bg: '#fef2f2' },
    };

    return (
        <div className="snowseo-logs">
            {/* Breadcrumb */}
            <div className="snowseo-breadcrumb">
                <a href="#/dashboard" className="snowseo-breadcrumb__link">SnowSEO</a>
                <span className="snowseo-breadcrumb__sep">/</span>
                <span className="snowseo-breadcrumb__current">Logs</span>
            </div>

            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                <h1 className="snowseo-page-title" style={{ margin: 0 }}>Logs</h1>
            </div>

            {/* Tab Navigation */}
            <div className="snowseo-tabs">
                <a href="#/dashboard" className="snowseo-tabs__item">Dashboard</a>
                {/* <a href="#/articles" className="snowseo-tabs__item">Articles</a> */}
                <a href="#/settings" className="snowseo-tabs__item">Settings</a>
                <a href="#/logs" className="snowseo-tabs__item snowseo-tabs__item--active">Logs</a>
                {/* <a href="#/help" className="snowseo-tabs__item">Help</a> */}
            </div>

            <div className="snowseo-logs-container" style={{ alignItems: 'stretch' }}>

                {!hasLogs && !loading ? (
                    // EMPTY STATE
                    <>
                        <div className="snowseo-empty-state" style={{ margin: '0 auto' }}>
                            <div className="snowseo-empty-state__icon-wrap">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#136dec" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z" />
                                </svg>
                                <div className="snowseo-empty-state__badge">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
                                        <circle cx="12" cy="12" r="10" />
                                        <polyline points="12 6 12 12 16 14" />
                                    </svg>
                                </div>
                            </div>

                            <h2 className="snowseo-empty-state__title">No activity yet</h2>
                            <p className="snowseo-empty-state__desc">
                                SnowSEO acts as your autopilot. Once you publish or connect, your activity history will appear here automatically.
                            </p>

                            <a href="https://snowseo.com/dashboard/articles/new" target="_blank" rel="noopener noreferrer" className="snowseo-empty-state__btn">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="16" />
                                    <line x1="8" y1="12" x2="16" y2="12" />
                                </svg>
                                Create your first article
                            </a>
                        </div>

                        {/* Help Card */}
                        <div className="snowseo-help-card" style={{ margin: '0 auto' }}>
                            <div className="snowseo-help-card__icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                                    <line x1="12" y1="17" x2="12.01" y2="17" />
                                </svg>
                            </div>
                            <div className="snowseo-help-card__content">
                                <h3 className="snowseo-help-card__title">Need help getting started?</h3>
                                <p className="snowseo-help-card__text">Learn how to set up your first automation schedule.</p>
                            </div>
                            <a href="https://snowseo.com/docs/user-guide" target="_blank" rel="noopener noreferrer" className="snowseo-help-card__link">
                                View Documentation
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <line x1="5" y1="12" x2="19" y2="12" />
                                    <polyline points="12 5 19 12 12 19" />
                                </svg>
                            </a>
                        </div>
                    </>
                ) : (
                    // POPULATED STATE
                    <>
                        {/* Stats Grid */}
                        <div className="snowseo-logs__stats-grid">
                            <div className="snowseo-logs-stat-card">
                                <div className="snowseo-logs-stat-card__content">
                                    <span className="snowseo-logs-stat-card__label">Published Today</span>
                                    <span className="snowseo-logs-stat-card__value">{stats.publishedToday}</span>
                                </div>
                                <div className="snowseo-logs-stat-card__icon" style={{ background: '#dcfce7' }}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg>
                                </div>
                            </div>
                            <div className="snowseo-logs-stat-card">
                                <div className="snowseo-logs-stat-card__content">
                                    <span className="snowseo-logs-stat-card__label">Failed Items</span>
                                    <span className="snowseo-logs-stat-card__value">{stats.failed}</span>
                                </div>
                                <div className="snowseo-logs-stat-card__icon" style={{ background: '#fef2f2' }}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <circle cx="12" cy="12" r="10" />
                                        <line x1="12" y1="8" x2="12" y2="12" />
                                        <line x1="12" y1="16" x2="12.01" y2="16" />
                                    </svg>
                                </div>
                            </div>
                            <div className="snowseo-logs-stat-card">
                                <div className="snowseo-logs-stat-card__content">
                                    <span className="snowseo-logs-stat-card__label">Total Events</span>
                                    <span className="snowseo-logs-stat-card__value">{stats.total}</span>
                                </div>
                                <div className="snowseo-logs-stat-card__icon" style={{ background: '#eff6ff' }}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2563eb" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M4 19.5A2.5 2.5 0 016.5 17H20" />
                                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z" />
                                    </svg>
                                </div>
                            </div>
                            <div className="snowseo-logs-stat-card">
                                <div className="snowseo-logs-stat-card__content">
                                    <span className="snowseo-logs-stat-card__label">Success Rate</span>
                                    <span className="snowseo-logs-stat-card__value">{stats.successRate}%</span>
                                </div>
                                <div className="snowseo-logs-stat-card__icon" style={{ background: '#f1f5f9' }}>
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748b" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                        <line x1="18" y1="20" x2="18" y2="10" />
                                        <line x1="12" y1="20" x2="12" y2="4" />
                                        <line x1="6" y1="20" x2="6" y2="14" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {/* Section Header */}
                        <div className="snowseo-logs__section-header">
                            <div>
                                <h3 className="snowseo-logs__section-title">Activity Log</h3>
                                <p className="snowseo-logs__section-desc">Detailed history of all plugin activity.</p>
                            </div>
                        </div>

                        {/* Table Container */}
                        <div className="snowseo-table-container">
                            {/* Filter Bar */}
                            <div className="snowseo-logs__filters">
                                <div className="snowseo-filter-group">
                                    <button
                                        className={`snowseo-filter-btn ${filter === 'all' ? 'snowseo-filter-btn--active' : ''}`}
                                        onClick={() => setFilter('all')}
                                    >
                                        All
                                    </button>
                                    <button
                                        className={`snowseo-filter-btn ${filter === 'published' ? 'snowseo-filter-btn--active' : ''}`}
                                        onClick={() => setFilter('published')}
                                    >
                                        <div className="snowseo-filter-dot" style={{ background: '#16a34a' }}></div>
                                        Published
                                    </button>
                                    <button
                                        className={`snowseo-filter-btn ${filter === 'connected' ? 'snowseo-filter-btn--active' : ''}`}
                                        onClick={() => setFilter('connected')}
                                    >
                                        <div className="snowseo-filter-dot" style={{ background: '#2563eb' }}></div>
                                        Connected
                                    </button>
                                    <button
                                        className={`snowseo-filter-btn ${filter === 'error' ? 'snowseo-filter-btn--active' : ''}`}
                                        onClick={() => setFilter('error')}
                                    >
                                        <div className="snowseo-filter-dot" style={{ background: '#dc2626' }}></div>
                                        Errors
                                    </button>
                                </div>
                            </div>

                            {loading ? (
                                <div style={{ padding: '40px', textAlign: 'center', color: '#64748b' }}>
                                    Loading logs...
                                </div>
                            ) : (
                                <>
                                    {/* Table */}
                                    <table className="snowseo-table">
                                        <thead>
                                            <tr>
                                                <th>Date & Time</th>
                                                <th>Article Title</th>
                                                <th>Type</th>
                                                <th>SEO Score</th>
                                                <th>Status</th>
                                                <th style={{ textAlign: 'right' }}>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {logs.map(item => {
                                                const iconStyle = STATUS_ICONS[item.status] || STATUS_ICONS.connected;
                                                return (
                                                    <tr key={item.id}>
                                                        <td style={{ width: '180px' }}>
                                                            <div className="snowseo-cell-primary">
                                                                <span style={{ fontWeight: 600, color: '#1d2327', fontSize: '13px' }}>
                                                                    {item.date ? new Date(item.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—'}
                                                                </span>
                                                                <span className="snowseo-cell-subtitle">
                                                                    {item.date ? new Date(item.date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : ''}
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div className="snowseo-cell-primary">
                                                                <span className="snowseo-cell-title">{item.details?.title || item.message}</span>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span style={{ color: '#64748b', fontSize: '13px' }}>
                                                                {item.details?.type || '—'}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span style={{ fontWeight: 500, color: '#1d2327', fontSize: '13px' }}>
                                                                {item.details?.seoScore != null ? `${item.details.seoScore}%` : '—'}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span
                                                                style={{
                                                                    display: 'inline-flex',
                                                                    alignItems: 'center',
                                                                    gap: '6px',
                                                                    padding: '3px 10px',
                                                                    borderRadius: '12px',
                                                                    fontSize: '12px',
                                                                    fontWeight: 500,
                                                                    background: iconStyle.bg,
                                                                    color: iconStyle.color,
                                                                }}
                                                            >
                                                                <span style={{
                                                                    width: '6px', height: '6px', borderRadius: '50%',
                                                                    background: iconStyle.color,
                                                                }}></span>
                                                                {item.status}
                                                            </span>
                                                        </td>
                                                        <td style={{ textAlign: 'right' }}>
                                                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '8px' }}>
                                                                {item.details?.url && (
                                                                    <a href={item.details.url} target="_blank" rel="noopener noreferrer" style={{ color: '#94a3b8' }} title="View on Site">
                                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                                                    </a>
                                                                )}
                                                                {item.details?.articleId && item.status === 'published' && (
                                                                    <a href={`${getAppUrl()}/articles/editor?articleId=${item.details.articleId}`} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', background: '#2563eb', color: '#fff', padding: '4px 10px', borderRadius: '4px', fontSize: '13px', textDecoration: 'none', fontWeight: 500 }}>
                                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" /><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" /></svg>
                                                                        Edit
                                                                    </a>
                                                                )}
                                                                {item.details?.articleId && (item.status === 'error' || item.status === 'failed') && (
                                                                    <a href={`${getAppUrl()}/articles/editor?articleId=${item.details.articleId}`} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', background: '#2563eb', color: '#fff', padding: '4px 10px', borderRadius: '4px', fontSize: '13px', textDecoration: 'none', fontWeight: 500 }}>
                                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="1 4 1 10 7 10" /><polyline points="23 20 23 14 17 14" /><path d="M20.49 9A9 9 0 005.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 013.51 15" /></svg>
                                                                        Retry
                                                                    </a>
                                                                )}
                                                                {!item.details?.url && !item.details?.articleId && (
                                                                    <span style={{ color: '#94a3b8', fontSize: '13px' }}>—</span>
                                                                )}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>

                                    {/* Pagination */}
                                    <div className="snowseo-logs-pagination">
                                        <span style={{ fontSize: '13px', color: '#64748b', marginRight: 'auto' }}>
                                            Showing page {pagination.page} of {pagination.totalPages || 1} ({pagination.total} results)
                                        </span>
                                        <button
                                            className="snowseo-page-btn"
                                            disabled={pagination.page <= 1}
                                            onClick={() => fetchLogs(pagination.page - 1, filter)}
                                        >
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="15 18 9 12 15 6" /></svg>
                                        </button>
                                        <button className="snowseo-page-btn snowseo-page-btn--active">{pagination.page}</button>
                                        <button
                                            className="snowseo-page-btn"
                                            disabled={pagination.page >= pagination.totalPages}
                                            onClick={() => fetchLogs(pagination.page + 1, filter)}
                                        >
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
