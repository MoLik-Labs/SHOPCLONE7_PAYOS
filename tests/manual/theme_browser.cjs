/* Synthetic static-preview browser test. No DB, credentials or payments. */
const {createServer} = require('http');
const fs=require('fs'),path=require('path'),assert=require('assert');
const puppeteer=require('../../tools/dev/node_modules/puppeteer-core');
const root=path.resolve(process.env.PREVIEW_ROOT || '/tmp/molik-preview');
const mime={'.html':'text/html; charset=utf-8','.css':'text/css','.js':'application/javascript','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2'};
(async()=>{
 const server=createServer((req,res)=>{let f=path.resolve(root,'.'+decodeURIComponent(req.url.split('?')[0]));if(!f.startsWith(root+'/'))return res.writeHead(403).end();fs.readFile(f,(e,d)=>{if(e)return res.writeHead(404).end();res.setHeader('Content-Type',mime[path.extname(f)]||'application/octet-stream');res.end(d);});});
 await new Promise(r=>server.listen(0,'127.0.0.1',r));let b;
 try {
  b=await puppeteer.launch({executablePath:process.env.CHROMIUM_PATH||'/tmp/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  const url=`http://127.0.0.1:${server.address().port}/`;
  for(const width of [390,1440]) for(const theme of ['light','latte','mocha','dark']) {
   const page=await b.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(String(e)));
   await page.setViewport({width,height:900});
   await page.goto(url+'home.html',{waitUntil:'networkidle0'});
   await page.select('#appearance-theme',theme);
   const result=await page.evaluate(()=>{
    const root=document.documentElement, css=getComputedStyle(root);
    const val=k=>css.getPropertyValue(k).trim();
    const rgb=value=>{const el=document.createElement('span');el.style.color=value;document.body.appendChild(el);const v=getComputedStyle(el).color;el.remove();return v.match(/\d+(?:\.\d+)?/g).slice(0,3).map(Number)};
    const lum=c=>{let x=c.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return .2126*x[0]+.7152*x[1]+.0722*x[2]};
    const ratio=(a,b)=>{let x=lum(rgb(val(a))),y=lum(rgb(val(b)));return (Math.max(x,y)+.05)/(Math.min(x,y)+.05)};
    return {theme:root.dataset.theme,overflow:root.scrollWidth-root.clientWidth,ratios:[ratio('--text','--bg'),ratio('--text','--surface'),ratio('--text-muted','--surface-2'),ratio('--accent-contrast','--accent'),ratio('--danger-contrast','--danger')], logo:[...document.querySelectorAll('.topbar__brand img')].every(i=>i.complete&&i.naturalWidth>0)};
   });
   assert.equal(result.theme,theme);assert.equal(result.overflow,0,`${theme} ${width} overflow`);assert(result.logo);assert(result.ratios.every(x=>x>=4.5),`${theme} contrast ${result.ratios}`);assert.deepEqual(errors,[]);
   await page.reload({waitUntil:'networkidle0'});assert.equal(await page.$eval('html',e=>e.dataset.theme),theme);assert.equal(await page.$eval('#appearance-theme',e=>e.value),theme);
   await page.goto(url+'document-api.html',{waitUntil:'networkidle0'});assert.equal(await page.$eval('html',e=>e.dataset.theme),theme);
   console.log(`PASS ${theme} ${width}px, contrast ${result.ratios.map(x=>x.toFixed(2)).join(', ')}; persists across pages`);await page.close();
  }
  const invalid=await b.newPage();await invalid.evaluateOnNewDocument(()=>localStorage.setItem('molik.appearance.v1','<bad>'));await invalid.goto(url+'home.html',{waitUntil:'networkidle0'});assert.equal(await invalid.$eval('html',e=>e.dataset.theme),'light');await invalid.close();console.log('PASS invalid preference ignored');
  const blocked=await b.newPage();await blocked.evaluateOnNewDocument(()=>Object.defineProperty(window,'localStorage',{get(){throw new Error('blocked');}}));await blocked.goto(url+'home.html',{waitUntil:'networkidle0'});await blocked.select('#appearance-theme','mocha');assert.equal(await blocked.$eval('html',e=>e.dataset.theme),'mocha');assert.match(await blocked.$eval('#appearance-status',e=>e.textContent),/Không thể lưu/);await blocked.close();console.log('PASS blocked storage still allows a session-only choice');
 } finally {if(b)await b.close();server.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
