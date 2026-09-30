/** Actual built Vue app and API client; mock only the local HTTP boundary. */
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const build = path.resolve(process.env.ADMIN_BUILD || 'public/build');
const manifest = JSON.parse(await readFile(path.join(build, 'manifest.json'), 'utf8'));
const entry = manifest['resources/js/app.ts'];
const auth = { authenticated: true, user: { id: 1, name: 'Local QA', email: 'local@example.invalid',
    user_type: 'platform', status: 'active', company_id: 1, locale: 'en', roles: ['platform_super_admin'], permissions: [] }, session: { remembered:false,idle_timeout_seconds:3600,last_activity_at:null } };
const html = '<!doctype html><html><head><meta name="csrf-token" content="test-only"><meta name="viewport" content="width=device-width, initial-scale=1">' +
    (entry.css || []).map(file => '<link rel="stylesheet" href="/build/' + file + '">').join('') +
    '</head><body><div id="app"></div><script>window.__INITIAL_AUTH__=' + JSON.stringify(auth) +
    '</script><script type="module" src="/build/' + entry.file + '"></script></body></html>';
const server = createServer(async (req, res) => {
    if (req.url.startsWith('/build/')) {
        const file = path.resolve(build, decodeURIComponent(req.url.slice(7).split('?')[0]));
        assert.ok(file.startsWith(build + path.sep));
        res.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'application/javascript');
        res.end(await readFile(file));
    } else { res.setHeader('Content-Type', 'text/html'); res.end(html); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
let browserServer;
try {
    browserServer = await chromium.launchServer({ host: '127.0.0.1', headless: true,
        ...(process.env.CHROMIUM_EXECUTABLE ? { executablePath: process.env.CHROMIUM_EXECUTABLE } : {}) });
    const browser = await chromium.connect(browserServer.wsEndpoint());
    const device={id:1,uuid:'device-a',name:'Test terminal',serial_number:'TEST-1',kiosk_id:'TEST',device_type:'fixed_pos',status:'active',
        company_id:1,branch_id:1,bank_id:1,terminal_id:'T1',terminal_pin_set:true,softpos:{provider:'mosambee',label:'Test',blocked_reason:null},
        company:{id:1,uuid:'c',name:'Test merchant'},branch:{id:1,uuid:'b',name:'Test branch'},assignment_history:[],activation_tokens:[]};
    const line=(id,reason)=>({statement:{row_number:id,date:'2026-09-30',terminal_id:'T'+id,auth_code:'A'+id,gross_amount:1},
        payment:{id,terminal_id:'T'+id,auth_code:'A'+id,amount:1,status:'success',pending_reconciliation:!reason,ineligible_reason:reason,direction:'sale',softpos_provider:'mosambee'},bank_fee:'0.010'});
    for(const width of [1440,390]){
        const page=await browser.newPage({viewport:{width,height:900}});const errors=[];const commits=[];const assignments=[];let committed=false;
        page.on('pageerror',e=>errors.push(e.message));
        await page.route('**/auth/**',route=>route.fulfill({json:auth}));
        await page.route('**/admin/api/**',route=>{
            const url=route.request().url();
            if(url.endsWith('/bank-reconciliation/commit')){commits.push(route.request().postDataJSON());committed=true;return route.fulfill({json:{data:{reconciled:1,payment_ids:[1]}}});}
            if(url.endsWith('/bank-reconciliation/preview')){
                const ready=committed?[]:[line(1,null)],excluded=[line(2,'already_reconciled'),line(3,'void')];
                if(committed)excluded.push(line(1,'already_reconciled'));
                return route.fulfill({json:{data:{bank:{id:1,name:'Test bank'},statement_token:'proof',statement_date:'2026-09-30',summary:{statement_rows:3,matched_rows:3},
                    ready_to_reconcile:ready,excluded_matches:excluded,matched:[...ready,...excluded],missing_in_db:[],amount_mismatches:[],db_only:[],invalid_rows:[]}}});
            }
            if(url.endsWith('/devices/device-a/assign')){assignments.push(route.request().postDataJSON());return route.fulfill({json:{data:device}});}
            if(url.endsWith('/devices/device-a'))return route.fulfill({json:{data:device}});
            if(url.includes('/banks'))return route.fulfill({json:{data:[{id:1,name:'Test bank',short_name:'TEST',is_active:true}]}});
            if(url.includes('/merchants'))return route.fulfill({json:{data:[{id:1,uuid:'c',name:'Test merchant'}]}});
            if(url.includes('/branches'))return route.fulfill({json:{data:[{id:1,uuid:'b',company_id:1,name:'Test branch',status:'active'}]}});
            return route.fulfill({json:{data:[],unread:0}});
        });
        await page.goto(origin+'/admin/settings/bank-reconciliation');
        await page.locator('main select').first().selectOption('1');
        await page.locator('input[type=date]').fill('2026-09-30');
        await page.locator('input[type=file]').setInputFiles({name:'statement.csv',mimeType:'text/csv',buffer:Buffer.from('synthetic fixture')});
        await page.getByRole('button',{name:'Generate preview',exact:true}).click();
        await page.getByRole('heading',{name:'Ready to reconcile',exact:true}).waitFor();
        const excluded=page.locator('section').filter({has:page.getByRole('heading',{name:/Already reconciled/})});
        assert.equal(await excluded.locator('input,button').count(),0);
        await page.getByRole('button',{name:'Mark matched as reconciled',exact:true}).click();
        await page.waitForFunction(()=>!document.body.innerText.includes('Ready to reconcile'));
        assert.deepEqual(commits[0].payment_ids,[1]);assert.deepEqual(commits[0].fees,{'1':'0.010'});
        assert.equal(await page.getByRole('button',{name:'Mark matched as reconciled',exact:true}).count(),0);
        if(process.env.EVIDENCE_DIR){await mkdir(process.env.EVIDENCE_DIR,{recursive:true});await page.screenshot({path:path.join(process.env.EVIDENCE_DIR,'admin-reconciled-'+width+'.png')});}
        console.log('PASS B9 statement includes reconciled/void; only ready ID 1 committed; repeat has no commit; width='+width);
        await page.goto(origin+'/admin/devices/device-a');
        await page.getByRole('button',{name:'Reassign',exact:true}).click();
        const dialog=page.getByRole('dialog');
        await dialog.locator('input[type=password]').waitFor();
        assert.equal(await dialog.locator('input[type=password]').inputValue(),'');
        assert.equal(await dialog.locator('input[type=checkbox]').last().isChecked(),false);
        await dialog.getByRole('button',{name:'Assign device',exact:true}).click();
        await dialog.waitFor({state:'hidden'});
        assert.equal(assignments[0].terminal_pin,null);assert.equal(assignments[0].use_default_pin,false);
        await page.getByRole('button',{name:'Reassign',exact:true}).click();
        await dialog.locator('input[type=checkbox]').last().check();
        assert.equal(await dialog.locator('input[type=password]').isDisabled(),true);
        if(process.env.EVIDENCE_DIR)await page.screenshot({path:path.join(process.env.EVIDENCE_DIR,'admin-pin-'+width+'.png')});
        await dialog.getByRole('button',{name:'Assign device',exact:true}).click();await dialog.waitFor({state:'hidden'});
        assert.equal(assignments[1].use_default_pin,true);
        assert.deepEqual(errors,[]);assert.equal(await page.locator('vite-error-overlay').count(),0);
        console.log('PASS B14 blank keeps PIN; explicit default submits true; no runtime errors; width='+width);
        await page.close();
    }

} finally {
    if (browserServer) await browserServer.kill();
    await new Promise(resolve => server.close(resolve));
}
