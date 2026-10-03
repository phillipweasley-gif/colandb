const { chromium } = require('playwright');
(async () => {
  const H = 'http://localhost:8899', OUT = process.argv[2], PAGE = H + '/?page_id=' + (process.argv[3] || '23');
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }).catch(() => chromium.launch());
  const states = [['anon', null], ['unverified', 'unv'], ['attest', 'att'], ['member', 'mem']];
  const report = [];
  for (const [name, user] of states) {
    for (const width of [320, 375, 768, 1440]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      const page = await ctx.newPage();
      if (user) {
        await page.goto(H + '/wp-login.php');
        await page.fill('#user_login', user); await page.fill('#user_pass', 'pass1234');
        await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
      }
      await page.goto(PAGE);
      const m = await page.evaluate(() => {
        const area = document.querySelector('.cmp-member-area');
        const btns = [...document.querySelectorAll('.cmp-member-area .cmp-btn')].map(b => {
          const cs = getComputedStyle(b), r = b.getBoundingClientRect();
          return { h: Math.round(r.height), w: Math.round(r.width), bg: cs.backgroundColor, td: cs.textDecorationLine };
        });
        return { overflowX: document.documentElement.scrollWidth > window.innerWidth, areaW: area ? Math.round(area.getBoundingClientRect().width) : 0, btns };
      });
      report.push({ name, width, ...m });
      if (width === 375 || width === 1440) {
        const el = await page.$('.cmp-member-area');
        await el.screenshot({ path: `${OUT}/${name}-${width}.png` });
      }
      if (name === 'member' && width === 1440) {
        // Click "Mark as read" on the unread invitation and confirm the UI updates.
        const before = await page.textContent('[data-cmp-unread]');
        await page.click('.cmp-notification:not(.is-read) [data-cmp-read] >> nth=0');
        await page.waitForTimeout(800);
        const after = await page.textContent('[data-cmp-unread]');
        const hidden = await page.$eval('[data-cmp-unread]', e => e.hidden);
        report.push({ clickTest: { before, after, badgeHidden: hidden } });
        await (await page.$('.cmp-member-area')).screenshot({ path: `${OUT}/member-after-read-1440.png` });
      }
      await ctx.close();
    }
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})();
