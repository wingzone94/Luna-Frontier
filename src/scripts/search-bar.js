import { storage } from '../storage';

const NODE_DEBUG = false;

export function initSearchBar() {
    if (NODE_DEBUG) console.log('Search Bar: Initializing Full Version...');
    const searchToggle = document.getElementById('search-toggle');
    const searchForm = document.getElementById('m3-main-search-form');
    const searchBar = searchForm || document.querySelector('.m3-search-bar');
    const searchInput = document.getElementById('m3-search-input');
    const modal = document.getElementById('m3-advanced-search-modal');
    const modalClose = document.getElementById('m3-advanced-search-close');
    const modalReset = document.getElementById('m3-advanced-search-reset');
    const modalApply = document.getElementById('m3-advanced-search-apply');
    const searchResultsRegion = document.getElementById('m3-search-rest-results');
    const searchResultsStatus = document.getElementById('m3-search-rest-results-status');
    const searchResultsList = document.getElementById('m3-search-rest-results-list');
    const searchResultsAll = document.getElementById('m3-search-rest-results-all');
    const header = document.querySelector('.m3-header');

    if (!searchToggle || !searchBar || !searchInput) return;

    const openSearch = () => {
        searchBar.classList.add('is-active');
        header?.classList.add('search-is-active');
        // iOS Safari: focus はタップと同じイベントターンで呼ぶ必要がある（遅延するとキーボードが出ない）
        searchInput.focus({ preventScroll: true });
        if (typeof window.nodeUpdateSearchClear === 'function') {
            window.nodeUpdateSearchClear();
        }
    };

    const submitSearch = () => {
        if (!searchInput.value.trim()) return;
        closeSuggestions();
        if (typeof searchForm?.requestSubmit === 'function') {
            searchForm.requestSubmit();
            return;
        }
        searchForm?.submit();
    };

    const closeSearch = () => {
        closeSuggestions();
        searchBar.classList.remove('is-active');
        header?.classList.remove('search-is-active');
        searchInput.blur();
    };

    // --- Search keyword suggestions (Luna Library / categories) ---
    const suggestionsBox = document.getElementById('m3-search-suggestions');
    const SUGGEST_MIN_LENGTH = 2;
    const SUGGEST_DEBOUNCE_MS = 250;
    let suggestTimer;
    let suggestController;
    let activeSuggestion = -1;

    const suggestionOptions = () => Array.from(suggestionsBox?.querySelectorAll('[role="option"]') || []);

    function updateSuggestionLayoutSpace(isOpen) {
        document.body.classList.toggle('m3-search-suggestions-is-active', isOpen);

        if (!isOpen || !suggestionsBox) {
            document.documentElement.style.removeProperty('--m3-search-suggestions-space');
            return;
        }

        const height = Math.ceil(suggestionsBox.getBoundingClientRect().height);
        document.documentElement.style.setProperty('--m3-search-suggestions-space', `${height + 12}px`);
    }

    function closeSuggestions() {
        clearTimeout(suggestTimer);
        suggestController?.abort();
        suggestController = undefined;
        suggestionsBox?.replaceChildren();
        suggestionsBox?.classList.remove('is-active');
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.removeAttribute('aria-activedescendant');
        activeSuggestion = -1;
        updateSuggestionLayoutSpace(false);
    }

    const setActiveSuggestion = (index) => {
        const options = suggestionOptions();
        if (!options.length) return;

        activeSuggestion = (index + options.length) % options.length;
        options.forEach((option, optionIndex) => {
            const isActive = optionIndex === activeSuggestion;
            option.classList.toggle('is-highlighted', isActive);
            option.setAttribute('aria-selected', String(isActive));
        });
        searchInput.setAttribute('aria-activedescendant', options[activeSuggestion].id);
        options[activeSuggestion].scrollIntoView({ block: 'nearest' });
    };

    const applySuggestion = (keyword) => {
        searchInput.value = keyword;
        if (typeof window.nodeUpdateSearchClear === 'function') {
            window.nodeUpdateSearchClear();
        }
        submitSearch();
    };

    const renderSuggestions = (items) => {
        if (!suggestionsBox) return;

        suggestionsBox.replaceChildren();
        activeSuggestion = -1;
        searchInput.removeAttribute('aria-activedescendant');

        if (!items.length) {
            suggestionsBox.classList.remove('is-active');
            searchInput.setAttribute('aria-expanded', 'false');
            updateSuggestionLayoutSpace(false);
            return;
        }

        items.forEach((item, index) => {
            const option = document.createElement('button');
            const icon = document.createElement('span');
            const label = document.createElement('span');
            const source = document.createElement('span');

            option.type = 'button';
            option.id = `m3-search-suggestion-${index}`;
            option.className = 'm3-suggestion-item m3-search-suggestion';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');

            icon.setAttribute('aria-hidden', 'true');
            const suggestionSources = String(item.source || '').split('+').filter(Boolean);
            const isCategorySuggestion = document.body.classList.contains('lf-theme')
                && suggestionSources.includes('category');
            if (isCategorySuggestion) {
                icon.className = 'm3-search-suggestion__mark';
                icon.textContent = '📁';
            } else {
                icon.className = 'material-symbols-outlined';
                icon.textContent = item.icon || 'search';
            }

            label.className = 'm3-search-suggestion__label';
            label.textContent = item.keyword;

            source.className = 'm3-search-suggestion__source';
            source.textContent = item.sourceLabel || '';

            option.append(icon, label, source);
            option.addEventListener('mousedown', (event) => event.preventDefault());
            option.addEventListener('click', (event) => {
                event.stopPropagation();
                applySuggestion(item.keyword);
            });
            suggestionsBox.append(option);
        });

        suggestionsBox.classList.add('is-active');
        searchInput.setAttribute('aria-expanded', 'true');
        updateSuggestionLayoutSpace(true);
    };

    const requestSuggestions = () => {
        const query = searchInput.value.trim();

        if (query.length < SUGGEST_MIN_LENGTH || !m3_ajax?.search_suggest_url) {
            closeSuggestions();
            return;
        }

        suggestController?.abort();
        suggestController = new AbortController();
        const url = new URL(m3_ajax.search_suggest_url, window.location.origin);
        url.searchParams.set('q', query);

        fetch(url, { signal: suggestController.signal, headers: { Accept: 'application/json' } })
            .then(response => {
                if (!response.ok) throw new Error(`Search suggest failed: ${response.status}`);
                return response.json();
            })
            .then(items => {
                if (query === searchInput.value.trim()) renderSuggestions(Array.isArray(items) ? items : []);
            })
            .catch(error => {
                if (error.name !== 'AbortError') closeSuggestions();
            });
    };

    searchInput.addEventListener('input', () => {
        clearTimeout(suggestTimer);
        suggestTimer = setTimeout(requestSuggestions, SUGGEST_DEBOUNCE_MS);
    });
    searchInput.addEventListener('blur', () => setTimeout(closeSuggestions, 150));
    document.getElementById('m3-search-clear')?.addEventListener('click', closeSuggestions);
    window.addEventListener('resize', () => {
        if (suggestionsBox?.classList.contains('is-active')) updateSuggestionLayoutSpace(true);
    }, { passive: true });

    // --- Search Bar Toggle ---
    searchToggle.addEventListener('click', () => {
        if (!searchBar.classList.contains('is-active')) {
            openSearch();
        } else if (!searchInput.value.trim()) {
            closeSearch();
        } else {
            submitSearch();
        }
    });

    // クリアボタンは header.php インラインスクリプトが正本（二重バインド防止）

    // --- Close (← / Escape) ---
    document.getElementById('m3-search-mobile-close')?.addEventListener('click', closeSearch);

    searchInput.addEventListener('keydown', (e) => {
        const options = suggestionOptions();

        if (e.key === 'ArrowDown' && options.length) {
            e.preventDefault();
            setActiveSuggestion(activeSuggestion + 1);
            return;
        }
        if (e.key === 'ArrowUp' && options.length) {
            e.preventDefault();
            setActiveSuggestion(activeSuggestion - 1);
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            if (options.length) {
                closeSuggestions();
                return;
            }
            closeSearch();
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            if (activeSuggestion >= 0) {
                options[activeSuggestion]?.click();
                return;
            }
            submitSearch();
        }
    });

    searchForm?.addEventListener('submit', (e) => {
        if (!searchInput.value.trim()) {
            e.preventDefault();
            searchInput.focus({ preventScroll: true });
        }
    });

    if (!modal) return;

    const modalTriggers = document.querySelectorAll('.m3-search-advanced-trigger, #m3-advanced-search-trigger');
    let lastModalTrigger = null;
    let previousBodyOverflow = '';

    const setModalExpanded = (expanded) => {
        modalTriggers.forEach(trigger => trigger.setAttribute('aria-expanded', String(expanded)));
    };

    const openModal = (trigger) => {
        if (NODE_DEBUG) console.log('Search Bar: Opening Modal...');
        if (modal.open) return;

        lastModalTrigger = trigger || document.activeElement;
        previousBodyOverflow = document.body.style.overflow;
        modal.showModal();
        setModalExpanded(true);
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => {
            modal.classList.add('is-active');
            modalClose?.focus({ preventScroll: true });
        });

        // --- Restore saved search settings ---
        const saved = storage.get('m3-saved-search');
        const saveToggle = document.getElementById('m3-save-search-settings');
        if (saved) {
            if (NODE_DEBUG) console.log('Search Bar: Restoring saved settings...');
            modal.querySelectorAll('input, select').forEach(input => {
                if (saved[input.name] !== undefined) {
                    if (input.type === 'checkbox') {
                        input.checked = Array.isArray(saved[input.name]) ? saved[input.name].includes(input.value) : saved[input.name] === input.value;
                    } else if (input.type === 'radio') {
                        input.checked = saved[input.name] === input.value;
                    } else {
                        input.value = saved[input.name];
                    }
                }
            });
            if (saveToggle) saveToggle.checked = true;
        }

        // switchPage はタブ下のインジケータ（アクティブタブの背景）も配置する。
        // 以前はモバイルを除外していたため、開いた直後はインジケータが幅 0 のままで、
        // アクティブタブの白文字（.is-active の color:#FFF）が背景に溶けて消えていた。
        // タブは等分幅になり位置計算が合うので、幅に関係なく初期化する。
        // ただし showModal 直後は offsetWidth が確定しておらず（Safari で実測 105px →
        // 確定後 111px）インジケータがタブより狭くなるため、他の初期化と同じタイミングまで待つ。
        setTimeout(() => {
            switchPage(1);
            initRangeSlider();
            updateHitCount();
            updateTabStatus();
        }, 150);

    };

    const closeModal = () => {
        if (!modal.open) return;
        modal.classList.remove('is-active');
        modal.close();
    };

    modal.addEventListener('cancel', () => modal.classList.remove('is-active'));
    modal.addEventListener('close', () => {
        modal.classList.remove('is-active');
        setModalExpanded(false);
        document.body.style.overflow = previousBodyOverflow;
        lastModalTrigger?.focus({ preventScroll: true });
        lastModalTrigger = null;
    });

    // --- Global Event Delegation for Advanced Search Trigger ---
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('.m3-search-advanced-trigger, #m3-advanced-search-trigger');
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            openModal(trigger);
        }
    });

    modalClose?.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    let platformOptionsPromise;

    function restoreSavedPlatformSettings(container) {
        const saved = storage.get('m3-saved-search');
        if (!saved) return;

        container.querySelectorAll('input[name="m3_platform[]"]').forEach(input => {
            const savedValues = saved[input.name];
            input.checked = Array.isArray(savedValues) && savedValues.includes(input.value);
        });
    }

    function ensurePlatformOptions() {
        const container = document.getElementById('m3-platform-options');
        if (!container || container.dataset.state === 'ready') return Promise.resolve();
        if (platformOptionsPromise) return platformOptionsPromise;

        container.dataset.state = 'loading';
        container.setAttribute('aria-busy', 'true');
        const status = document.createElement('p');
        status.className = 'm3-platform-load-state';
        status.setAttribute('role', 'status');
        status.textContent = 'プラットフォームを読み込んでいます…';
        container.replaceChildren(status);

        const platformUrl = m3_ajax?.platform_search_url;
        if (!platformUrl) {
            platformOptionsPromise = Promise.reject(new Error('Platform API is unavailable'));
        } else {
            platformOptionsPromise = Promise.all([
                import('./search-platforms.js'),
                fetch(platformUrl, { headers: { Accept: 'application/json' } }).then(response => {
                    if (!response.ok) throw new Error(`Platform search failed: ${response.status}`);
                    return response.json();
                })
            ]);
        }

        platformOptionsPromise
            .then(([{ initSearchPlatforms }, payload]) => {
                initSearchPlatforms(container, payload?.groups);
                restoreSavedPlatformSettings(container);
                bindSearchControlListeners(container);
                container.dataset.state = 'ready';
                container.removeAttribute('aria-busy');
                updateHitCount();
                updateTabStatus();
            })
            .catch(() => {
                platformOptionsPromise = null;
                container.dataset.state = 'error';
                container.removeAttribute('aria-busy');
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'm3-button m3-button--text m3-platform-load-state';
                retry.textContent = '読み込みに失敗しました。再試行';
                retry.addEventListener('click', ensurePlatformOptions);
                container.replaceChildren(retry);
            });

        return platformOptionsPromise;
    }

    // --- Search Tab Switching ---
    const switchPage = (pageNum) => {
        if (pageNum === 3) ensurePlatformOptions();

        const pagesContainer = modal.querySelector('.m3-modal__pages-container');
        const allPages = Array.from(modal.querySelectorAll('.m3-modal__page'));
        const allTabs = Array.from(modal.querySelectorAll('.m3-modal__tab'));

        if (!pagesContainer || allPages.length === 0) return;

        const movePercent = (100 / allPages.length) * (pageNum - 1);
        pagesContainer.style.transform = `translateX(-${movePercent}%)`;

        allPages.forEach(p => p.classList.toggle('is-active', p.dataset.page == pageNum));
        allTabs.forEach(t => t.classList.toggle('is-active', t.dataset.page == pageNum));

        // Update tab indicator
        const tabsContainer = document.getElementById('m3-search-tabs');
        if (tabsContainer) {
            const indicator = tabsContainer.querySelector('.m3-modal__tab-indicator');
            const activeTab = allTabs.find(t => t.dataset.page == pageNum);
            if (indicator && activeTab) {
                indicator.style.width = `${activeTab.offsetWidth}px`;
                indicator.style.left = `${activeTab.offsetLeft}px`;
            }
        }
    };

    modal.querySelectorAll('.m3-modal__tab').forEach(el => {
        el.addEventListener('click', () => switchPage(parseInt(el.dataset.page)));
    });

    // タブ幅はモーダルを開くときの再レイアウトや画面回転で変わる。開いた直後に一度
    // 測るだけだと確定前の値（Safari 実測で 105px、確定後 111px）が残りインジケータが
    // タブより狭くなるので、幅の変化そのものに追従させる。
    if (typeof ResizeObserver !== 'undefined') {
        const tabsContainer = document.getElementById('m3-search-tabs');
        if (tabsContainer) {
            new ResizeObserver(() => {
                const indicator = tabsContainer.querySelector('.m3-modal__tab-indicator');
                const activeTab = tabsContainer.querySelector('.m3-modal__tab.is-active');
                if (!indicator || !activeTab || !activeTab.offsetWidth) return;
                indicator.style.width = `${activeTab.offsetWidth}px`;
                indicator.style.left = `${activeTab.offsetLeft}px`;
            }).observe(tabsContainer);
        }
    }

    // --- On-demand Tag Suggestions ---
    const tagInput = document.getElementById('m3-tag-input');
    const tagSuggestions = document.getElementById('m3-tag-suggestions');
    let tagSuggestionTimer;
    let tagRequestController;
    let activeTagSuggestion = -1;

    const closeTagSuggestions = () => {
        tagSuggestions?.replaceChildren();
        tagSuggestions?.classList.remove('is-active');
        tagInput?.setAttribute('aria-expanded', 'false');
        tagInput?.removeAttribute('aria-activedescendant');
        activeTagSuggestion = -1;
    };

    const setActiveTagSuggestion = (index) => {
        const options = Array.from(tagSuggestions?.querySelectorAll('[role="option"]') || []);
        if (!options.length) return;

        activeTagSuggestion = (index + options.length) % options.length;
        options.forEach((option, optionIndex) => {
            const isActive = optionIndex === activeTagSuggestion;
            option.classList.toggle('is-highlighted', isActive);
            option.setAttribute('aria-selected', String(isActive));
        });
        tagInput?.setAttribute('aria-activedescendant', options[activeTagSuggestion].id);
        options[activeTagSuggestion].scrollIntoView({ block: 'nearest' });
    };

    const selectTagSuggestion = (name) => {
        if (!tagInput) return;
        tagInput.value = name;
        closeTagSuggestions();
        updateHitCount();
        updateTabStatus();
        tagInput.focus({ preventScroll: true });
    };

    const renderTagSuggestions = (terms) => {
        if (!tagSuggestions || !tagInput) return;
        tagSuggestions.replaceChildren();
        activeTagSuggestion = -1;

        terms.forEach((term, index) => {
            const option = document.createElement('button');
            const icon = document.createElement('span');
            const label = document.createElement('span');

            option.type = 'button';
            option.id = `m3-tag-suggestion-${index}`;
            option.className = 'm3-suggestion-item';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            icon.className = 'material-symbols-outlined';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = 'sell';
            label.textContent = term.name;
            option.append(icon, label);
            option.addEventListener('click', (event) => {
                event.stopPropagation();
                selectTagSuggestion(term.name);
            });
            tagSuggestions.append(option);
        });

        const hasSuggestions = terms.length > 0;
        tagSuggestions.classList.toggle('is-active', hasSuggestions);
        tagInput.setAttribute('aria-expanded', String(hasSuggestions));
    };

    const requestTagSuggestions = () => {
        const query = tagInput?.value.trim() || '';
        if (!query || !m3_ajax?.tag_search_url) {
            closeTagSuggestions();
            return;
        }

        tagRequestController?.abort();
        tagRequestController = new AbortController();
        const url = new URL(m3_ajax.tag_search_url, window.location.origin);
        url.searchParams.set('q', query);

        fetch(url, { signal: tagRequestController.signal })
            .then(response => {
                if (!response.ok) throw new Error(`Tag search failed: ${response.status}`);
                return response.json();
            })
            .then(terms => {
                if (query === tagInput?.value.trim()) renderTagSuggestions(Array.isArray(terms) ? terms : []);
            })
            .catch(error => {
                if (error.name !== 'AbortError') closeTagSuggestions();
            });
    };

    tagInput?.addEventListener('input', () => {
        clearTimeout(tagSuggestionTimer);
        tagSuggestionTimer = setTimeout(requestTagSuggestions, 250);
    });
    tagInput?.addEventListener('keydown', (event) => {
        const optionCount = tagSuggestions?.querySelectorAll('[role="option"]').length || 0;
        if (event.key === 'ArrowDown' && optionCount) {
            event.preventDefault();
            setActiveTagSuggestion(activeTagSuggestion + 1);
        } else if (event.key === 'ArrowUp' && optionCount) {
            event.preventDefault();
            setActiveTagSuggestion(activeTagSuggestion - 1);
        } else if (event.key === 'Enter' && activeTagSuggestion >= 0) {
            event.preventDefault();
            const activeOption = tagSuggestions.querySelectorAll('[role="option"]')[activeTagSuggestion];
            activeOption?.click();
        } else if (event.key === 'Escape') {
            closeTagSuggestions();
        }
    });
    tagInput?.addEventListener('blur', () => setTimeout(closeTagSuggestions, 150));

    const updateTabStatus = () => {
        const tabs = modal.querySelectorAll('.m3-modal__tab');
        tabs.forEach(tab => {
            const pageNum = tab.dataset.page;
            const page = modal.querySelector(`.m3-modal__page[data-page="${pageNum}"]`);
            const hasValue = Array.from(page.querySelectorAll('input, select')).some(input => {
                if (input.type === 'checkbox' || input.type === 'radio') return input.checked && input.value !== 'all';
                if (input.type === 'date' || input.type === 'number') return !!input.value;
                if (input.tagName === 'SELECT') return !!input.value;
                return false;
            });
            tab.classList.toggle('has-value', hasValue);
        });
    };

    // --- Range Slider ---
    function initRangeSlider() {
        const slider = document.getElementById('m3-word-count-slider');
        const minHandle = document.getElementById('m3-slider-handle-min');
        const maxHandle = document.getElementById('m3-slider-handle-max');
        const range = document.getElementById('m3-slider-range');
        const minInput = document.getElementById('m3-min-chars');
        const maxInput = document.getElementById('m3-max-chars');

        if (!slider || !minHandle || !maxHandle || !range || !minInput || !maxInput) return;

        let minVal = parseInt(minInput.value) || 0;
        let maxVal = parseInt(maxInput.value) || 10000;
        const totalMax = 10000;

        const updateUI = () => {
            const rect = slider.getBoundingClientRect();
            if (rect.width === 0) return;

            const minPercent = (minVal / totalMax) * 100;
            const maxPercent = (maxVal / totalMax) * 100;

            const offset = 14;
            const trackWidth = rect.width - (offset * 2);

            const minPos = offset + (minPercent / 100) * trackWidth;
            const maxPos = offset + (maxPercent / 100) * trackWidth;

            minHandle.style.left = `${minPos}px`;
            maxHandle.style.left = `${maxPos}px`;
            range.style.left = `${minPos}px`;
            range.style.width = `${maxPos - minPos}px`;

            minHandle.querySelector('.m3-range-slider__value').textContent = minVal;
            maxHandle.querySelector('.m3-range-slider__value').textContent = maxVal >= totalMax ? '10000+' : maxVal;

            minInput.value = minVal;
            maxInput.value = maxVal;
        };

        const handleDrag = (e, type) => {
            if (e.cancelable) e.preventDefault();
            const rect = slider.getBoundingClientRect();
            const offset = 14;
            const trackWidth = rect.width - (offset * 2);
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const x = clientX - rect.left - offset;

            const percent = Math.min(100, Math.max(0, (x / trackWidth) * 100));
            let val = Math.round((percent / 100) * totalMax);
            val = Math.round(val / 500) * 500;

            if (type === 'min') {
                minVal = Math.min(val, maxVal - 500);
            } else {
                maxVal = Math.max(val, minVal + 500);
            }
            updateUI();
            updateHitCount();
        };

        const onStart = (e, type) => {
            const onMove = (me) => handleDrag(me, type);
            const onEnd = () => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onEnd);
                document.removeEventListener('touchmove', onMove);
                document.removeEventListener('touchend', onEnd);
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onEnd);
        };

        minHandle.addEventListener('mousedown', (e) => onStart(e, 'min'));
        minHandle.addEventListener('touchstart', (e) => onStart(e, 'min'), { passive: false });
        maxHandle.addEventListener('mousedown', (e) => onStart(e, 'max'));
        maxHandle.addEventListener('touchstart', (e) => onStart(e, 'max'), { passive: false });

        minInput.addEventListener('change', () => {
            minVal = Math.min(parseInt(minInput.value) || 0, maxVal - 500);
            updateUI();
            updateHitCount();
        });
        maxInput.addEventListener('change', () => {
            maxVal = Math.max(parseInt(maxInput.value) || 0, minVal + 500);
            updateUI();
            updateHitCount();
        });

        window.addEventListener('resize', updateUI);
        updateUI();
    }

    // --- Search Hit Counter ---
    let debounceTimer;
    let countRequestController;
    let countRequestSequence = 0;
    function updateHitCount() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            const data = new FormData();
            data.append('s', searchInput.value.trim());
            modal.querySelectorAll('input, select').forEach(input => {
                if (!input.name) return;
                if (input.type === 'checkbox' || input.type === 'radio') {
                    if (input.checked) data.append(input.name, input.value);
                } else {
                    data.append(input.name, input.value);
                }
            });

            const params = new URLSearchParams(data);
            params.append('action', 'node_get_search_count');

            const counter = document.getElementById('m3-search-hit-count');
            const applyBtn = document.getElementById('m3-advanced-search-apply');
            const requestSequence = ++countRequestSequence;

            countRequestController?.abort();
            countRequestController = new AbortController();

            if (counter) counter.style.opacity = '0.5';

            fetch(m3_ajax.ajax_url, {
                method: 'POST',
                body: params,
                signal: countRequestController.signal
            })
                .then(res => res.json())
                .then(res => {
                    if (requestSequence !== countRequestSequence) return;

                    if (res.success && counter) {
                        counter.textContent = res.data.count;
                        counter.style.opacity = '1';

                        if (applyBtn) {
                            applyBtn.disabled = (res.data.count === 0);
                            applyBtn.style.opacity = res.data.count === 0 ? '0.5' : '1';
                        }
                    }
                })
                .catch(error => {
                    if (error.name !== 'AbortError' && requestSequence === countRequestSequence && counter) {
                        counter.textContent = '—';
                        counter.style.opacity = '1';
                        if (applyBtn) {
                            // 件数取得の失敗を「0件」と扱わず、通常の検索送信は許可する。
                            applyBtn.disabled = false;
                            applyBtn.style.opacity = '1';
                        }
                    }
                });
        }, 300);
    }

    function bindSearchControlListeners(container) {
        container.querySelectorAll('input, select').forEach(input => {
            if (input.dataset.nodeSearchBound === 'true') return;
            input.dataset.nodeSearchBound = 'true';
            input.addEventListener('change', () => { updateHitCount(); updateTabStatus(); });
            if (input.type === 'text' || input.type === 'number') {
                input.addEventListener('input', () => { updateHitCount(); updateTabStatus(); });
            }
        });
    }

    bindSearchControlListeners(modal);
    searchInput.addEventListener('input', () => {
        if (modal.classList.contains('is-active')) updateHitCount();
    });

    let resultRequestController;

    function renderSearchResults(payload, destinationUrl) {
        if (!searchResultsRegion || !searchResultsStatus || !searchResultsList) return;

        const results = Array.isArray(payload?.results) ? payload.results : [];
        const total = Number.isFinite(Number(payload?.total)) ? Number(payload.total) : results.length;
        const fragment = document.createDocumentFragment();

        results.forEach(result => {
            const item = document.createElement('li');
            const article = document.createElement('article');
            const title = document.createElement('h4');
            const link = document.createElement('a');
            const meta = document.createElement('time');
            const excerpt = document.createElement('p');

            item.className = 'm3-search-rest-results__item';
            article.className = 'm3-search-rest-result';
            title.className = 'm3-search-rest-result__title';
            link.href = result.url;
            link.textContent = result.title;
            meta.className = 'm3-search-rest-result__date';
            meta.dateTime = result.date;
            meta.textContent = result.date_display;
            excerpt.className = 'm3-search-rest-result__excerpt';
            excerpt.textContent = result.excerpt;

            title.append(link);
            article.append(title, meta, excerpt);
            item.append(article);
            fragment.append(item);
        });

        searchResultsList.replaceChildren(fragment);
        searchResultsStatus.textContent = total > 0
            ? `${total.toLocaleString()}件中、先頭${results.length}件を表示しています。`
            : '条件に一致する記事はありませんでした。';
        searchResultsAll?.setAttribute('href', destinationUrl);
        searchResultsAll?.toggleAttribute('hidden', total === 0);
        searchResultsRegion.hidden = false;
        searchResultsRegion.removeAttribute('aria-busy');
        searchResultsRegion.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function requestSearchResults(params, destinationUrl) {
        if (!m3_ajax?.result_search_url || !searchResultsRegion || !searchResultsStatus) {
            window.location.href = destinationUrl;
            return;
        }

        resultRequestController?.abort();
        resultRequestController = new AbortController();
        const url = new URL(m3_ajax.result_search_url, window.location.origin);
        params.forEach((value, key) => url.searchParams.append(key, value));

        searchResultsRegion.hidden = false;
        searchResultsRegion.setAttribute('aria-busy', 'true');
        searchResultsStatus.textContent = '検索結果を読み込んでいます…';
        searchResultsList?.replaceChildren();
        document.getElementById('m3-search-loading')?.classList.add('is-active');

        fetch(url, {
            headers: { Accept: 'application/json' },
            signal: resultRequestController.signal
        })
            .then(response => {
                if (!response.ok) throw new Error(`Search failed: ${response.status}`);
                return response.json();
            })
            .then(payload => renderSearchResults(payload, destinationUrl))
            .catch(error => {
                if (error.name !== 'AbortError') window.location.href = destinationUrl;
            })
            .finally(() => document.getElementById('m3-search-loading')?.classList.remove('is-active'));
    }

    // --- Apply Search ---
    modalApply?.addEventListener('click', () => {
        const params = new URLSearchParams();
        params.append('s', searchInput.value.trim());

        const searchData = {};
        const saveToggle = document.getElementById('m3-save-search-settings');

        modal.querySelectorAll('input, select').forEach(input => {
            if (!input.name) return;
            if ((input.type === 'checkbox' || input.type === 'radio')) {
                if (input.checked) {
                    params.append(input.name, input.value);
                    if (input.type === 'checkbox') {
                        if (!searchData[input.name]) searchData[input.name] = [];
                        searchData[input.name].push(input.value);
                    } else {
                        searchData[input.name] = input.value;
                    }
                }
            } else if (input.tagName === 'SELECT' && input.value) {
                params.append(input.name, input.value);
                searchData[input.name] = input.value;
            } else if ((input.type === 'text' || input.type === 'date' || input.type === 'number') && input.value) {
                params.append(input.name, input.value);
                searchData[input.name] = input.value;
            }
        });

        // Save to LocalStorage if enabled
        if (saveToggle && saveToggle.checked) {
            storage.set('m3-saved-search', searchData);
        } else {
            storage.remove('m3-saved-search');
        }

        const destinationUrl = `${m3_ajax.home_url}?${params.toString()}`;
        requestSearchResults(params, destinationUrl);
    });

    // --- Reset ---
    modalReset?.addEventListener('click', () => {
        modal.querySelectorAll('input, select').forEach(input => {
            if (input.type === 'checkbox') input.checked = false;
            else if (input.type === 'radio') input.checked = (input.value === 'all' || input.value === 'date' || input.value === 'newest');
            else if (input.tagName === 'SELECT') input.selectedIndex = 0;
            else if (input.id === 'm3-min-chars') input.value = 0;
            else if (input.id === 'm3-max-chars') input.value = 10000;
            else input.value = '';
        });

        // Also clear saved search
        storage.remove('m3-saved-search');
        const saveToggle = document.getElementById('m3-save-search-settings');
        if (saveToggle) saveToggle.checked = false;

        closeTagSuggestions();

        resultRequestController?.abort();
        searchResultsList?.replaceChildren();
        if (searchResultsStatus) searchResultsStatus.textContent = '';
        if (searchResultsRegion) {
            searchResultsRegion.hidden = true;
            searchResultsRegion.removeAttribute('aria-busy');
        }

        initRangeSlider();
        updateHitCount();
        updateTabStatus();
    });
}
