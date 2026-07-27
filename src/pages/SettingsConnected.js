import { useState, useEffect } from '@wordpress/element';
import { getSettings } from '../api';

const getAppUrl = () => {
    return 'https://snowseo.com/dashboard';
};

export default function SettingsConnected() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    useEffect(() => {
        getSettings()
            .then(setData)
            .catch((err) => {
                console.error('Failed to fetch settings:', err);
                setError(err.message || 'Failed to load settings');
            })
            .finally(() => setLoading(false));
    }, []);

    const settings = data?.settings || {};
    const articleStats = data?.articleStats || {};

    const connectedAt = settings.connectedAt
        ? new Date(settings.connectedAt).toLocaleString()
        : '—';

    const settingsRows = [
        {
            setting: 'Auto-publishing',
            value: settings.automationEnabled ? 'Enabled' : 'Disabled',
            valueType: settings.automationEnabled ? 'badge-green' : 'text',
            source: 'snowseo',
        },
        {
            setting: 'Publishing Frequency',
            value: settings.postingFrequency?.replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || '—',
            valueType: 'text',
            source: 'snowseo',
        },
        {
            setting: 'Target Post Status',
            value: 'Draft',
            valueType: 'text',
            // source: wordpress
            source: 'snowseo',
        },
        {
            setting: 'Content Language',
            value: settings.defaultLanguage === 'en' ? 'English (US)' : (settings.defaultLanguage || '—'),
            valueType: 'text',
            source: 'snowseo',
        },
        {
            setting: 'Image Source',
            value: settings.defaultImageService === 'stock' ? 'Stock Images' :
                settings.defaultImageService === 'ai-generated' ? 'AI Generated (DALL-E 3)' : (settings.defaultImageService || '—'),
            valueType: 'text',
            source: 'snowseo',
        },
        {
            setting: 'Keyword Clusters',
            value: settings.keywordClusters || settings.categories || '—',
            valueType: 'text',
            source: 'snowseo',
        },
    ];

    return (
        <div className="snowseo-settings-connected">
            {/* Breadcrumb */}
            <div className="snowseo-breadcrumb">
                <a href="#/dashboard" className="snowseo-breadcrumb__link">SnowSEO</a>
                <span className="snowseo-breadcrumb__sep">/</span>
                <span className="snowseo-breadcrumb__current">Settings</span>
            </div>

            {/* Page Title */}
            <h1 className="snowseo-page-title">Settings</h1>

            {/* Tab Navigation */}
            <div className="snowseo-tabs">
                <a href="#/dashboard" className="snowseo-tabs__item">Dashboard</a>
                 {/* <a href="#/articles" className="snowseo-tabs__item">Articles</a> */}
                <a href="#/settings" className="snowseo-tabs__item snowseo-tabs__item--active">Settings</a>
                {/* <a href="#/logs" className="snowseo-tabs__item">Logs</a> */}
                {/* <a href="#/help" className="snowseo-tabs__item">Help</a> */}
            </div>

            {/* Blue Banner */}
            <div className="snowseo-banner">
                <div className="snowseo-banner__content">
                    <div className="snowseo-banner__icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18" />
                            <line x1="7" y1="2" x2="7" y2="22" />
                            <line x1="17" y1="2" x2="17" y2="22" />
                            <line x1="2" y1="12" x2="22" y2="12" />
                            <line x1="2" y1="7" x2="7" y2="7" />
                            <line x1="2" y1="17" x2="7" y2="17" />
                            <line x1="17" y1="7" x2="22" y2="7" />
                            <line x1="17" y1="17" x2="22" y2="17" />
                        </svg>
                    </div>
                    <div>
                        <h3 className="snowseo-banner__title">Publishing is managed by SnowSEO</h3>
                        <p className="snowseo-banner__text">
                            To prevent conflicts, direct configuration on this WordPress instance is disabled. All
                            publishing rules are streamed directly from your cloud account.
                        </p>
                    </div>
                </div>
                <a
                    href={`${getAppUrl()}?openSettings=true&tab=integrations&subTab=cms-publishing&expand=wp`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="snowseo-banner__btn snowseo-banner__btn--outline"
                >
                    Manage in SnowSEO
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7" />
                    </svg>
                </a>
            </div>

            {/* Configuration Table */}
            <div className="snowseo-config-card">
                <div className="snowseo-config-card__header">
                    <h2 className="snowseo-config-card__title">Current Publishing Configuration</h2>
                    <p className="snowseo-config-card__subtitle">Read-only view of active parameters</p>
                </div>

                {loading ? (
                    <div style={{ padding: '40px', textAlign: 'center', color: '#64748b' }}>
                        Loading settings...
                    </div>
                ) : error ? (
                    <div style={{ padding: '40px', textAlign: 'center', color: '#dc2626' }}>
                        {error}
                    </div>
                ) : (
                    <div className="snowseo-config-table">
                        {/* Table Header */}
                        <div className="snowseo-config-table__head">
                            <span className="snowseo-config-table__col snowseo-config-table__col--setting">Setting</span>
                            <span className="snowseo-config-table__col snowseo-config-table__col--value">Current Value</span>
                            <span className="snowseo-config-table__col snowseo-config-table__col--source">Source</span>
                        </div>

                        {/* Table Rows */}
                        {settingsRows.map((row, index) => (
                            <div className="snowseo-config-table__row" key={index}>
                                <span className="snowseo-config-table__col snowseo-config-table__col--setting">
                                    {row.setting}
                                </span>
                                <span className="snowseo-config-table__col snowseo-config-table__col--value">
                                    {row.valueType === 'badge-green' ? (
                                        <span className="snowseo-config-badge snowseo-config-badge--green">{row.value}</span>
                                    ) : (
                                        row.value
                                    )}
                                </span>
                                <span className="snowseo-config-table__col snowseo-config-table__col--source">
                                    {row.source === 'snowseo' ? (
                                        <span className="snowseo-source snowseo-source--cloud">
                                            SnowSEO
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                                                <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71" />
                                                <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71" />
                                            </svg>
                                        </span>
                                    ) : (
                                        <span className="snowseo-source snowseo-source--wp">
                                            WordPress
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                <rect x="3" y="3" width="18" height="18" rx="2" />
                                                <path d="M9 3v18" />
                                            </svg>
                                        </span>
                                    )}
                                </span>
                            </div>
                        ))}
                    </div>
                )}

                {/* Footer */}
                {/* <div className="snowseo-config-card__footer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <polyline points="23 4 23 10 17 10" />
                        <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10" />
                    </svg>
                    Settings are synced automatically every 15 minutes.
                </div> */}
            </div>
        </div>
    );
}
