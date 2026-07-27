import { useState } from '@wordpress/element';
import { connectSite } from '../api';
import snowseoLogo from '../logo-color-data';

export default function Settings({ onConnect }) {
    const [token, setToken] = useState('');
    const [showKey, setShowKey] = useState(false);
    const [isConnecting, setIsConnecting] = useState(false);
    const [error, setError] = useState('');
    const pluginVersion = window.snowseoData?.version || '1.0.0';

    const handleConnect = async () => {
        if (!token.trim()) { return; }

        setIsConnecting(true);
        setError('');

        try {
            await connectSite(token.trim());
            onConnect();
        } catch (err) {
            setError(err.message || 'Failed to connect. Please check your token and try again.');
        } finally {
            setIsConnecting(false);
        }
    };

    return (
        <div className="snowseo-settings">
            {/* Page Title Row */}
            <div className="snowseo-settings__header">
                <div>
                    <h1 className="snowseo-settings__title">SnowSEO Integration</h1>
                    <p className="snowseo-settings__subtitle">
                        Connect your site to enable AI-powered automated publishing.
                    </p>
                </div>
                <a
                    className="snowseo-settings__docs-link"
                    href="https://snowseo.com/docs/user-guide"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Documentation
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                        <title>Documentation</title>
                        <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6" />
                        <polyline points="15 3 21 3 21 9" />
                        <line x1="10" y1="14" x2="21" y2="3" />
                    </svg>
                </a>
            </div>

            {/* Info Box */}
            <div className="snowseo-info-box">
                <div className="snowseo-info-box__icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <title>Automated Publishing Management</title>
                        <circle cx="12" cy="12" r="10" stroke="#136dec" strokeWidth="2" />
                        <line x1="12" y1="16" x2="12" y2="12" stroke="#136dec" strokeWidth="2" strokeLinecap="round" />
                        <circle cx="12" cy="8" r="1" fill="#136dec" />
                    </svg>
                </div>
                <div className="snowseo-info-box__content">
                    <h3 className="snowseo-info-box__title">Automated Publishing Management</h3>
                    <p className="snowseo-info-box__text">
                        Once connected, content publishing is managed entirely from your SnowSEO dashboard.
                        This plugin acts as a receiver for your scheduled content. No configuration is needed
                        here after connection.
                    </p>
                </div>
            </div>

            {/* Connection Card */}
            <div className="snowseo-connection-card">
                {/* Card Header */}
                <div className="snowseo-connection-card__header">
                    <div>
                        <h2 className="snowseo-connection-card__heading">Connection Status</h2>
                        <p className="snowseo-connection-card__desc">Current integration state with SnowSEO platform.</p>
                    </div>
                    <div className="snowseo-status-badge snowseo-status-badge--disconnected">
                        <span className="snowseo-status-badge__dot" />
                        <span>Not Connected</span>
                    </div>
                </div>

                {/* Card Body */}
                <div className="snowseo-connection-card__body">
                    {/* WordPress ↔ SnowSEO Visual */}
                    <div className="snowseo-connection-visual">
                        {/* WordPress Icon */}
                        <div className="snowseo-connection-visual__item">
                            <div className="snowseo-connection-visual__icon snowseo-connection-visual__icon--wp">
                                <svg
                                    width="48"
                                    height="48"
                                    viewBox="0 0 19.22 19.22"
                                    fill="#1d2327"
                                    xmlns="http://www.w3.org/2000/svg"
                                    role="img"
                                    aria-label="WordPress"
                                    style={{ objectFit: 'contain' }}
                                >
                                    <path d="M18.029,5.13c-1.452-2.841-5.127-5.528-9.752-4.876C0.865,1.295-3.04,10.682,2.878,16.452 C10.036,23.431,23.25,15.328,18.029,5.13z M7.581,18.541c-3.828-0.652-7.065-4.636-7.141-8.708 C0.343,4.748,3.871,1.395,8.104,0.775c5.924-0.869,11.265,3.456,10.449,10.103C17.885,16.331,12.805,19.432,7.581,18.541z M5.84,5.479C5.605,5.768,4.851,5.537,4.62,5.827c0.95,2.88,1.793,5.87,2.961,8.533c1.114-2.884,2.343-5.696,0.522-8.533 c-0.292-0.29-1.273,0.111-1.22-0.523c0.778-0.312,4.271-0.312,5.05,0c-0.002,0.578-0.982,0.177-1.219,0.523 c0.963,2.811,1.735,5.814,2.961,8.36c0.327-2.11,1.444-2.872,1.393-5.052c-0.055-2.484-3.17-4.662-0.174-5.748 C11.977,0.257,4.153,1.215,2.878,5.13C4.041,5.38,5.613,4.608,5.84,5.479z M13.676,16.452c3.17-1.164,5.382-6.925,2.96-10.452 C16.688,9.838,14.62,13.03,13.676,16.452z M5.84,16.8C4.671,13.206,3.468,9.648,2.007,6.35C0.334,10.7,2.473,15.388,5.84,16.8z M7.231,17.322c1.154,0.604,3.683,0.419,4.876,0c-0.761-2.374-1.339-4.929-2.612-6.793C8.884,12.937,8.044,15.117,7.231,17.322z" />
                                </svg>
                            </div>
                            <span className="snowseo-connection-visual__label">WORDPRESS</span>
                        </div>

                        {/* Connector */}
                        <div className="snowseo-connection-visual__connector">
                            <span className="snowseo-connection-visual__line" />
                            <svg className="snowseo-connection-visual__x" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" strokeWidth="2.5" strokeLinecap="round" aria-hidden="true">
                                <title>X</title>
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                            <span className="snowseo-connection-visual__line" />
                        </div>

                        {/* SnowSEO Icon */}
                        <div className="snowseo-connection-visual__item">
                            <div className="snowseo-connection-visual__icon snowseo-connection-visual__icon--seo">
                                {/** biome-ignore lint/performance/noImgElement: <> */}
                                <img
                                    src={snowseoLogo}
                                    alt="SnowSEO"
                                    width={48}
                                    height={48}
                                    style={{ objectFit: 'contain' }}
                                />
                            </div>
                            <span className="snowseo-connection-visual__label">SNOWSEO</span>
                        </div>
                    </div>

                    {/* Token Input */}
                    <div className="snowseo-token-form">
                        <div className="snowseo-token-form__label-row">
                            <label htmlFor="token" className="snowseo-token-form__label">SnowSEO Site Token</label>
                            <a
                                className="snowseo-token-form__help"
                                href="https://snowseo.com/docs/user-guide/others/integrations#plugin"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Where do I find my token?
                            </a>
                        </div>

                        <div className="snowseo-token-form__input-wrap">
                            <input
                                type={showKey ? 'text' : 'password'}
                                className="snowseo-token-form__input"
                                placeholder="sw_live_xxxxxxxxxxxxxxxxxxxx"
                                value={token}
                                onChange={(e) => setToken(e.target.value)}
                                id="token"
                            />
                            <button
                                type="button"
                                className="snowseo-token-form__key-toggle"
                                onClick={() => setShowKey((v) => !v)}
                                title={showKey ? 'Hide token' : 'Show token'}
                                aria-label={showKey ? 'Hide token' : 'Show token'}
                            >
                                {showKey ? (
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                        <title>Hide token</title>
                                        <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24" />
                                        <line x1="1" y1="1" x2="23" y2="23" />
                                    </svg>
                                ) : (
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                        <title>Show token</title>
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                )}
                            </button>
                        </div>

                        <p className="snowseo-token-form__hint">
                            Paste the site token generated in your SnowSEO project settings.
                        </p>

                        {error && (
                            <div className="snowseo-token-form__error" style={{ color: '#ef4444', fontSize: '13px', marginBottom: '8px', padding: '8px 12px', background: '#fef2f2', borderRadius: '6px', border: '1px solid #fecaca' }}>
                                {error}
                            </div>
                        )}

                        {/* Connect Button */}
                        <button
                            className="snowseo-btn-connect"
                            type="button"
                            onClick={handleConnect}
                            disabled={!token.trim() || isConnecting}
                        >
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                                <title>Connecting...</title>
                                <path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71" />
                                <path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71" />
                            </svg>
                            {isConnecting ? 'Connecting...' : 'Save & Connect'}
                        </button>

                        <p className="snowseo-token-form__disclaimer">
                            By connecting, you grant SnowSEO permission to create drafts and publish posts on this site.
                        </p>
                    </div>
                </div>
            </div>

            {/* Footer */}
            <div className="snowseo-settings__footer">
                <p>
                    Need help?{' '}
                    <a href="https://snowseo.com/docs/user-guide/others/integrations#plugin" target="_blank" rel="noopener noreferrer">
                        Read the setup guide
                    </a>{' '}
                    or{' '}
                    <a href="https://snowseo.com/contact-us" target="_blank" rel="noopener noreferrer">
                        Contact Support
                    </a>
                    .
                </p>
                <p className="snowseo-settings__footer-version">SnowSEO Plugin v{pluginVersion}</p>
            </div>
        </div>
    );
}
