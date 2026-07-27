import { useState, useEffect } from '@wordpress/element';
import { getArticles, publishArticle } from '../api';

const STATUS_LABELS = {
    draft: 'Draft',
    generated: 'Generated',
    published: 'Published',
    scheduled: 'Scheduled',
    failed: 'Failed',
};

const STATUS_COLORS = {
    draft: '#94a3b8',
    generated: '#3b82f6',
    published: '#22c55e',
    scheduled: '#f59e0b',
    failed: '#ef4444',
};

function StatusBadge({ status }) {
    const color = STATUS_COLORS[status] || '#94a3b8';
    const label = STATUS_LABELS[status] || status;
    return (
        <span
            className="snowseo-articles__badge"
            style={{ background: `${color}18`, color, border: `1px solid ${color}40` }}
        >
            {label}
        </span>
    );
}

export default function Articles() {
    const [articles, setArticles] = useState([]);
    const [pagination, setPagination] = useState({ page: 1, perPage: 20, total: 0, totalPages: 0 });
    const [loading, setLoading] = useState(true);
    const [statusFilter, setStatusFilter] = useState('all');
    const [publishing, setPublishing] = useState({});
    const [error, setError] = useState('');

    const fetchArticles = async (page = 1, status = statusFilter) => {
        setLoading(true);
        setError('');
        try {
            const res = await getArticles(page, 20, status);
            setArticles(res.articles || []);
            setPagination(res.pagination || { page: 1, perPage: 20, total: 0, totalPages: 0 });
        } catch (err) {
            setError(err.message || 'Failed to load articles');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { fetchArticles(1); }, [statusFilter]);

    const handlePublish = async (articleSlug, status = 'publish') => {
        setPublishing((prev) => ({ ...prev, [articleSlug]: true }));
        try {
            await publishArticle(articleSlug, status);
            fetchArticles(pagination.page);
        } catch (err) {
            alert(err.message || 'Failed to publish');
        } finally {
            setPublishing((prev) => ({ ...prev, [articleSlug]: false }));
        }
    };

    const filters = ['all', 'generated', 'draft', 'published', 'scheduled'];

    return (
        <div className="snowseo-articles">
            <div className="snowseo-breadcrumb">
                <a href="#/dashboard" className="snowseo-breadcrumb__link">SnowSEO</a>
                <span className="snowseo-breadcrumb__sep">/</span>
                <span className="snowseo-breadcrumb__current">Articles</span>
            </div>

            <h1 className="snowseo-dashboard__title">Articles</h1>

            <div className="snowseo-tabs">
                <a href="#/dashboard" className="snowseo-tabs__item">Dashboard</a>
                <a href="#/articles" className="snowseo-tabs__item snowseo-tabs__item--active">Articles</a>
                <a href="#/settings" className="snowseo-tabs__item">Settings</a>
            </div>

            {/* Status Filters */}
            <div className="snowseo-articles__filters">
                {filters.map((f) => (
                    <button
                        key={f}
                        type="button"
                        className={`snowseo-articles__filter-btn ${statusFilter === f ? 'snowseo-articles__filter-btn--active' : ''}`}
                        onClick={() => setStatusFilter(f)}
                    >
                        {f === 'all' ? 'All' : STATUS_LABELS[f] || f}
                    </button>
                ))}
            </div>

            {error && <div className="snowseo-articles__error">{error}</div>}

            {loading ? (
                <div className="snowseo-articles__loading">Loading articles...</div>
            ) : articles.length === 0 ? (
                <div className="snowseo-articles__empty">
                    <p>No articles found. Generate articles in the SnowSEO dashboard first.</p>
                </div>
            ) : (
                <>
                    <div className="snowseo-articles__table-wrap">
                        <table className="snowseo-articles__table">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>SEO</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {articles.map((art) => {
                                    const isPublished = art.status === 'published' && art.provider && art.provider.includes('wordpress');
                                    const canPublish = art.status === 'generated' || art.status === 'draft';
                                    return (
                                        <tr key={art.id}>
                                            <td className="snowseo-articles__title-cell">
                                                <span className="snowseo-articles__title-text">{art.title}</span>
                                                {art.keywords && art.keywords.length > 0 && (
                                                    <span className="snowseo-articles__keywords">
                                                        {art.keywords.slice(0, 3).join(', ')}
                                                    </span>
                                                )}
                                            </td>
                                            <td><StatusBadge status={art.status} /></td>
                                            <td className="snowseo-articles__type">{art.type}</td>
                                            <td className="snowseo-articles__seo">
                                                {art.seoScore != null ? `${art.seoScore}%` : '—'}
                                            </td>
                                            <td className="snowseo-articles__date">
                                                {art.createdAt ? new Date(art.createdAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—'}
                                            </td>
                                            <td className="snowseo-articles__actions">
                                                {isPublished && art.url ? (
                                                    <a
                                                        href={art.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="snowseo-articles__btn snowseo-articles__btn--view"
                                                    >
                                                        View
                                                    </a>
                                                ) : canPublish ? (
                                                    <div className="snowseo-articles__btn-group">
                                                        <button
                                                            type="button"
                                                            className="snowseo-articles__btn snowseo-articles__btn--publish"
                                                            onClick={() => handlePublish(art.slug, 'publish')}
                                                            disabled={publishing[art.slug]}
                                                        >
                                                            {publishing[art.slug] ? 'Publishing...' : 'Publish'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="snowseo-articles__btn snowseo-articles__btn--draft"
                                                            onClick={() => handlePublish(art.slug, 'draft')}
                                                            disabled={publishing[art.slug]}
                                                        >
                                                            Draft
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <span className="snowseo-articles__no-action">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pagination.totalPages > 1 && (
                        <div className="snowseo-articles__pagination">
                            <button
                                type="button"
                                disabled={pagination.page <= 1}
                                onClick={() => fetchArticles(pagination.page - 1)}
                            >
                                Previous
                            </button>
                            <span>Page {pagination.page} of {pagination.totalPages} ({pagination.total} articles)</span>
                            <button
                                type="button"
                                disabled={pagination.page >= pagination.totalPages}
                                onClick={() => fetchArticles(pagination.page + 1)}
                            >
                                Next
                            </button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
