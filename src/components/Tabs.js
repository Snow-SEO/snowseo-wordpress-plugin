/**
 * Admin tab strip.
 *
 * Extracted because the same markup was duplicated verbatim in Dashboard.js and
 * SettingsConnected.js - adding a third tab meant editing both copies and
 * keeping them in step.
 */

const TABS = [
    { id: 'dashboard', href: '#/dashboard', label: 'Dashboard' },
    { id: 'performance', href: '#/performance', label: 'Page Speed' },
    { id: 'settings', href: '#/settings', label: 'Settings' },
];

export default function Tabs({ current }) {
    return (
        <div className="snowseo-tabs">
            {TABS.map((tab) => (
                <a
                    key={tab.id}
                    href={tab.href}
                    className={
                        'snowseo-tabs__item' +
                        (tab.id === current ? ' snowseo-tabs__item--active' : '')
                    }
                >
                    {tab.label}
                </a>
            ))}
        </div>
    );
}
