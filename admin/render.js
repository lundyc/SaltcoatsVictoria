// render.js
const puppeteer = require('puppeteer-core');

function resolveExecutablePath() {
  const candidates = [
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/opt/google/chrome/chrome',
  ];

  for (const candidate of candidates) {
    try {
      require('fs').accessSync(candidate);
      return candidate;
    } catch (error) {
      // continue
    }
  }

  return process.env.CHROME_BIN || null;
}

const URL = process.argv[2] || 'league_table.php';
const OUT = process.argv[3] || 'latest_wosfl.png';
const SELECTOR = process.argv[4] || '#table-container';
const WIDTH = parseInt(process.argv[5] || '680', 10);
const HEIGHT = parseInt(process.argv[6] || '709', 10);
const WAIT_SELECTOR = process.argv[7] || SELECTOR;
const DEVICE_SCALE_FACTOR = parseInt(process.argv[8] || '1', 10) || 1;

(async () => {
  let browser;

  try {
    const executablePath = resolveExecutablePath();
    if (!executablePath) {
      throw new Error('No Chrome executable found for Puppeteer.');
    }

    browser = await puppeteer.launch({
      headless: true,
      executablePath,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--hide-scrollbars']
    });
    const page = await browser.newPage();

    await page.setViewport({ width: WIDTH, height: HEIGHT, deviceScaleFactor: DEVICE_SCALE_FACTOR });

    await page.goto(URL, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.waitForSelector(WAIT_SELECTOR, { visible: true, timeout: 60000 });
    await page.evaluate(async () => {
      if (document.fonts && document.fonts.ready) {
        await document.fonts.ready;
      }
    });

    await page.evaluate(() => {
      const el = document.getElementById('time-since');
      if (el) el.style.display = 'none';
    });

    const container = await page.$(SELECTOR);
    if (!container) {
      throw new Error(`Selector not found: ${SELECTOR}`);
    }
    await container.screenshot({ path: OUT, type: 'png' });

    console.log('Saved:', OUT);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();
