// script.js
const loginBtn = document.getElementById('login-btn');
const authUser = document.getElementById('auth-user');
let authState = { authenticated: false, user: null };

const refreshAuthState = async () => {
    if (!loginBtn) {
        return;
    }
    try {
        const resp = await fetch('/web/backend/api/auth_status.php', { credentials: 'include' });
        const data = await resp.json();
        authState = {
            authenticated: !!data.authenticated,
            user: data.user || null,
        };
    } catch (_) {
        authState = { authenticated: false, user: null };
    }

    if (authState.authenticated) {
        const name = (authState.user?.name || authState.user?.email || '已登录').trim();
        if (authUser) {
            authUser.textContent = `欢迎，${name}`;
        }
        loginBtn.textContent = '退出登录';
    } else {
        if (authUser) {
            authUser.textContent = '';
        }
        loginBtn.textContent = 'Google 登录';
    }
};

if (loginBtn) {
    loginBtn.addEventListener('click', async () => {
        if (!authState.authenticated) {
            window.location.href = '/web/backend/api/google_login.php';
            return;
        }
        try {
            await fetch('/web/backend/api/logout.php', {
                method: 'POST',
                credentials: 'include',
            });
        } catch (_) {
            // no-op
        }
        await refreshAuthState();
    });
}

refreshAuthState();

const escapeHtmlText = (str) => String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const normalizeHttpUrl = (value) => {
    try {
        const trimmed = String(value || '').trim().replace(/[)）\]】,，。;；\s]+$/g, '');
        const u = new URL(trimmed);
        return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : '';
    } catch {
        return '';
    }
};

