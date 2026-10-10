import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { chromium } from 'playwright';

const baseUrl = process.env.NODE_VISUAL_BASE_URL ?? 'http://cybernode.local';
const paths = (process.env.NODE_VISUAL_PATHS ?? '/,/?s=node')
  .split(',')
  .map((path) => path.trim())
  .filter(Boolean);
const expectedStatuses = new Map(
  Object.entries(JSON.parse(process.env.NODE_VISUAL_EXPECTED_STATUSES ?? '{}'))
    .map(([path, status]) => [path, Number(status)]),
);

const viewports = [
  { name: 'desktop', width: 1440, height: 1000 },
  { name: 'mobile', width: 390, height: 844 },
];

const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
const outputDir = join(process.cwd(), 'scratch', 'visual-check', timestamp);
const maxIssuesPerPage = 25;

function urlForPath(path) {
  return new URL(path, baseUrl).toString();
}

function safeName(value) {
  return value
    .replace(/^https?:\/\//, '')
    .replace(/[^A-Za-z0-9_-]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 120);
}

async function inspectTextLayout(page) {
  return page.evaluate((limit) => {
    const selector = [
      'a',
      'button',
      'input',
      'select',
      'textarea',
      '[role="button"]',
      '[class*="button"]',
      '[class*="btn"]',
      'p',
      'li',
      'figcaption',
      'blockquote',
      'h1',
      'h2',
      'h3',
      'h4',
      'h5',
      'h6',
      '.entry-content *',
      '.post-content *',
      '.article-content *',
    ].join(',');

    function isVisible(element, style, rect) {
      return (
        rect.width > 0 &&
        rect.height > 0 &&
        style.visibility !== 'hidden' &&
        style.display !== 'none' &&
        Number(style.opacity) !== 0
      );
    }

    function labelFor(element) {
      const text = (element.innerText || element.value || '')
        .replace(/\s+/g, ' ')
        .trim();
      return text.length > 60 ? `${text.slice(0, 57)}...` : text;
    }

    function pathFor(element) {
      const parts = [];
      let current = element;

      while (current && current.nodeType === Node.ELEMENT_NODE && parts.length < 4) {
        const tag = current.tagName.toLowerCase();
        const id = current.id ? `#${current.id}` : '';
        const className = String(current.className || '')
          .split(/\s+/)
          .filter(Boolean)
          .slice(0, 2)
          .map((name) => `.${name}`)
          .join('');
        parts.unshift(`${tag}${id}${className}`);
        current = current.parentElement;
      }

      return parts.join(' > ');
    }

    const issues = [];
    const seen = new Set();
    const elements = Array.from(document.querySelectorAll(selector));

    for (const element of elements) {
      if (issues.length >= limit) break;
      if (seen.has(element)) continue;
      seen.add(element);
      // Screen-reader-only labels have intentional 1px boxes, not visible text layout.
      if (element.matches('.screen-reader-text, .lf-hero-sr-only')) continue;

      const style = window.getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      const text = labelFor(element);

      if (!text || !isVisible(element, style, rect)) continue;

      const overflowX = element.scrollWidth - element.clientWidth;
      const overflowY = element.scrollHeight - element.clientHeight;
      const clipsX = ['hidden', 'clip', 'auto', 'scroll'].includes(style.overflowX);
      const clipsY = ['hidden', 'clip', 'auto', 'scroll'].includes(style.overflowY);
      const fontSize = Number.parseFloat(style.fontSize) || 16;
      const lineHeight = style.lineHeight === 'normal'
        ? fontSize * 1.2
        : Number.parseFloat(style.lineHeight);
      const nowrap = style.whiteSpace.includes('nowrap');
      const isControl = element.matches('a, button, input, select, textarea, [role="button"], [class*="button"], [class*="btn"]');
      const isInsideControl = Boolean(element.closest('a, button, [role="button"], [class*="button"], [class*="btn"]'));
      const isIcon = element.matches('.material-symbols-outlined, [aria-hidden="true"], [class*="__icon"], [class*="-icon"]');
      const isIntentionalEllipsis = style.textOverflow === 'ellipsis';
      const lineClamp = Number.parseInt(style.webkitLineClamp, 10);
      const isIntentionalLineClamp = Number.isFinite(lineClamp) && lineClamp > 0 && style.webkitBoxOrient === 'vertical';

      if (overflowX > 2 && (clipsX || nowrap || isControl) && !isIntentionalEllipsis) {
        issues.push({
          type: 'horizontal-text-overflow',
          selector: pathFor(element),
          text,
          details: `scrollWidth ${element.scrollWidth}px > clientWidth ${element.clientWidth}px`,
        });
        continue;
      }

      if (overflowY > 2 && (clipsY || isControl) && !isIntentionalLineClamp && !isIcon) {
        issues.push({
          type: 'vertical-text-clipping',
          selector: pathFor(element),
          text,
          details: `scrollHeight ${element.scrollHeight}px > clientHeight ${element.clientHeight}px`,
        });
        continue;
      }

      if (Number.isFinite(lineHeight) && lineHeight < fontSize * 1.05 && !isInsideControl && !isIcon) {
        issues.push({
          type: 'tight-line-height',
          selector: pathFor(element),
          text,
          details: `line-height ${lineHeight}px / font-size ${fontSize}px`,
        });
      }
    }

    return issues;
  }, maxIssuesPerPage);
}

async function run() {
  await mkdir(outputDir, { recursive: true });

  const browser = await chromium.launch();
  const results = [];

  try {
    for (const viewport of viewports) {
      const context = await browser.newContext({
        viewport: { width: viewport.width, height: viewport.height },
        deviceScaleFactor: 1,
      });

      const page = await context.newPage();
      const browserErrors = [];

      page.on('pageerror', (error) => {
        browserErrors.push({ details: `pageerror: ${error.message}`, url: '' });
      });

      page.on('console', (message) => {
        if (message.type() === 'error') {
          browserErrors.push({
            details: `console: ${message.text()}`,
            url: message.location().url || '',
          });
        }
      });

      for (const path of paths) {
        const url = urlForPath(path);
        const expectedStatus = expectedStatuses.get(path) ?? 200;
        const response = await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
        const status = response?.status() ?? 0;
        const title = await page.title();
        const screenshotName = `${viewport.name}-${safeName(path || 'root') || 'root'}.png`;
        const screenshotPath = join(outputDir, screenshotName);

        await page.screenshot({ path: screenshotPath, fullPage: true });

        const layoutIssues = await inspectTextLayout(page);
        const unexpectedBrowserErrors = browserErrors.splice(0).filter((error) => {
          const isExpectedDocumentError = status === expectedStatus
            && expectedStatus >= 400
            && error.url === url
            && error.details.includes(`status of ${expectedStatus}`);

          return !isExpectedDocumentError;
        });
        results.push({
          viewport: viewport.name,
          url,
          status,
          expectedStatus,
          title,
          screenshotPath,
          issues: [
            ...(status !== expectedStatus
              ? [{ type: 'http-status', details: `expected ${expectedStatus}, got ${status}` }]
              : []),
            ...unexpectedBrowserErrors.map((error) => ({ type: 'browser-error', details: error.details })),
            ...layoutIssues,
          ],
        });
      }

      await context.close();
    }
  } finally {
    await browser.close();
  }

  let issueCount = 0;

  console.log('Visual layout check');
  console.log(`- Base URL: ${baseUrl}`);
  console.log(`- Paths: ${paths.join(', ')}`);
  console.log(`- Screenshots: ${outputDir}`);

  for (const result of results) {
    console.log(`\n[${result.viewport}] ${result.url}`);
    console.log(`- HTTP: ${result.status} (expected ${result.expectedStatus})`);
    console.log(`- Title: ${result.title}`);
    console.log(`- Screenshot: ${result.screenshotPath}`);

    if (result.issues.length === 0) {
      console.log('- Issues: none');
      continue;
    }

    issueCount += result.issues.length;
    console.log(`- Issues: ${result.issues.length}`);

    for (const issue of result.issues) {
      const selector = issue.selector ? ` ${issue.selector}` : '';
      const text = issue.text ? ` "${issue.text}"` : '';
      console.log(`  - ${issue.type}:${selector}${text} (${issue.details})`);
    }
  }

  if (issueCount > 0) {
    process.exitCode = 1;
  }
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
