// render_pdf.js — prints a page to A4 landscape PDF with the same headless Chrome as render.js.
// Used by stats_pdf.php through render_capture_pdf() in render_lib.php.
const puppeteer = require('puppeteer-core');

// Same Chrome candidates as render.js.
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

const URL = process.argv[2];
const OUT = process.argv[3];
const WAIT_SELECTOR = process.argv[4] || 'body';
const FOOTER_TEXT = process.argv[5] || '';

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

    // Landscape A4 leaves about 1030px of width, past the desktop breakpoint, so the tables keep their on-screen columns.
    await page.setViewport({ width: 1123, height: 794, deviceScaleFactor: 1 });
    await page.goto(URL, { waitUntil: 'networkidle2', timeout: 60000 });
    await page.waitForSelector(WAIT_SELECTOR, { timeout: 60000 });
    await page.evaluate(async () => {
      if (document.fonts && document.fonts.ready) {
        await document.fonts.ready;
      }
    });
    // Let chart animations finish before the page is printed.
    await new Promise((resolve) => setTimeout(resolve, 1000));

    await page.emulateMediaType('print');
    await page.pdf({
      path: OUT,
      format: 'A4',
      landscape: true,
      printBackground: true,
      margin: { top: '12mm', right: '12mm', bottom: '16mm', left: '12mm' },
      displayHeaderFooter: true,
      headerTemplate: '<span></span>',
      footerTemplate: `<div style="width:100%;text-align:center;font-size:8px;color:#8c8689;font-family:sans-serif;">${FOOTER_TEXT} &#183; Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>`
    });

    console.log('Saved:', OUT);
  } finally {
    if (browser) {
      await browser.close();
    }
  }
})();
