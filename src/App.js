import { useState, useEffect } from '@wordpress/element';
import Settings from './pages/Settings';
import Dashboard from './pages/Dashboard';
import SettingsConnected from './pages/SettingsConnected';
import Articles from './pages/Articles';
import Performance from './pages/Performance';
// import Logs from './pages/Logs'; // Logs tab commented out for now
import Help from './pages/Help';
import { disconnectSite, getStatus } from './api';

/* ──────────────────────────────────────────── */
/*  Placeholder page for unbuilt routes         */
/* ──────────────────────────────────────────── */
function ComingSoon({ title }) {
    return (
        <div className="snowseo-coming-soon">
            <div className="snowseo-coming-soon__icon">
                <span className="dashicons dashicons-hammer"></span>
            </div>
            <h2>{title}</h2>
            <p>This section is under development and will be available soon.</p>
        </div>
    );
}

/* ──────────────────────────────────────────── */
/*  Page content (Phase 2)                      */
/* ──────────────────────────────────────────── */
function PageContent({ route }) {
    // Normalization incase route has slashes (e.g. settings vs /settings)
    const routeId = route.replace(/^\//, '');

    switch (routeId) {
        case 'dashboard':
            return <Dashboard />;
        case 'articles':
            return <Articles />;
        case 'content-queue':
            return <ComingSoon title="Content Queue" />;
        case 'automation':
            return <ComingSoon title="Automation Rules" />;
        case 'analytics':
            return <ComingSoon title="Analytics" />;
        case 'performance':
            return <Performance />;
        case 'settings':
            return <SettingsConnected />;
        // case 'logs':
        //     return <Logs />;
        case 'help':
            return <Help />;
        default:
            return <Dashboard />;
    }
}

/* ──────────────────────────────────────────── */
/*  Main App                                    */
/* ──────────────────────────────────────────── */
export default function App() {
    // Connection state: start from PHP, then verify with API on load
    const [connected, setConnected] = useState(() => {
        return !!window.snowseoData?.connected;
    });

    const [route, setRoute] = useState('dashboard');
    const [statusChecked, setStatusChecked] = useState(false);

    // Verify connection on load — if dashboard disconnected, revert to connect screen
    useEffect(() => {
        if (!connected) {
            setStatusChecked(true);
            return;
        }
        getStatus()
            .then((res) => {
                if (res && res.connected === false) {
                    setConnected(false);
                }
            })
            .catch(() => {
                setConnected(false);
            })
            .finally(() => setStatusChecked(true));
    }, [connected]);

    // Routing Logic: Listen for hash changes
    useEffect(() => {
        const onHashChange = () => {
            // Normalize hash
            const hash = window.location.hash.replace(/^#\/?/, '');

            // Handle Disconnect (require confirmation to prevent CSRF-like link attacks)
            if (hash === 'disconnect') {
                if (window.confirm('Are you sure you want to disconnect SnowSEO from this site?')) {
                    disconnectSite().then(() => {
                        setConnected(false);
                        window.location.hash = '';
                        window.location.reload();
                    }).catch((err) => {
                        console.error('Failed to disconnect:', err);
                        window.location.hash = '';
                    });
                } else {
                    window.location.hash = '';
                }
                return;
            }

            setRoute(hash || 'dashboard');
        };

        window.addEventListener('hashchange', onHashChange);

        // Initial check on mount
        onHashChange();

        return () => window.removeEventListener('hashchange', onHashChange);
    }, []);

    // Connection callback from Settings page
    const handleConnect = () => {
        setConnected(true);
        window.location.reload();
    };

    // Phase 1: Connection page (no top bar, no navigation)
    if (!connected) {
        return (
            <div className="snowseo-app snowseo-app--setup">
                <div className="snowseo-setup-container">
                    <Settings onConnect={handleConnect} />
                </div>
            </div>
        );
    }

    // Wait for status check so we don't flash dashboard then switch to connect
    if (!statusChecked) {
        return (
            <div className="snowseo-app snowseo-app--connected" style={{ alignItems: 'center', justifyContent: 'center', minHeight: '200px' }}>
                <p style={{ color: '#64748b', fontSize: '14px' }}>Checking connection…</p>
            </div>
        );
    }

    // Phase 2: Connected — no navbar, just main content
    return (
        <div className="snowseo-app snowseo-app--connected">
            <main className="snowseo-main">
                <PageContent route={route} />
            </main>
        </div>
    );
}
