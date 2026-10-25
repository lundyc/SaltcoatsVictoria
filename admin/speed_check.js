// speed_check.js <url> <mobile|desktop> [runs]
//
// Loads a page in headless Chrome and prints one JSON object of real timings:
// time to first byte, first and largest paint, layout shift, blocking time,
// page weight and request count. Mobile runs use a phone screen, a 4x slower
// CPU and a "slow 4G" connection, so the numbers resemble a typical visitor
// on a phone, not this fast server. Each page is loaded several times and the
// median is reported, because a single load is noisy.
const puppeteer = require('puppeteer-core');

function chromePath() {
  const candidates = ['/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/opt/google/chrome/chrome'];
  for (const candidate of candidates) {
    try { require('fs').accessSync(candidate); return candidate; } catch (e) { /* next */ }
  }
  return process.env.CHROME_BIN || null;
}

const URL_TO_TEST = process.argv[2];
const STRATEGY = process.argv[3] === 'desktop' ? 'desktop' : 'mobile';
const RUNS = Math.max(1, parseInt(process.argv[4] || '3', 10));

const PROFILES = {
  mobile: {
    viewport: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true },
    userAgent: 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36 SpeedCheck',
    cpu: 4,
    network: { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 },
  },
  desktop: {
    viewport: { width: 1350, height: 900, deviceScaleFactor: 1 },
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 SpeedCheck',
    cpu: 1,
    network: null,
  },
};

function median(values) {
  const v = values.filter((x) => typeof x === 'number' && isFinite(x)).sort((a, b) => a - b);
  if (!v.length) { return null; }
  const mid = Math.floor(v.length / 2);
  return v.length % 2 ? v[mid] : (v[mid - 1] + v[mid]) / 2;
}

async function runOnce(browser) {
  const profile = PROFILES[STRATEGY];
  const page = await browser.newPage();
  const client = await page.createCDPSession();
  try {
    await page.setViewport(profile.viewport);
    await page.setUserAgent(profile.userAgent);
    await page.setCacheEnabled(false);
    await client.send('Network.enable');
    if (profile.network) { await client.send('Network.emulateNetworkConditions', profile.network); }
    if (profile.cpu > 1) { await client.send('Emulation.setCPUThrottlingRate', { rate: profile.cpu }); }

    let bytes = 0;
    let requests = 0;
    client.on('Network.requestWillBeSent', () => { requests += 1; });
    client.on('Network.loadingFinished', (e) => { bytes += e.encodedDataLength || 0; });

    await page.evaluateOnNewDocument(() => {
      window.__sc = { lcp: null, cls: 0, tbt: 0, fcp: null };
      try {
        new PerformanceObserver((list) => {
          for (const e of list.getEntries()) { window.__sc.lcp = e.startTime; }
        }).observe({ type: 'largest-contentful-paint', buffered: true });
        new PerformanceObserver((list) => {
          for (const e of list.getEntries()) { if (!e.hadRecentInput) { window.__sc.cls += e.value; } }
        }).observe({ type: 'layout-shift', buffered: true });
        new PerformanceObserver((list) => {
          for (const e of list.getEntries()) {
            if (e.name === 'first-contentful-paint') { window.__sc.fcp = e.startTime; }
          }
        }).observe({ type: 'paint', buffered: true });
        new PerformanceObserver((list) => {
          for (const e of list.getEntries()) {
            if (window.__sc.fcp !== null && e.startTime >= window.__sc.fcp) { window.__sc.tbt += Math.max(0, e.duration - 50); }
          }
        }).observe({ type: 'longtask', buffered: true });
      } catch (err) { /* an observer type is unsupported: leave that metric null */ }
    });

    const response = await page.goto(URL_TO_TEST, { waitUntil: 'load', timeout: 90000 });
    await new Promise((resolve) => setTimeout(resolve, 2500)); // let late paints and shifts land
    const m = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0] || {};
      return {
        ttfb: nav.responseStart || null,
        load: nav.loadEventEnd || null,
        fcp: window.__sc.fcp,
        lcp: window.__sc.lcp,
        cls: window.__sc.cls,
        tbt: window.__sc.tbt,
      };
    });
    return Object.assign(m, { bytes, requests, status: response ? response.status() : null });
  } finally {
    await page.close();
  }
}

(async () => {
  const executablePath = chromePath();
  if (!executablePath) { console.error('No Chrome executable found.'); process.exit(2); }
  const browser = await puppeteer.launch({ headless: true, executablePath, args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu'] });
  try {
    const runs = [];
    for (let i = 0; i < RUNS; i += 1) { runs.push(await runOnce(browser)); }
    const out = { url: URL_TO_TEST, strategy: STRATEGY, runs: runs.length, status: runs[0].status };
    for (const key of ['ttfb', 'fcp', 'lcp', 'cls', 'tbt', 'load', 'bytes', 'requests']) {
      const v = median(runs.map((r) => r[key]));
      out[key] = v === null ? null : (key === 'cls' ? Math.round(v * 1000) / 1000 : Math.round(v));
    }
    console.log(JSON.stringify(out));
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error(String(error && error.message ? error.message : error)); process.exit(1); });
