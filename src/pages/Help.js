

export default function Help() {
    return (
        <div className="snowseo-dashboard">
            {/* Breadcrumb */}
            <div className="snowseo-breadcrumb">
                <a href="#/dashboard" className="snowseo-breadcrumb__link">SnowSEO</a>
                <span className="snowseo-breadcrumb__sep">/</span>
                <span className="snowseo-breadcrumb__current">Help</span>
            </div>

            {/* Page Title */}
            <h1 className="snowseo-dashboard__title">Help & Support</h1>

            {/* Tab Navigation */}
            <div className="snowseo-tabs">
                <a href="#/dashboard" className="snowseo-tabs__item">Dashboard</a>
                <a href="#/settings" className="snowseo-tabs__item">Settings</a>
                {/* <a href="#/logs" className="snowseo-tabs__item">Logs</a> */}
                {/* <a href="#/help" className="snowseo-tabs__item snowseo-tabs__item--active">Help</a> */}
            </div>

            {/* Main Content */}
            <div className="snowseo-dashboard__grid">

                {/* Left Column: Main Resources */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>

                    {/* Documentation Card */}
                    <div className="snowseo-status-card">
                        <div className="snowseo-status-card__header">
                            <div className="snowseo-status-card__check">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <polyline points="10 9 9 9 8 9"></polyline>
                                </svg>
                            </div>
                            <div>
                                <h3 className="snowseo-status-card__title">Documentation</h3>
                                <p className="snowseo-status-card__subtitle">Explore our comprehensive guides and tutorials.</p>
                            </div>
                        </div>
                        <div className="snowseo-status-card__footer">
                            <span className="snowseo-status-card__version">Updated frequently</span>
                            <a href="https://snowseo.com/docs/user-guide" target="_blank" rel="noopener noreferrer" className="snowseo-status-card__manage">
                                Open Documentation
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                    <polyline points="15 3 21 3 21 9"></polyline>
                                    <line x1="10" y1="14" x2="21" y2="3"></line>
                                </svg>
                            </a>
                        </div>
                    </div>

                    {/* Support Card */}
                    <div className="snowseo-status-card">
                        <div className="snowseo-status-card__header">
                            <div className="snowseo-status-card__check">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 className="snowseo-status-card__title">Contact Support</h3>
                                <p className="snowseo-status-card__subtitle">Need assistance? Our team is here to help.</p>
                            </div>
                        </div>
                        <div className="snowseo-status-card__footer">
                            <span className="snowseo-status-card__version">Mon-Fri 9am-5pm EST</span>
                            <a href="mailto:support@snowseo.com" className="snowseo-status-card__manage" rel="noopener noreferrer">
                                Email Support
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                    <line x1="22" y1="2" x2="11" y2="13"></line>
                                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                </svg>
                            </a>
                        </div>
                    </div>

                </div>

                {/* Right Column: FAQ & Community */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: '20px' }}>

                    {/* FAQ Card */}
                    <div className="snowseo-activity-card">
                        <div className="snowseo-activity-card__header">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                            <h3 className="snowseo-activity-card__title">Common Questions</h3>
                        </div>

                        <div className="snowseo-activity-list">
                            <div className="snowseo-activity-item">
                                <div className="snowseo-activity-item__dot" style={{ background: '#cbd5e1' }}></div>
                                <div className="snowseo-activity-item__content">
                                    <a href="https://snowseo.com/docs/user-guide/others/frequently-asked-questions" target="_blank" rel="noopener noreferrer" className="snowseo-activity-item__title" style={{ textDecoration: 'none' }}>How to configure auto-publishing?</a>
                                    <span className="snowseo-activity-item__desc">Check Settings &gt; Automation</span>
                                </div>
                            </div>
                            <div className="snowseo-activity-item">
                                <div className="snowseo-activity-item__dot" style={{ background: '#cbd5e1' }}></div>
                                <div className="snowseo-activity-item__content">
                                    <a href="https://snowseo.com/docs/user-guide/others/frequently-asked-questions" target="_blank" rel="noopener noreferrer" className="snowseo-activity-item__title" style={{ textDecoration: 'none' }}>Connection troubleshooting</a>
                                    <span className="snowseo-activity-item__desc">Guide to resolving sync issues</span>
                                </div>
                            </div>
                            <div className="snowseo-activity-item">
                                <div className="snowseo-activity-item__dot" style={{ background: '#cbd5e1' }}></div>
                                <div className="snowseo-activity-item__content">
                                    <a href="https://snowseo.com/docs/user-guide/others/frequently-asked-questions" target="_blank" rel="noopener noreferrer" className="snowseo-activity-item__title" style={{ textDecoration: 'none' }}>API Key management</a>
                                    <span className="snowseo-activity-item__desc">How to regenerate keys</span>
                                </div>
                            </div>
                        </div>

                        <a href="https://snowseo.com/docs/user-guide/others/frequently-asked-questions" target="_blank" rel="noopener noreferrer" className="snowseo-activity-card__view-all">
                            View All FAQs
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                                <path d="M5 12h14M12 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>

                </div>
            </div>

            {/* Community Banner */}
            <div className="snowseo-protip">
                <div className="snowseo-protip__icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke="#fbbf24" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        <circle cx="9" cy="7" r="4" stroke="#fbbf24" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" stroke="#fbbf24" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" stroke="#fbbf24" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                    </svg>
                </div>
                <div className="snowseo-protip__content">
                    <h3 className="snowseo-protip__title">Community Forum</h3>
                    <p className="snowseo-protip__text">
                        Join 2,000+ SEO professionals sharing strategies and tips in our private Slack community.
                    </p>
                </div>
                <a
                    href="https://go.snowseo.com/discord"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="snowseo-protip__btn"
                >
                    Join Community
                </a>
            </div>

        </div>
    );
}
