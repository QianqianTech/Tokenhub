// usage: node render.js jobs.json   jobs: [[svgPath, outPng, width, height]]
const {chromium}=require('playwright');const fs=require('fs');
(async()=>{const jobs=JSON.parse(fs.readFileSync(process.argv[2]));const b=await chromium.launch();const p=await b.newPage();
for(const [src,out,w,h] of jobs){await p.setViewportSize({width:w,height:h});
const svg=fs.readFileSync(src,'utf8');
await p.setContent(`<html><body style="margin:0;background:transparent"><img style="display:block;width:${w}px;height:${h}px" src="data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}"></body></html>`);
await p.screenshot({path:out,omitBackground:true,clip:{x:0,y:0,width:w,height:h}});}
await b.close();})();
