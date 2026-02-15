// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Technical fingerprinting module for agent detection.
 *
 * Collects browser fingerprint data to identify automation tools,
 * headless browsers, and known AI/automation extensions.
 *
 * @module     local_agentdetect/fingerprint
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Comet agent extension ID (Perplexity's agentic browser).
 *
 * @type {string}
 */
const COMET_EXTENSION_ID = 'npclhjbddhklpbnacpjloidibaggcgon';

/**
 * Paths to probe within the Comet extension for resource detection.
 *
 * @type {Array<string>}
 */
const COMET_RESOURCE_PATHS = [
    'icon.png',
    'icons/icon128.png',
    'icons/icon48.png',
    'overlay.js',
];

/**
 * Initial webdriver state at module load time.
 * Used to detect mid-session debugger attachment.
 *
 * @type {boolean}
 */
const initialWebdriverState = navigator.webdriver;

/**
 * Known automation extension identifiers.
 *
 * Format: {id: 'extension-id', name: 'Human readable name', weight: score_weight}
 * Weight indicates how strong an indicator this extension is (1-10).
 *
 * @type {Array<{id: string, name: string, weight: number}>}
 */
const KNOWN_EXTENSIONS = [
    // AI Agents & Browser Automation.
    {id: COMET_EXTENSION_ID, name: 'Comet Agent (Perplexity)', weight: 10, pattern: /comet.*agent|perplexity/i},
    {id: 'claudeinchrome', name: 'Claude in Chrome (MCP)', weight: 10, pattern: /claude.*mcp|mcp.*claude/i},
    {id: 'anthropic', name: 'Anthropic Browser Agent', weight: 10, pattern: /anthropic/i},

    // Anti-detection / AI bypass tools.
    {id: 'undetectable', name: 'Undetectable.ai', weight: 9, pattern: /undetectable/i},
    {id: 'gofingerprint', name: 'GoLogin Fingerprint', weight: 8, pattern: /gologin/i},
    {id: 'multilogin', name: 'MultiLogin', weight: 8, pattern: /multilogin/i},

    // Browser automation.
    {id: 'selenium', name: 'Selenium IDE', weight: 10, pattern: /selenium/i},
    {id: 'puppeteer', name: 'Puppeteer Recorder', weight: 9, pattern: /puppeteer/i},
    {id: 'playwright', name: 'Playwright Inspector', weight: 9, pattern: /playwright/i},
    {id: 'imacros', name: 'iMacros', weight: 8, pattern: /imacros/i},
    {id: 'browserflow', name: 'Browserflow', weight: 8, pattern: /browserflow/i},
    {id: 'axiom', name: 'Axiom.ai', weight: 8, pattern: /axiom/i},
    {id: 'browseai', name: 'Browse AI', weight: 8, pattern: /browse.*ai/i},
    {id: 'bardeen', name: 'Bardeen', weight: 7, pattern: /bardeen/i},

    // Script injection.
    {id: 'tampermonkey', name: 'Tampermonkey', weight: 5, pattern: /tampermonkey/i},
    {id: 'greasemonkey', name: 'Greasemonkey', weight: 5, pattern: /greasemonkey/i},
    {id: 'violentmonkey', name: 'Violentmonkey', weight: 5, pattern: /violentmonkey/i},
];

/**
 * Global objects injected by automation tools.
 *
 * @type {Array<{name: string, weight: number}>}
 */
const AUTOMATION_GLOBALS = [
    {name: 'webdriver', weight: 10},
    {name: '__webdriver_evaluate', weight: 10},
    {name: '__selenium_evaluate', weight: 10},
    {name: '__webdriver_script_function', weight: 10},
    {name: '__webdriver_script_func', weight: 10},
    {name: '__webdriver_script_fn', weight: 10},
    {name: '__fxdriver_evaluate', weight: 10},
    {name: '__driver_unwrapped', weight: 10},
    {name: '__webdriver_unwrapped', weight: 10},
    {name: '__driver_evaluate', weight: 10},
    {name: '__selenium_unwrapped', weight: 10},
    {name: '__fxdriver_unwrapped', weight: 10},
    {name: '_phantom', weight: 9},
    {name: '__nightmare', weight: 9},
    {name: '_selenium', weight: 10},
    {name: 'callSelenium', weight: 10},
    {name: 'callPhantom', weight: 9},
    {name: '_Selenium_IDE_Recorder', weight: 10},
    {name: '__playwright', weight: 10},
    {name: '__puppeteer', weight: 10},
    {name: 'cdc_adoQpoasnfa76pfcZLmcfl_Array', weight: 10}, // Chrome DevTools Protocol marker.
    {name: 'cdc_adoQpoasnfa76pfcZLmcfl_Promise', weight: 10},
    {name: 'cdc_adoQpoasnfa76pfcZLmcfl_Symbol', weight: 10},
    {name: 'iMacros', weight: 8},
    {name: 'GM_info', weight: 5}, // Greasemonkey/Tampermonkey.
    {name: 'GM_getValue', weight: 5},
    {name: 'GM_setValue', weight: 5},
];

/**
 * DOM attributes that indicate automation tool injection.
 *
 * @type {Array<{selector: string, attribute: string, pattern: RegExp, name: string, weight: number}>}
 */
const DOM_MARKERS = [
    {selector: '[data-mcp]', attribute: 'data-mcp', pattern: /.+/, name: 'MCP data attribute', weight: 10},
    {selector: '[data-claude]', attribute: 'data-claude', pattern: /.+/, name: 'Claude data attribute', weight: 10},
    {selector: '[data-anthropic]', attribute: 'data-anthropic', pattern: /.+/, name: 'Anthropic marker', weight: 10},
    {selector: '[data-selenium]', attribute: 'data-selenium', pattern: /.+/, name: 'Selenium marker', weight: 10},
    {
        selector: '[data-testid]',
        attribute: 'data-testid',
        pattern: /^(selenium|cypress|playwright)/i,
        name: 'Test framework ID',
        weight: 7,
    },
    {selector: '[data-cy]', attribute: 'data-cy', pattern: /.+/, name: 'Cypress marker', weight: 7},
    {selector: '[data-automation]', attribute: 'data-automation', pattern: /.+/, name: 'Automation marker', weight: 8},
    {selector: '[data-puppeteer]', attribute: 'data-puppeteer', pattern: /.+/, name: 'Puppeteer marker', weight: 9},
];

/**
 * Collect all technical fingerprint signals.
 *
 * @returns {Promise<Object>} Fingerprint data with detected signals and scores.
 */
export const collect = async() => {
    const signals = {
        timestamp: Date.now(),
        webdriver: detectWebdriver(),
        headless: detectHeadless(),
        extensions: await detectExtensions(),
        cometExtension: await detectCometExtension(),
        perplexityNetwork: detectPerplexityNetwork(),
        globals: detectAutomationGlobals(),
        domMarkers: detectDomMarkers(),
        canvas: await collectCanvasFingerprint(),
        webgl: collectWebGLInfo(),
        navigator: collectNavigatorInfo(),
    };

    signals.score = calculateFingerprintScore(signals);

    return signals;
};

/**
 * Detect WebDriver flag in navigator.
 *
 * @returns {Object} WebDriver detection results.
 */
const detectWebdriver = () => {
    const results = {
        detected: false,
        signals: [],
    };

    // Standard webdriver property.
    if (navigator.webdriver === true) {
        results.detected = true;
        results.signals.push({name: 'navigator.webdriver', value: true, weight: 10});
    }

    // Check for webdriver state changing mid-session (debugger attachment).
    if (navigator.webdriver === true && initialWebdriverState === false) {
        results.detected = true;
        results.signals.push({name: 'webdriver.changed_mid_session', value: true, weight: 10});
    }

    // Check for webdriver in prototype chain (some tools hide it).
    try {
        const descriptor = Object.getOwnPropertyDescriptor(Navigator.prototype, 'webdriver');
        if (descriptor && descriptor.get) {
            const getter = descriptor.get.toString();
            if (getter.includes('native code') === false) {
                results.detected = true;
                results.signals.push({name: 'webdriver.getter.modified', value: true, weight: 9});
            }
        }
    } catch (e) {
        // Property access error could indicate tampering.
        results.signals.push({name: 'webdriver.access.error', value: e.message, weight: 3});
    }

    return results;
};

/**
 * Detect headless browser indicators.
 *
 * @returns {Object} Headless detection results.
 */
const detectHeadless = () => {
    const results = {
        detected: false,
        signals: [],
    };

    // Check for missing plugins (headless often has 0).
    if (navigator.plugins.length === 0) {
        results.signals.push({name: 'plugins.empty', value: true, weight: 6});
    }

    // Check for missing languages.
    if (!navigator.languages || navigator.languages.length === 0) {
        results.detected = true;
        results.signals.push({name: 'languages.empty', value: true, weight: 7});
    }

    // Chrome-specific: missing chrome object.
    if (window.chrome === undefined && /Chrome/.test(navigator.userAgent)) {
        results.detected = true;
        results.signals.push({name: 'chrome.missing', value: true, weight: 8});
    }

    // Check for headless in user agent.
    if (/HeadlessChrome|PhantomJS|SlimerJS/.test(navigator.userAgent)) {
        results.detected = true;
        results.signals.push({name: 'useragent.headless', value: true, weight: 10});
    }

    // Check window dimensions (headless often has unusual sizes).
    if (window.outerWidth === 0 || window.outerHeight === 0) {
        results.detected = true;
        results.signals.push({name: 'window.dimensions.zero', value: true, weight: 8});
    }

    // Check for missing screen properties.
    if (screen.availWidth === 0 || screen.availHeight === 0) {
        results.signals.push({name: 'screen.dimensions.zero', value: true, weight: 7});
    }

    // Connection type check (headless often missing).
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (connection === undefined && /Chrome/.test(navigator.userAgent)) {
        results.signals.push({name: 'connection.missing', value: true, weight: 4});
    }

    // Check for permission API behavior.
    if (navigator.permissions) {
        navigator.permissions.query({name: 'notifications'}).then((result) => {
            // Headless often returns 'denied' instantly without prompting.
            if (result.state === 'denied') {
                results.signals.push({name: 'permissions.notifications.denied', value: true, weight: 3});
            }
        }).catch(() => {
            // Ignore permission errors.
        });
    }

    results.detected = results.signals.some((s) => s.weight >= 7);

    return results;
};

/**
 * Detect known automation extensions.
 *
 * @returns {Promise<Object>} Extension detection results.
 */
const detectExtensions = async() => {
    const results = {
        detected: [],
        signals: [],
    };

    // Method 1: Check for extension-injected elements.
    for (const ext of KNOWN_EXTENSIONS) {
        // Look for elements with extension-specific classes or IDs.
        const elements = document.querySelectorAll(`[class*="${ext.id}"], [id*="${ext.id}"]`);
        if (elements.length > 0) {
            results.detected.push(ext.name);
            results.signals.push({
                name: `extension.dom.${ext.id}`,
                value: elements.length,
                weight: ext.weight,
            });
        }
    }

    // Method 2: Check for extension resource accessibility.
    // Extensions often expose resources at chrome-extension:// URLs.
    // Note: Direct probing disabled due to CORS restrictions.
    // Future: Could probe known extension resource URLs.

    // Method 3: Check for MCP-specific patterns.
    if (typeof window.__MCP_READY !== 'undefined' || document.querySelector('[data-mcp-session]')) {
        results.detected.push('MCP Protocol');
        results.signals.push({name: 'mcp.protocol.detected', value: true, weight: 10});
    }

    // Method 4: Check for Anthropic/Claude-specific globals or patterns.
    if (window.__CLAUDE_BROWSER_CONTEXT || window.__ANTHROPIC_SESSION) {
        results.detected.push('Claude Browser Context');
        results.signals.push({name: 'claude.context.detected', value: true, weight: 10});
    }

    // Method 5: Scan for injected stylesheets from extensions.
    const stylesheets = Array.from(document.styleSheets);
    for (const sheet of stylesheets) {
        try {
            if (sheet.href && sheet.href.startsWith('chrome-extension://')) {
                for (const ext of KNOWN_EXTENSIONS) {
                    if (ext.pattern.test(sheet.href)) {
                        results.detected.push(ext.name);
                        results.signals.push({
                            name: `extension.stylesheet.${ext.id}`,
                            value: sheet.href,
                            weight: ext.weight,
                        });
                    }
                }
            }
        } catch (e) {
            // Cross-origin stylesheet, can't inspect.
        }
    }

    return results;
};

/**
 * Detect automation-related global objects.
 *
 * @returns {Object} Global object detection results.
 */
const detectAutomationGlobals = () => {
    const results = {
        detected: [],
        signals: [],
    };

    for (const global of AUTOMATION_GLOBALS) {
        if (global.name in window) {
            results.detected.push(global.name);
            results.signals.push({
                name: `global.${global.name}`,
                value: true,
                weight: global.weight,
            });
        }
    }

    // Check document properties (including non-enumerable) for CDP artifacts.
    try {
        const docPropNames = Object.getOwnPropertyNames(document);
        for (const key of docPropNames) {
            if (/^(\$?cdc_|_cdc_|\$chrome_asyncScriptInfo)/.test(key)) {
                results.detected.push(key);
                results.signals.push({name: `document.cdp.${key}`, value: true, weight: 10});
            }
        }
    } catch (e) {
        // Some environments may restrict getOwnPropertyNames.
    }

    return results;
};

/**
 * Detect DOM markers injected by automation tools.
 *
 * @returns {Object} DOM marker detection results.
 */
const detectDomMarkers = () => {
    const results = {
        detected: [],
        signals: [],
    };

    for (const marker of DOM_MARKERS) {
        const elements = document.querySelectorAll(marker.selector);
        for (const el of elements) {
            const value = el.getAttribute(marker.attribute);
            if (marker.pattern.test(value)) {
                results.detected.push(marker.name);
                results.signals.push({
                    name: `dom.${marker.attribute}`,
                    value: value,
                    weight: marker.weight,
                });
            }
        }
    }

    return results;
};

/**
 * Detect Perplexity Comet agent extension presence.
 *
 * Probes for the comet-agent extension by attempting to load known resources
 * and scanning the DOM for extension artifacts.
 *
 * @returns {Promise<Object>} Comet extension detection results.
 */
const detectCometExtension = async() => {
    const results = {
        detected: false,
        isAgentic: false,
        signals: [],
    };

    // Check sessionStorage cache from prior page.
    try {
        if (sessionStorage.getItem('agentdetect_comet_detected') === 'true') {
            results.detected = true;
            results.signals.push({name: 'comet.extension.cached', value: true, weight: 10});
        }
    } catch (e) {
        // Ignore.
    }

    // Scan existing scripts and links for the extension ID.
    const scripts = document.querySelectorAll('script[src*="' + COMET_EXTENSION_ID + '"]');
    if (scripts.length > 0) {
        results.detected = true;
        results.isAgentic = true; // Script injection means agent is active.
        results.signals.push({
            name: 'comet.extension.script_injected',
            value: scripts[0].src,
            weight: 10,
        });
    }

    const links = document.querySelectorAll('link[href*="' + COMET_EXTENSION_ID + '"]');
    if (links.length > 0) {
        results.detected = true;
        results.signals.push({
            name: 'comet.extension.link_injected',
            value: links[0].href,
            weight: 10,
        });
    }

    // Scan stylesheets for the extension ID.
    try {
        for (const sheet of document.styleSheets) {
            if (sheet.href && sheet.href.includes(COMET_EXTENSION_ID)) {
                results.detected = true;
                results.signals.push({
                    name: 'comet.extension.stylesheet',
                    value: sheet.href,
                    weight: 10,
                });
            }
        }
    } catch (e) {
        // Cross-origin stylesheet.
    }

    // Probe extension resources via Image load.
    if (!results.detected) {
        const probeResult = await probeExtensionResource();
        if (probeResult) {
            results.detected = true;
            results.signals.push({
                name: 'comet.extension.resource_probe',
                value: probeResult,
                weight: 10,
            });
        }
    }

    // Cache detection result for subsequent pages.
    if (results.detected) {
        try {
            sessionStorage.setItem('agentdetect_comet_detected', 'true');
        } catch (e) {
            // Ignore.
        }
    }

    return results;
};

/**
 * Attempt to load a resource from the Comet extension.
 *
 * @returns {Promise<string|null>} Path that loaded successfully, or null.
 */
const probeExtensionResource = () => {
    return new Promise((resolve) => {
        const baseUrl = `chrome-extension://${COMET_EXTENSION_ID}/`;
        let resolved = false;
        let remaining = COMET_RESOURCE_PATHS.length;

        const done = (path) => {
            if (!resolved) {
                resolved = true;
                resolve(path);
            }
        };

        for (const path of COMET_RESOURCE_PATHS) {
            const img = new Image();
            img.onload = () => {
                remaining--;
                done(path);
            };
            img.onerror = () => {
                remaining--;
                if (remaining === 0 && !resolved) {
                    done(null);
                }
            };
            img.src = baseUrl + path;
        }

        // Timeout after 1 second.
        setTimeout(() => {
            done(null);
        }, 1000);
    });
};

/**
 * Detect network connections to Perplexity AI agent infrastructure.
 *
 * @returns {Object} Network detection results.
 */
const detectPerplexityNetwork = () => {
    const results = {
        detected: false,
        signals: [],
    };

    try {
        const entries = performance.getEntriesByType('resource');
        for (const entry of entries) {
            if (/perplexity\.ai\/(agent|rest\/sse)/i.test(entry.name)) {
                results.detected = true;
                results.signals.push({
                    name: 'network.perplexity_agent',
                    value: entry.name,
                    weight: 9,
                });
            }
        }
    } catch (e) {
        // PerformanceObserver not available.
    }

    return results;
};

/**
 * Collect canvas fingerprint for headless detection.
 *
 * Headless browsers often have different canvas rendering.
 *
 * @returns {Promise<Object>} Canvas fingerprint data.
 */
const collectCanvasFingerprint = async() => {
    const results = {
        hash: null,
        anomalies: [],
    };

    try {
        const canvas = document.createElement('canvas');
        canvas.width = 200;
        canvas.height = 50;
        const ctx = canvas.getContext('2d');

        // Draw complex shapes that reveal rendering differences.
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillStyle = '#f60';
        ctx.fillRect(125, 1, 62, 20);
        ctx.fillStyle = '#069';
        ctx.fillText('Agent Detection Test', 2, 15);
        ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
        ctx.fillText('Canvas Fingerprint', 4, 17);

        const dataUrl = canvas.toDataURL();
        results.hash = await hashString(dataUrl);

        // Check for known headless canvas signatures.
        if (dataUrl.length < 1000) {
            results.anomalies.push({name: 'canvas.data.short', weight: 6});
        }

    } catch (e) {
        results.anomalies.push({name: 'canvas.error', value: e.message, weight: 5});
    }

    return results;
};

/**
 * Collect WebGL renderer information.
 *
 * Headless browsers often report "SwiftShader" or unusual renderers.
 *
 * @returns {Object} WebGL information.
 */
const collectWebGLInfo = () => {
    const results = {
        vendor: null,
        renderer: null,
        anomalies: [],
    };

    try {
        const canvas = document.createElement('canvas');
        const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');

        if (gl) {
            const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                results.vendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL);
                results.renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL);

                // Check for headless indicators.
                const headlessRenderers = ['SwiftShader', 'llvmpipe', 'Mesa', 'Software'];
                for (const hr of headlessRenderers) {
                    if (results.renderer && results.renderer.includes(hr)) {
                        results.anomalies.push({
                            name: `webgl.renderer.${hr.toLowerCase()}`,
                            value: results.renderer,
                            weight: 8,
                        });
                    }
                }
            }
        } else {
            results.anomalies.push({name: 'webgl.unavailable', weight: 5});
        }
    } catch (e) {
        results.anomalies.push({name: 'webgl.error', value: e.message, weight: 3});
    }

    return results;
};

