// Real PHP endpoints in isolated PHP-WASM subprocesses with fake DB only; no network or shop data.
import { mkdtempSync, mkdirSync, copyFileSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { resolve, join } from 'node:path';
import { spawnSync } from 'node:child_process';
import assert from 'node:assert/strict';

const root = resolve('.cache'); mkdirSync(root, {recursive:true});
const fixture = mkdtempSync(join(root, 'api-integration-'));
try {
  mkdirSync(join(fixture,'api')); mkdirSync(join(fixture,'libs/database'), {recursive:true});
  for (const file of ['profile.php','product.php','products.php','api.php','importAccount.php']) copyFileSync(resolve('api',file), join(fixture,'api',file));
  copyFileSync(resolve('libs/api_credentials.php'), join(fixture,'libs/api_credentials.php'));
  for (const file of ['config.php','libs/lang.php','libs/sendEmail.php','libs/suppliers.php','libs/database/users.php']) writeFileSync(join(fixture,file),'<?php\n');
  writeFileSync(join(fixture,'libs/helper.php'), `<?php
function __($s) { return $s; }
function check_string($s) { return is_string($s) ? trim(htmlspecialchars(addslashes($s), ENT_QUOTES, 'UTF-8')) : ''; }
function checkPermission($admin, $role) { global $scenario; return $scenario !== 'no-permission' && $role === 'edit_stock_product'; }
function gettime() { return '2026-01-01 00:00:00'; }
function BASE_URL($p = '') { return 'https://shop.example/' . $p; }
function getStock($code) { return 4; }
class Mobile_Detect {}
`);
  writeFileSync(join(fixture,'libs/db.php'), `<?php
$scenario = $argv[1]; $trace = $argv[2]; $key = str_repeat('a',32);
$_GET=[]; $_POST=[]; $_REQUEST=[]; $_ENV=[];
$_SERVER['REQUEST_METHOD']='GET'; unset($_SERVER['HTTP_X_API_KEY']);
if (strpos($scenario,'legacy') === 0 || $scenario === 'invalid-header') { $_GET['api_key']=$key; }
if ($scenario === 'legacy-disabled') { $_ENV['API_ALLOW_QUERY_KEYS']='false'; }
if ($scenario === 'legacy-get-disabled') { $_ENV['API_ALLOW_LEGACY_IMPORT_GET']='false'; }
if (strpos($scenario,'legacy') !== 0 || $scenario === 'invalid-header') { $_SERVER['HTTP_X_API_KEY'] = $scenario === 'invalid-header' ? 'bad key' : $key; }
if (strpos($scenario,'import-') === 0 || in_array($scenario,['duplicate','db-error','insert-error','no-permission','non-admin','banned','wrong-key','malformed','bad-uid','mixed-url','post-body-key'])) {
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=['code'=>'SKU-1','account'=>'user|password','filter'=>'1'];
}
if (in_array($scenario, ['legacy-import','legacy-get-disabled','legacy-insert-error','legacy-db-error','legacy-update','legacy-update-error'])) { $_GET += ['code'=>'SKU-1','account'=>'user|password','filter'=>'1']; }
if ($scenario === 'mixed-url') { $_GET['account']='should-not-use'; }
if ($scenario === 'malformed') { $_POST['account']=['broken']; }
if ($scenario === 'bad-uid') { $_POST['account']='|password'; }
if ($scenario === 'import-no-filter') { unset($_POST['filter']); }
if ($scenario === 'import-filter-zero') { $_POST['filter']='0'; }
if ($scenario === 'post-body-key') { unset($_SERVER['HTTP_X_API_KEY']); $_POST['api_key']=$key; }
if ($scenario === 'wrong-key') { $_SERVER['HTTP_X_API_KEY']=str_repeat('b',32); }
if ($scenario === 'import-no-query-keys') { $_ENV['API_ALLOW_QUERY_KEYS']='false'; }
$_REQUEST = array_merge($_GET, $_POST);
if (strpos($scenario,'profile') === 0 || strpos($scenario,'legacy') === 0 || $scenario === 'invalid-header') { }
if ($scenario === 'product-header') { $_GET['product']='7'; }
if ($scenario === 'api-balance') { $_GET['action']='get-balance'; }
if (in_array($scenario, ['order-own','order-other','order-missing','order-error'])) { $_GET['action']='get-order-detail'; if ($scenario !== 'order-missing') { $_GET['order_id']=in_array($scenario,['order-own','order-error']) ? 'ORD123' : 'ORDOTHER'; } }
register_shutdown_function(function() { echo "\\nHTTP_STATUS=" . (http_response_code() ?: 200); });
class DB {
    public static $errors=0;
    public function get_row($sql) {
        global $scenario, $key, $trace;
        file_put_contents($trace, 'read\\n', FILE_APPEND);
        if (strpos($sql,'FROM \`users\`') !== false) {
            if ($scenario === 'banned' || strpos($sql, $key) === false || strpos($sql, '\`banned\` = 0') === false) { return false; }
            if (strpos($sql, '\`admin\` != 0') !== false && $scenario === 'non-admin') { return false; }
            return ['id'=>7,'admin'=>2,'username'=>'fixture','money'=>1000];
        }
        if (strpos($sql,'FROM \`product_stock\`') !== false) {
            if (in_array($scenario, ['db-error','legacy-db-error'])) { ++self::$errors; return false; }
            return in_array($scenario, ['duplicate','legacy-update','legacy-update-error']) ? ['id'=>5,'product_code'=>'OTHER'] : false;
        }
        if (strpos($sql,'FROM \`product_sold\`') !== false) {
            return strpos($sql, "\`buyer\` = '7'") !== false && strpos($sql, "'ORD123'") !== false ? ['id'=>1] : false;
        }
        return false;
    }
    public function get_list($sql) {
        global $trace, $scenario;
        file_put_contents($trace, 'list\\n', FILE_APPEND);
        if (strpos($sql, '\`product_sold\`') !== false) {
            if ($scenario === 'order-error') { ++self::$errors; return []; }
            return strpos($sql, "\`buyer\` = '7'") !== false && strpos($sql, "'ORD123'") !== false ? [['account'=>"one|fake\\r"],['account'=>'two|fake']] : [];
        }
        if (strpos($sql, '\`categories\`') !== false) { return [['id'=>1,'name'=>'Category','icon'=>'icon.png']]; }
        if (strpos($sql, '\`products\`') !== false) { return [['id'=>7,'name'=>'Item','price'=>123,'supplier_id'=>0,'api_stock'=>0,'code'=>'SKU-1','short_desc'=>'fixture','flag'=>'','min'=>1,'max'=>10]]; }
        return [];
    }
    public function insert($table,$data) { global $scenario,$trace; file_put_contents($trace, 'insert\\n', FILE_APPEND); return !in_array($scenario, ['insert-error','legacy-insert-error']); }
    public function update($table,$data,$where) { global $scenario,$trace; file_put_contents($trace, 'update\\n', FILE_APPEND); return $scenario !== 'legacy-update-error'; }
}
`);
  const cases = [
    ['profile.php','profile-header',200,'success',''], ['profile.php','legacy-profile',200,'success',''],
    ['profile.php','legacy-disabled',200,'error',''], ['profile.php','invalid-header',200,'error',''],
    ['product.php','product-header',200,'success',''], ['products.php','products-header',200,'success',''],
    ['api.php','api-balance',200,null,''], ['api.php','order-own',200,'order-ok',''],
    ['api.php','order-other',200,'order-invalid',''], ['api.php','order-missing',200,'order-invalid',''],
    ['api.php','order-error',503,'order-invalid',''],
    ['importAccount.php','import-ok',200,'success','insert'],
    ['importAccount.php','import-no-filter',200,'success','insert'], ['importAccount.php','legacy-import',200,'success','insert'],
    ['importAccount.php','legacy-get-disabled',405,'error',''], ['importAccount.php','legacy-insert-error',503,'error','insert'],
    ['importAccount.php','legacy-db-error',503,'error',''], ['importAccount.php','legacy-update',200,'success','update'],
    ['importAccount.php','legacy-update-error',503,'error','update'], ['importAccount.php','duplicate',409,'error',''],
    ['importAccount.php','db-error',503,'error',''], ['importAccount.php','insert-error',503,'error','insert'],
    ['importAccount.php','no-permission',200,'error',''], ['importAccount.php','non-admin',200,'error',''],
    ['importAccount.php','banned',200,'error',''], ['importAccount.php','wrong-key',200,'error',''],
    ['importAccount.php','post-body-key',200,'success','insert'], ['importAccount.php','import-no-query-keys',200,'success','insert'],
    ['importAccount.php','malformed',400,'error',''],
    ['importAccount.php','bad-uid',400,'error',''], ['importAccount.php','mixed-url',400,'error',''],
    ['importAccount.php','import-filter-zero',400,'error',''], ['importAccount.php','import-get-header',200,'success','insert'],
  ];
  // This case deliberately uses GET with the header (not POST).
  cases[cases.length-1] = ['importAccount.php','get-header',405,'error',''];
  for (const [file,scenario,status,expected,action] of cases) {
    const trace=join(fixture,`trace-${scenario}`);
    const nativePhp = process.env.ORBIT_TEST_PHP;
    const executable = nativePhp || process.execPath;
    const args = nativePhp ? ['-n', join(fixture,'api',file), scenario, trace]
      : [resolve('tools/dev/phpcl.mjs'), join(fixture,'api',file), scenario, trace];
    const r=spawnSync(executable,args,{encoding:'utf8',timeout:30000});
    assert.equal(r.status,0,`${scenario}: ${r.stderr} ${r.stdout}`);
    assert.equal(r.stderr,'',`${scenario}: unexpected stderr`);
    const match=r.stdout.match(/^(.*)\nHTTP_STATUS=(\d+)$/s); assert.ok(match,`${scenario}: ${r.stdout}`);
    assert.equal(Number(match[2]),status,scenario); const body=JSON.parse(match[1]);
    if(expected === 'order-ok') { assert.equal(body.code,200); assert.equal(body.order.data,'one|fake\ntwo|fake'); }
    else if (expected === 'order-invalid') { assert.equal(body.code,201); assert.equal(body.order.status,0); }
    else if(expected) assert.equal(body.status,expected,scenario); else assert.equal(body.username,'fixture',scenario);
    const events=existsSync(trace)?readFileSync(trace,'utf8'):'';
    if (scenario === 'order-own') { assert.equal((events.match(/read/g)||[]).length,1,'order lookup: only authentication uses get_row'); assert.equal((events.match(/list/g)||[]).length,1,'order lookup uses one list query'); }
    assert.equal(events.includes('insert'),action==='insert',`${scenario} insert`);
    assert.equal(events.includes('update'),action==='update',`${scenario} update`);
    assert.equal(r.stdout.includes('a'.repeat(32)),false,`${scenario} leaked credential`);
    console.log(`PASS ${scenario}`);
  }
  console.log(`api_integrations_test: ${cases.length} passed, 0 failed (isolated fake DB)`);
} finally { rmSync(fixture,{recursive:true,force:true}); }
