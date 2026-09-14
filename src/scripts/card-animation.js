/**
 * グリッドを調整して、中途半端な最後の行を隠す
 */
const balanceGrid = (container) => {
    // Date archives must retain every result, including a partial final row.
    if (container.closest('.lf-date-archive')) return;
    const cards = Array.from(container.querySelectorAll('.m3-card, .m3-elevated-nav-card, .special-features__item'));
    if (!cards.length) return;

    // コンテナの幅とカードの幅から現在の列数を計算
    const containerWidth = container.offsetWidth;
    const cardWidth = cards[0].offsetWidth;
    const gapWidth = parseFloat(getComputedStyle(container).gap) || 0;
    
    // 実効列数を算出
    const columns = Math.floor((containerWidth + gapWidth) / (cardWidth + gapWidth));
    
    if (columns <= 1) {
        cards.forEach(card => card.style.display = '');
        return;
    }

    // 表示すべき総数（列数の倍数）を算出
    const totalToDisplay = Math.floor(cards.length / columns) * columns;

    // もし総数が列数未満なら（1行目すら満たしていない場合）、すべて表示して端数隠しをキャンセル
    if (totalToDisplay === 0) {
        cards.forEach(card => card.style.display = '');
        return;
    }

    cards.forEach((card, index) => {
        if (index < totalToDisplay) {
            card.style.display = '';
        } else {
            card.style.display = 'none'; // 端数を隠す
        }
    });
};

const initCardLinkFallback = () => {
    document.querySelectorAll('.m3-card[data-card-url], .c-card[data-card-url]').forEach((card) => {
        if (card.dataset.cardLinkReady === 'true') return;

        card.dataset.cardLinkReady = 'true';
        card.addEventListener('click', (event) => {
            if (
                event.defaultPrevented ||
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            const interactive = event.target.closest('a, button, input, select, textarea, summary, [role="button"], [data-no-card-link]');
            if (interactive) return;

            const url = card.dataset.cardUrl;
            if (url) {
                window.location.href = url;
            }
        });
    });
};

export const initCardAnimations = () => {
    initCardLinkFallback();

    if (typeof gsap === 'undefined') return;

    const gridContainers = document.querySelectorAll('.m3-post-grid__container');
    const selectors = [
        '.m3-card',
        '.m3-elevated-nav-card',
        '.special-features__item',
        '.m3-nexus-card',
        '.m3-blogcard',
        '.m3-product-card'
    ];

    const cards = document.querySelectorAll(selectors.join(', '));
    
    if (cards.length > 0) {
        // グリッドのバランス調整を実行 (ページ内のすべてのコンテナに対して)
        if (gridContainers.length > 0) {
            gridContainers.forEach(container => {
                balanceGrid(container);
                window.addEventListener('resize', () => balanceGrid(container), { passive: true });
            });
        }

        // 「動きを減らす」設定では出現アニメーションを行わない。
        // テーマの他の箇所（_article.css ほか10ファイル）は CSS の
        // prefers-reduced-motion で尊重しているが、ここは GSAP 製なので
        // メディアクエリが効かず素通りしていた。
        // 早期 return するので gsap.set による opacity: 0 も掛からず、
        // カードは最初から見えたままになる（レイアウト調整は上で済ませている）。
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        /*
         * 最初から画面内にあるカードはアニメーションさせない。
         *
         * これらは読み込み直後の見え方そのもの（多くの場合 LCP 要素）で、
         * opacity: 0 から始めるとスタッガー（0.05s 刻み）のぶんだけ
         * 「何も無い画面」が続く。実測 375×812 のトップでは、ファーストビューの
         * カードが数百 ms のあいだ真っ白のままだった。
         * 出現の演出は「スクロールして新しく現れる」ものにだけ意味があるので、
         * 初期表示ぶんは最初から見えている状態に置く。
         */
        const belowFold = Array.from(cards).filter((card) => {
            const rect = card.getBoundingClientRect();
            return !(rect.bottom > 0 && rect.top < window.innerHeight);
        });

        if (belowFold.length === 0) return;

        // 初期状態（高速化のため y移動を控えめに）
        gsap.set(belowFold, {
            opacity: 0, 
            y: 40,
            scale: 0.95
        });

        const observerOptions = {
            threshold: 0.05, // より早く反応するように
            rootMargin: "0px 0px 50px 0px"
        };

        const observer = new IntersectionObserver((entries) => {
            // 表示されている（display: none でない）要素のみをアニメーション対象にする
            const visibleEntries = entries.filter(entry => entry.isIntersecting && entry.target.style.display !== 'none');
            
            if (visibleEntries.length > 0) {
                const elementsToAnimate = visibleEntries.map(entry => entry.target);
                
                // アニメーション高速化 (duration: 0.4s)
                gsap.to(elementsToAnimate, {
                    opacity: 1,
                    y: 0,
                    scale: 1,
                    duration: 0.4,
                    ease: "power2.out",
                    stagger: 0.05, // スタッガーも高速化
                    overwrite: true
                });

                elementsToAnimate.forEach(el => observer.unobserve(el));
            }
        }, observerOptions);

        belowFold.forEach(card => observer.observe(card));
    }
};

document.addEventListener('DOMContentLoaded', initCardAnimations);