const formatTime = (value) => {
    if (!value) {
        return '';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleString('zh-CN', { hour12: false });
};

const fallbackCover = 'https://placehold.co/800x450?text=Pet+Knowledge';
let feedSearchDebounce = null;

const buildCoverFromUrl = (url) => {
    const raw = normalizeHttpUrl(url);
    if (!raw) {
        return fallbackCover;
    }
    return `https://image.thum.io/get/width/960/noanimate/${raw}`;
};

const renderKnowledgeFeed = (items) => {
    const list = document.getElementById('feed-list');
    const status = document.getElementById('feed-status');
    if (!list || !status) {
        return;
    }

    if (!Array.isArray(items) || items.length === 0) {
        list.innerHTML = '';
        status.textContent = '暂无可展示的知识资讯。';
        return;
    }

    const refreshedAt = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    status.textContent = `已刷新 ${refreshedAt}，共加载 ${items.length} 条资讯`;
    list.innerHTML = items.map((item) => {
        const title = escapeHtmlText(item.title || '未命名知识条目');
        const excerpt = escapeHtmlText(item.excerpt || '暂无摘要');
        const safeSourceUrl = normalizeHttpUrl(item.url || '');
        const published = escapeHtmlText(formatTime(item.publishedAt) || '时间未知');
        const safeImage = normalizeHttpUrl(item.image || '');
        const coverUrl = escapeHtmlText(safeImage || buildCoverFromUrl(safeSourceUrl));
        const sourceLink = safeSourceUrl
            ? `<a class="feed-card-link" href="${escapeHtmlText(safeSourceUrl)}" target="_blank" rel="noopener noreferrer">查看原文</a>`
            : '<span class="feed-card-link">无原文链接</span>';
        
        const articleId = item.id || 0;
        const currentLang = item.lang || 'zh';
        const detailPageUrl = articleId > 0 ? `article.html?id=${articleId}&lang=${encodeURIComponent(currentLang)}` : '#';

        return `
            <article class="feed-card" data-article-id="${articleId}">
                <img src="${coverUrl}" data-source-url="${escapeHtmlText(safeSourceUrl)}" alt="宠物知识配图" loading="lazy">
                <div class="feed-card-body">
                    <h3 class="feed-card-title">${title}</h3>
                    <p class="feed-card-text">${excerpt}</p>
                    <div class="feed-card-meta">${published}</div>
                    ${sourceLink}
                </div>
            </article>
        `;
    }).join('');

    // Two-stage fallback: saved image -> source screenshot -> static placeholder.
    list.querySelectorAll('img[data-source-url]').forEach((img) => {
        img.addEventListener('error', () => {
            const sourceUrl = normalizeHttpUrl(img.dataset.sourceUrl || '');
            if (img.dataset.retryFromSource !== '1' && sourceUrl) {
                img.dataset.retryFromSource = '1';
                img.src = buildCoverFromUrl(sourceUrl);
                return;
            }
            img.src = fallbackCover;
        });
    });
    
    // Add click handler for cards
    list.querySelectorAll('.feed-card').forEach((card) => {
        card.style.cursor = 'pointer';
        card.addEventListener('click', (e) => {
            // Don't navigate if clicking on a link
            if (e.target.tagName === 'A') {
                return;
            }
            const articleId = card.dataset.articleId;
            const langSelect = document.getElementById('feed-lang');
            const lang = (langSelect ? langSelect.value : 'zh') || 'zh';
            if (articleId) {
                window.location.href = `article.html?id=${articleId}&lang=${encodeURIComponent(lang)}`;
            }
        });
    });
};

const loadKnowledgeFeed = async () => {
    const langSelect = document.getElementById('feed-lang');
    const status = document.getElementById('feed-status');
    const refreshBtn = document.getElementById('refresh-feed-btn');
    const searchInput = document.getElementById('feed-search-input');
    const searchBtn = document.getElementById('feed-search-btn');
    if (!langSelect || !status) {
        return;
    }

    status.textContent = '正在加载资讯...';
    if (refreshBtn) {
        refreshBtn.disabled = true;
    }
    if (searchBtn) {
        searchBtn.disabled = true;
    }
    try {
        const lang = langSelect.value || 'zh';
        const q = (searchInput?.value || '').trim();
        status.textContent = q
            ? `正在搜索 ${lang.toUpperCase()} 资讯：${q}`
            : `正在刷新 ${lang.toUpperCase()} 资讯...`;
        const response = await fetch(`/web/backend/api/articles.php?lang=${encodeURIComponent(lang)}&limit=12&q=${encodeURIComponent(q)}&t=${Date.now()}`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const payload = await response.json();
        renderKnowledgeFeed(payload.items || []);
        if (q && status.textContent.includes('共加载')) {
            status.textContent += `（关键词：${q}）`;
        }
    } catch (error) {
        const list = document.getElementById('feed-list');
        if (list) {
            list.innerHTML = '';
        }
        status.textContent = '资讯加载失败，请稍后重试。';
    } finally {
        if (refreshBtn) {
            refreshBtn.disabled = false;
        }
        if (searchBtn) {
            searchBtn.disabled = false;
        }
    }
};

const feedLang = document.getElementById('feed-lang');
if (feedLang) {
    feedLang.addEventListener('change', loadKnowledgeFeed);
}

const refreshFeedBtn = document.getElementById('refresh-feed-btn');
if (refreshFeedBtn) {
    refreshFeedBtn.addEventListener('click', loadKnowledgeFeed);
}

const feedSearchInput = document.getElementById('feed-search-input');
if (feedSearchInput) {
    feedSearchInput.addEventListener('input', () => {
        if (feedSearchDebounce) {
            clearTimeout(feedSearchDebounce);
        }
        feedSearchDebounce = setTimeout(() => {
            loadKnowledgeFeed();
        }, 450);
    });
    feedSearchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (feedSearchDebounce) {
                clearTimeout(feedSearchDebounce);
            }
            loadKnowledgeFeed();
        }
    });
}

const feedSearchBtn = document.getElementById('feed-search-btn');
if (feedSearchBtn) {
    feedSearchBtn.addEventListener('click', loadKnowledgeFeed);
}

