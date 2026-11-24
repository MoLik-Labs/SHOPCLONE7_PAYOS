// Local-only PHP built-in server probe; isolated fake DB, no proxy or external services.
// ORBIT_TEST_PHP=/path/to/native/php node tests/manual/api_http_probe.mjs
import {mkdtempSync,mkdirSync,copyFileSync,writeFileSync,rmSync} from 'node:fs';
import {resolve,join} from 'node:path';
import {spawn} from 'node:child_process';
import {createServer} from 'node:net';
import assert from 'node:assert/strict';
import {once} from 'node:events';

const php=process.env.ORBIT_TEST_PHP;
if (!php) throw new Error('Set ORBIT_TEST_PHP to an isolated native PHP executable');
mkdirSync(resolve('.cache'),{recursive:true});
const dir=mkdtempSync(resolve('.cache/api-http-'));
let child;
try {
  mkdirSync(join(dir,'api')); mkdirSync(join(dir,'libs/database'),{recursive:true});
  for(const f of ['profile.php','importAccount.php']) copyFileSync(resolve('api',f),join(dir,'api',f));
  copyFileSync(resolve('libs/api_credentials.php'),join(dir,'libs/api_credentials.php'));
  for(const f of ['config.php','libs/lang.php','libs/database/users.php']) writeFileSync(join(dir,f),'<?php\n');
  writeFileSync(join(dir,'libs/helper.php'),`<?php
function __($s){return $s;}
function check_string($s){return is_string($s)?trim(htmlspecialchars(addslashes($s),ENT_QUOTES,'UTF-8')):'';}
function checkPermission($admin,$permission){return $admin===2 && $permission==='edit_stock_product';}
function gettime(){return '2026-01-01 00:00:00';}
`);
  writeFileSync(join(dir,'libs/db.php'),`<?php
class DB {
 public static $errors=0;
 public function get_row($sql){
  if(strpos($sql,'users')!==false && strpos($sql,str_repeat('a',32))!==false)
   return ['id'=>7,'admin'=>2,'username'=>'fixture','money'=>100];
  return false;
 }
 public function insert($table,$data){return true;}
}
`);
  const socket=createServer(); socket.listen(0,'127.0.0.1'); await once(socket,'listening');
  const port=socket.address().port; await new Promise(r=>socket.close(r));
  child=spawn(php,['-n','-S',`127.0.0.1:${port}`,'-t',dir],{env:{...process.env,API_ALLOW_QUERY_KEYS:'false',API_ALLOW_LEGACY_IMPORT_GET:'false'},stdio:['ignore','pipe','pipe']});
  let log=''; child.stderr.on('data',d=>{log+=d});
  await Promise.race([new Promise((ok,fail)=>{child.stderr.on('data',()=>{if(log.includes('Development Server')) ok();}); child.once('exit',()=>fail(new Error(log)));}),new Promise((_,fail)=>{const timer=setTimeout(()=>fail(new Error('PHP server start timeout: '+log)),10000); timer.unref();})]);
  const base=`http://127.0.0.1:${port}/api/`, key='a'.repeat(32);
  const profile=await fetch(base+'profile.php',{headers:{'X-API-Key':key}});
  assert.equal(profile.status,200); assert.equal((await profile.json()).status,'success');
  assert.match(profile.headers.get('cache-control'),/no-store/); assert.match(profile.headers.get('content-type'),/application\/json/);
  assert.equal(profile.headers.get('x-content-type-options'),'nosniff');
  const query=await fetch(base+'profile.php?api_key='+key); assert.equal((await query.json()).status,'error');
  const imported=await fetch(base+'importAccount.php',{method:'POST',headers:{'X-API-Key':key,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({code:'SKU',account:'synthetic|fixture',filter:'1'})});
  assert.equal(imported.status,200); assert.equal((await imported.json()).status,'success'); assert.match(imported.headers.get('cache-control'),/no-store/);
  const mixed=await fetch(base+'importAccount.php?account=fixture',{method:'POST',headers:{'X-API-Key':key},body:''}); assert.equal(mixed.status,400);
  const blocked=await fetch(base+'importAccount.php'); assert.equal(blocked.status,405); assert.equal(blocked.headers.get('allow'),'POST');
  console.log('api_http_probe: 5 HTTP cases passed (PHP built-in server, fake DB, no proxy)');
} finally { if(child && child.exitCode === null && child.signalCode === null) { const exited=once(child,'exit'); child.kill(); await exited.catch(()=>{}); } rmSync(dir,{recursive:true,force:true}); }