/**
 * Collect navigator information for fingerprinting.
 *
 * @returns {Object} Navigator properties.
 */
const collectNavigatorInfo = () => {
    return {
        userAgent: navigator.userAgent,
        platform: navigator.platform,
        hardwareConcurrency: navigator.hardwareConcurrency,
        deviceMemory: navigator.deviceMemory,
        maxTouchPoints: navigator.maxTouchPoints,
        languages: navigator.languages ? [...navigator.languages] : [],
        cookieEnabled: navigator.cookieEnabled,
        doNotTrack: navigator.doNotTrack,
        pluginCount: navigator.plugins.length,
    };
};

/**
 * Calculate overall fingerprint score.
 *
 * @param {Object} signals Collected signals.
 * @returns {number} Score from 0-100 (higher = more likely automated).
 */
const calculateFingerprintScore = (signals) => {
    let totalWeight = 0;
    let maxWeight = 0;

    // Aggregate all signal weights.
    const allSignals = [
        ...(signals.webdriver.signals || []),
        ...(signals.headless.signals || []),
        ...(signals.extensions.signals || []),
        ...(signals.cometExtension?.signals || []),
        ...(signals.perplexityNetwork?.signals || []),
        ...(signals.globals.signals || []),
        ...(signals.domMarkers.signals || []),
        ...(signals.canvas.anomalies || []),
        ...(signals.webgl.anomalies || []),
    ];

    for (const signal of allSignals) {
        totalWeight += signal.weight || 0;
        maxWeight += 10; // Max possible weight per signal.
    }

    // Normalize to 0-100 scale.
    if (maxWeight === 0) {
        return 0;
    }

    // Use sigmoid-like scaling to emphasize high-confidence signals.
    const rawScore = (totalWeight / Math.max(maxWeight, 50)) * 100;
    return Math.min(100, Math.round(rawScore));
};

/**
 * Simple hash function for fingerprint comparison.
 *
 * @param {string} str String to hash.
 * @returns {Promise<string>} Hex hash string.
 */
const hashString = async(str) => {
    const encoder = new TextEncoder();
    const data = encoder.encode(str);
    const hashBuffer = await crypto.subtle.digest('SHA-256', data);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
};

export default {
    collect,
    KNOWN_EXTENSIONS,
    AUTOMATION_GLOBALS,
};