loadKnowledgeFeed();

document.getElementById('ask-btn').addEventListener('click', async () => {
    const question = document.getElementById('question').value;
    const answerDiv = document.getElementById('answer');
    answerDiv.innerHTML = '';
    let rawOutput = '';
    let hasStructuredView = false;

    const escapeHtml = (str) => escapeHtmlText(str);

    const safeHttpUrl = (url) => {
        try {
            const cleanedUrl = String(url || '')
                .trim()
                .replace(/[)）\]】,，。;；\s]+$/g, '')
                // Tolerate malformed URLs like "c1002 - 32008014.html" from noisy model output.
                .replace(/\s+/g, '');
            const u = new URL(cleanedUrl);
            return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : '';
        } catch {
            return '';
        }
    };

    const renderStructuredAnswer = (raw) => {
        let text = String(raw || '')
            .replace(/\r/g, '')
            .replace(/\bdata:\s*/gi, '')
            .trim();

        const normalizeSnippet = (value) => String(value || '')
            .replace(/^[-•]\s*/, '')
            // Remove dangling markers like "（来源：" left after URL extraction.
            .replace(/[（(]\s*(?:来源|出处)?\s*[:：]?\s*$/u, '')
            .replace(/[（(]+$/g, '')
            .replace(/\s+/g, ' ')
            .trim();

        if (!text) {
            answerDiv.textContent = '';
            return false;
        }

        const answerKeyIdx = text.indexOf('回答');
        const citationKeyIdx = text.indexOf('出处');

        let answer = '';
        let citationBlob = '';

        if (citationKeyIdx >= 0) {
            const answerStart = answerKeyIdx >= 0 ? answerKeyIdx + 2 : 0;
            answer = text.slice(answerStart, citationKeyIdx);
            citationBlob = text.slice(citationKeyIdx + 2);
        } else {
            const answerStart = answerKeyIdx >= 0 ? answerKeyIdx + 2 : 0;
            answer = text.slice(answerStart);
        }

        answer = answer
            .replace(/^[：:\s]+/u, '')
            .replace(/^回答[:：]?\s*/iu, '')
            .replace(/\s*来源编号：.*$/u, '')
            .trim();

        citationBlob = citationBlob
            .replace(/^[：:\s]+/u, '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();

        const items = [];
        if (citationBlob) {
            let match;
            const regexInline = /([^\n（]+?)\s*（\s*(?:来源|出处)：\s*(https?:\/\/[^）\n]+)\s*）/g;
            while ((match = regexInline.exec(citationBlob)) !== null) {
                items.push({
                    snippet: normalizeSnippet(match[1]),
                    url: match[2].trim(),
                    fallback: ''
                });
            }

            const regexBlock = /([^\n]+?)（\s*\n\s*(?:来源|出处)：\s*\n\s*(https?:\/\/[^）\n]+)\s*）/g;
            while ((match = regexBlock.exec(citationBlob)) !== null) {
                items.push({
                    snippet: normalizeSnippet(match[1]),
                    url: match[2].trim(),
                    fallback: ''
                });
            }

            const lines2 = citationBlob
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean);

            let pendingSnippet = '';
            for (const line of lines2) {
                if (/^出处[:：]?$/u.test(line) || /^来源[:：]?$/u.test(line)) {
                    continue;
                }

                const inlineUrlMatch = line.match(/(https?:\/\/\S+)/i);
                if (inlineUrlMatch) {
                    const rawUrl = inlineUrlMatch[1].replace(/[）)]+$/g, '').trim();
                    const leading = line.slice(0, inlineUrlMatch.index).trim();
                    const snippet = normalizeSnippet(leading || pendingSnippet);
                    if (snippet) {
                        items.push({ snippet, url: rawUrl, fallback: '' });
                    }
                    pendingSnippet = '';
                    continue;
                }

                pendingSnippet = normalizeSnippet(line);
            }

            if (items.length === 0) {
                lines2
                    .map((line) => normalizeSnippet(line))
                    .filter(Boolean)
                    .forEach((cleaned) => {
                        const withFallback = cleaned.match(/^(.*?)\s*-\s*(.+)$/);
                        if (withFallback) {
                            items.push({
                                snippet: withFallback[1].trim(),
                                url: '',
                                fallback: withFallback[2].trim()
                            });
                        } else if (!/^出处[:：]?$/u.test(cleaned) && !/^来源[:：]?$/u.test(cleaned)) {
                            items.push({ snippet: cleaned, url: '', fallback: '' });
                        }
                    });
            }
        }

        const uniq = new Set();
        const finalItems = items.filter((item) => {
            const key = `${normalizeSnippet(item.snippet)}||${safeHttpUrl(item.url || '')}||${String(item.fallback || '').trim()}`;
            if (uniq.has(key)) {
                return false;
            }
            uniq.add(key);
            return true;
        });

        const answerText = answer || '未获取到有效回答。';
        let html = `<div class="answer-title">回答</div><div class="answer-text">${escapeHtml(answerText)}</div>`;

        if (finalItems.length > 0) {
            html += '<div class="answer-title">出处</div><ul class="citation-list">';
            for (const item of finalItems) {
                const snippet = escapeHtml(item.snippet || '无可用摘录');
                const url = safeHttpUrl(item.url || '');
                const fallback = escapeHtml(item.fallback || '');

                if (url) {
                    html += `<li><a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${snippet}</a></li>`;
                } else if (fallback) {
                    html += `<li>${snippet} - ${fallback}</li>`;
                } else {
                    html += `<li>${snippet}</li>`;
                }
            }
            html += '</ul>';
        }

        answerDiv.innerHTML = html;
        return true;
    };

    const appendCleanText = (text) => {
        if (!text) {
            return;
        }
        const cleaned = text
            .replace(/\r/g, '')
            // Strip SSE prefix even when proxy rewrites all pieces into one line.
            .replace(/\bdata:\s*/gi, '')
            // Merge accidental spaces between Chinese chars, but keep line breaks.
            .replace(/([\u4e00-\u9fff])[ \t]+([\u4e00-\u9fff])/g, '$1$2');
        rawOutput += cleaned;
        rawOutput = rawOutput.replace(/\bdata:\s*/gi, '');

        // Render progressively so UI is still structured even if stream closing is delayed.
        if (rawOutput.includes('回答') || rawOutput.includes('出处')) {
            hasStructuredView = renderStructuredAnswer(rawOutput) || hasStructuredView;
            return;
        }

        if (!hasStructuredView) {
            answerDiv.textContent = rawOutput;
        }
    };

    const processEvent = (eventText) => {
        if (!eventText) {
            return;
        }
        const lines = eventText.split('\n');
        const dataLines = lines
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.replace(/^data:\s?/, ''));

        if (dataLines.length > 0) {
            appendCleanText(dataLines.join('\n'));
        } else {
            // Fallback for proxies that may alter SSE framing.
            appendCleanText(eventText);
        }
    };

    const response = await fetch('/ask.php?' + new Date().getTime(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question })
    });

    if (!response.ok || !response.body) {
        if (response.status === 401) {
            answerDiv.textContent = '请先登录后再使用 AI 问答功能。';
            loginBtn?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            answerDiv.textContent = '问答服务暂时不可用，请稍后再试。';
        }
        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        buffer += decoder.decode(value || new Uint8Array(), { stream: !done });
        buffer = buffer.replace(/\r\n/g, '\n');

        const events = buffer.split('\n\n');
        buffer = events.pop() || '';

        for (const event of events) {
            processEvent(event.trim());
        }

        if (done) {
            processEvent(buffer.trim());
            rawOutput = rawOutput.replace(/\bdata:\s*/gi, '');
            renderStructuredAnswer(rawOutput);
            break;
        }
    }
});