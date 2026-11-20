/*
 * Orbit script contract — zero-dependency checks for the browser files.
 *
 *   node tests/js/orbit_script_test.mjs
 *
 * The PHP suites cannot execute JavaScript, and the repository does not take a test-time dependency
 * just to run two small files. This drives chart.js and table.js in a Node vm with the minimum DOM
 * surface they touch, and checks the two things a browser bug report actually showed:
 *
 *   1. the donut always fits inside its plot box (it used to be drawn with a radius twice the
 *      available height and spilled over the legend, because chart.css lets the SVG overflow);
 *   2. numbers are parsed and printed the way the shop prints them ("1.234.567", not 1,234.567).
 *
 * Node is only needed for this file; the application and every other suite run without it.
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');

let passed = 0;
let failed = 0;
function check(label, condition, detail = '') {
    if (condition) {
        passed++;
    } else {
        failed++;
        console.error(`  FAIL ${label}${detail ? '  >> ' + detail : ''}`);
    }
}

function loadScript(relative) {
    const window = { addEventListener() {} };
    const document = {
        readyState: 'complete',
        addEventListener() {},
        querySelectorAll() { return []; },
        querySelector() { return null; },
        createElement() { return {}; },
        createElementNS() { return {}; },
    };
    const sandbox = { window, document, console, setTimeout, clearTimeout };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(path.join(root, relative), 'utf8'), sandbox, { filename: relative });
    return window;
}

/* ------------------------------------------------------------------ chart.js */

const chartWindow = loadScript('public/client/orbit/chart.js');
check('chart.js exposes its test hooks', chartWindow.orbitCharts && typeof chartWindow.orbitCharts.donutGeometry === 'function');
const charts = chartWindow.orbitCharts || {};

const plots = [
    { left: 54, top: 14, width: 800, height: 180 },
    { left: 54, top: 14, width: 260, height: 40 },
    { left: 54, top: 14, width: 420, height: 220 },
    { left: 3, top: 3, width: 1000, height: 400 },
];
for (const plot of plots) {
    const geometry = charts.donutGeometry ? charts.donutGeometry(plot) : { fits: false, radius: 0, stroke: 0 };
    check(
        `donut fits a ${plot.width}x${plot.height} plot`,
        geometry.fits === true,
        `radius ${geometry.radius}, stroke ${geometry.stroke}`
    );
    const reach = geometry.radius + geometry.stroke / 2;
    const limit = Math.min(plot.width, plot.height) / 2;
    check(
        `donut stays inside the shorter half-axis (${plot.width}x${plot.height})`,
        reach <= limit + 0.01,
        `reach ${reach}, limit ${limit}`
    );
}

if (charts.groupNumber) {
    check('groups thousands with the server separators',
        charts.groupNumber(1234567, 0, ',', '.') === '1.234.567');
    check('keeps a decimal comma', charts.groupNumber(1234.5, 1, ',', '.') === '1.234,5');
    check('handles negatives', charts.groupNumber(-1234.5, 1, ',', '.') === '-1.234,5');
}
if (charts.fmtNumber) {
    const payload = { format: 'currency', decimal: ',', thousands: '.', prefix: '', suffix: 'đ' };
    check('a currency tooltip matches the PHP table', charts.fmtNumber(1234567, payload) === '1.234.567đ');
}
if (charts.fmtShort) {
    const payload = { decimal: ',', thousands: '.' };
    check('short millions use the decimal comma', charts.fmtShort(1500000, payload) === '1,5M');
    check('a round million drops the decimal', charts.fmtShort(1000000, payload) === '1M');
    check('thousands are shortened', charts.fmtShort(1500, payload) === '1,5k');
    check('small numbers stay whole', charts.fmtShort(999, payload) === '999');
}

/* ------------------------------------------------------------------ table.js */

const tableWindow = loadScript('public/client/orbit/table.js');
check('table.js exposes parseNumber', tableWindow.orbitTables && typeof tableWindow.orbitTables.parseNumber === 'function');
const parseNumber = (tableWindow.orbitTables || {}).parseNumber || (() => null);

check('dot-grouped thousands parse exactly', parseNumber('1.234.567') === 1234567);
check('a three-digit group is thousands, not a decimal', parseNumber('1.234') === 1234);
check('comma-grouped thousands parse exactly', parseNumber('1,234,567') === 1234567);
check('an English money string parses', parseNumber('$1,234.56') === 1234.56);
check('a Vietnamese money string parses', parseNumber('1.234,56đ') === 1234.56);
check('a suffix does not change the value', parseNumber('12.345.678đ') === 12345678);
check('a negative amount stays negative', parseNumber('-500') === -500);
check('a percentage parses', parseNumber('12%') === 12);
check('a non-number is refused', parseNumber('Netflix') === null);
check('an empty string is refused', parseNumber('') === null);

console.log(`\norbit_script_test: ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
