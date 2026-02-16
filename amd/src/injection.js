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
 * Injection detection module.
 *
 * Uses MutationObserver to detect elements injected by browser extensions,
 * particularly AI helper tools, homework solvers, and answer providers.
 *
 * This catches human-driven AI assistance where students use extension UI
 * (sidebars, "solve" buttons, floating helpers) rather than automated agents.
 *
 * @module     local_agentdetect/injection
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Log from 'core/log';

/**
 * Suspicious text patterns that indicate AI/homework helper UI.
 * These are commonly found in extension-injected buttons and panels.
 *
 * @type {Array<{pattern: RegExp, name: string, weight: number}>}
 */
const SUSPICIOUS_TEXT_PATTERNS = [
    // Direct answer/solve buttons - must be specific to avoid Moodle's own UI.
    // Avoid generic "answer" or "solve" which appear in quiz feedback.
    {pattern: /\b(get\s+answer|show\s+answer|reveal\s+answer|find\s+answer)\b/i, name: 'get_answer', weight: 9},
    {pattern: /\b(solve\s+this|solve\s+for\s+me|auto\s*solve)\b/i, name: 'solve_button', weight: 8},
    {pattern: /\b(step.by.step\s+solution)\b/i, name: 'explain_button', weight: 6},

    // AI assistant triggers - specific phrases.
    {pattern: /\b(ask\s+(ai|gpt|chatgpt|claude|copilot|gemini|bard))\b/i, name: 'ask_ai', weight: 9},
    {pattern: /\b(ai\s+(help|assist|tutor|helper))\b/i, name: 'ai_help', weight: 8},
    {pattern: /\b(generate\s+answer|write\s+for\s+me|ai\s+write)\b/i, name: 'generate', weight: 7},

    // Known cheating services - brand names.
    {pattern: /\b(chegg|course\s*hero|brainly|bartleby|studocu)\b/i, name: 'known_service', weight: 10},
    {pattern: /\b(symbolab|mathway|photomath|wolfram\s*alpha)\b/i, name: 'math_solver', weight: 8},
    {pattern: /\b(quillbot|scribbr)\b/i, name: 'writing_helper', weight: 5},
];

/**
 * Suspicious element patterns (classes, IDs, attributes).
 *
 * @type {Array<{pattern: RegExp, attribute: string, name: string, weight: number}>}
 */
const SUSPICIOUS_ELEMENT_PATTERNS = [
    // Common AI extension patterns - must start with these to avoid false positives.
    {pattern: /^chatgpt/i, attribute: 'class', name: 'chatgpt_class', weight: 9},
    {pattern: /^chatgpt/i, attribute: 'id', name: 'chatgpt_id', weight: 9},
    {pattern: /^openai/i, attribute: 'class', name: 'openai_class', weight: 9},
    {pattern: /^openai/i, attribute: 'id', name: 'openai_id', weight: 9},
    {pattern: /^gpt-/i, attribute: 'class', name: 'gpt_class', weight: 9},
    {pattern: /^claude-/i, attribute: 'class', name: 'claude_class', weight: 9},
    {pattern: /^gemini-/i, attribute: 'class', name: 'gemini_class', weight: 9},

    // Extension-specific patterns - brand names only.
    {pattern: /^chegg/i, attribute: 'class', name: 'chegg', weight: 10},
    {pattern: /^chegg/i, attribute: 'id', name: 'chegg', weight: 10},
    {pattern: /^brainly/i, attribute: 'class', name: 'brainly', weight: 10},
    {pattern: /^brainly/i, attribute: 'id', name: 'brainly', weight: 10},
    {pattern: /^coursehero/i, attribute: 'class', name: 'coursehero', weight: 10},
    {pattern: /^quizlet/i, attribute: 'class', name: 'quizlet', weight: 8},
    {pattern: /^bartleby/i, attribute: 'class', name: 'bartleby', weight: 10},
    {pattern: /^studocu/i, attribute: 'class', name: 'studocu', weight: 10},

    // Perplexity Comet agent patterns.
    {pattern: /^comet/i, attribute: 'class', name: 'comet_class', weight: 9},
    {pattern: /^comet/i, attribute: 'id', name: 'comet_id', weight: 9},
    {pattern: /^perplexity/i, attribute: 'class', name: 'perplexity_class', weight: 9},
    {pattern: /^perplexity/i, attribute: 'id', name: 'perplexity_id', weight: 9},
    {pattern: /npclhjbddhklpbnacpjloidibaggcgon/, attribute: 'src', name: 'comet_agent_src', weight: 10},
    {pattern: /npclhjbddhklpbnacpjloidibaggcgon/, attribute: 'href', name: 'comet_agent_href', weight: 10},

    // Extension resource URLs - definitive signal.
    {pattern: /chrome-extension:\/\//i, attribute: 'src', name: 'chrome_ext_src', weight: 7},
    {pattern: /moz-extension:\/\//i, attribute: 'src', name: 'moz_ext_src', weight: 7},
];

/**
 * Elements that look like injected floating UI (fixed/absolute positioned overlays).
 *
 * @type {Object}
 */
const FLOATING_UI_INDICATORS = {
    positions: ['fixed', 'absolute'],
    minSize: 50, // Minimum width/height to consider
    maxZIndex: 9000, // High z-index suggests overlay
};

/**
 * Storage for detected injections.
 *
 * @type {Object}
 */
const detectionStore = {
    injectedElements: [],
    suspiciousText: [],
    floatingUI: [],
    chromeExtensionResources: [],
    startTime: Date.now(),
};

/**
 * MutationObserver instance.
 *
 * @type {MutationObserver|null}
 */
let observer = null;

/**
 * Whether monitoring is active.
 *
 * @type {boolean}
 */
let isMonitoring = false;

/**
 * Debug mode flag.
 *
 * @type {boolean}
 */
let debugMode = false;

/**
 * Start monitoring for injected content.
 *
 * @param {Object} options Configuration options.
 * @param {boolean} options.debug Enable debug logging.
 * @returns {void}
 */
export const startMonitoring = (options = {}) => {
    if (isMonitoring) {
        return;
    }

    debugMode = options.debug || false;
    isMonitoring = true;
    detectionStore.startTime = Date.now();

    // Scan existing DOM first.
    scanExistingDOM();

    // Set up MutationObserver for future changes.
    observer = new MutationObserver(handleMutations);
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'id', 'src', 'href', 'style', 'data-comet', 'data-perplexity'],
    });

    if (debugMode) {
        Log.debug('[AgentDetect/Injection] Monitoring started');
    }
};

/**
 * Stop monitoring for injected content.
 *
 * @returns {void}
 */
export const stopMonitoring = () => {
    if (!isMonitoring) {
        return;
    }

    if (observer) {
        observer.disconnect();
        observer = null;
    }

    isMonitoring = false;

    if (debugMode) {
        Log.debug('[AgentDetect/Injection] Monitoring stopped');
    }
};

/**
 * Scan the existing DOM for suspicious elements.
 * Called once at startup to catch elements already present.
 *
 * @returns {void}
 */
const scanExistingDOM = () => {
    // Scan all elements for suspicious patterns.
    const allElements = document.body.querySelectorAll('*');
    for (const element of allElements) {
        analyzeElement(element, 'initial_scan');
    }

    // Look for chrome-extension:// resources.
    scanExtensionResources();

    if (debugMode) {
        Log.debug('[AgentDetect/Injection] Initial scan complete', {
            injectedElements: detectionStore.injectedElements.length,
            suspiciousText: detectionStore.suspiciousText.length,
            floatingUI: detectionStore.floatingUI.length,
        });
    }
};

/**
 * Handle DOM mutations.
 *
 * @param {MutationRecord[]} mutations List of mutations.
 * @returns {void}
 */
const handleMutations = (mutations) => {
    for (const mutation of mutations) {
        if (mutation.type === 'childList') {
            // New nodes added.
            for (const node of mutation.addedNodes) {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    analyzeElement(node, 'mutation_added');

                    // Comet-specific: detect extension content script or resource injection.
                    const src = node.getAttribute ? (node.getAttribute('src') || '') : '';
                    const href = node.getAttribute ? (node.getAttribute('href') || '') : '';
                    const resourceUrl = src || href;

                    if (resourceUrl.includes('npclhjbddhklpbnacpjloidibaggcgon') ||
                        resourceUrl.includes('chrome-extension://')) {
                        const isComet = resourceUrl.includes('npclhjbddhklpbnacpjloidibaggcgon');
                        detectionStore.injectedElements.push({
                            timestamp: Date.now(),
                            source: isComet ? 'comet_extension_injection' : 'chrome_extension_injection',
                            tagName: node.tagName,
                            id: node.id || null,
                            className: node.className || null,
                            findings: [{
                                type: isComet ? 'comet_agentic' : 'extension',
                                name: isComet ? 'comet_overlay_js' : 'chrome_ext_injection',
                                value: resourceUrl,
                                weight: 10,
                            }],
                        });
                        if (debugMode) {
                            Log.debug('[AgentDetect/Injection] Extension resource injected',
                                node.tagName, resourceUrl);
                        }
                    }

                    // Check for elements with shadow roots (common for extension UI).
                    if (node.shadowRoot) {
                        detectionStore.injectedElements.push({
                            timestamp: Date.now(),
                            source: 'shadow_dom_injection',
                            tagName: node.tagName,
                            id: node.id || null,
                            className: node.className || null,
                            findings: [{
                                type: 'shadow_dom',
                                name: 'injected_shadow_root',
                                weight: 7,
                            }],
                        });
                    }

                    // Also scan children of added nodes.
                    const children = node.querySelectorAll ? node.querySelectorAll('*') : [];
                    for (const child of children) {
                        analyzeElement(child, 'mutation_added_child');
                    }
                }
            }
        } else if (mutation.type === 'attributes') {
            // Attribute changed.
            analyzeElement(mutation.target, 'mutation_attribute');
        }
    }
};

/**
 * Analyze a single element for suspicious patterns.
 *
 * @param {Element} element Element to analyze.
 * @param {string} source How this element was found.
 * @returns {void}
 */
const analyzeElement = (element, source) => {
    if (!element || !element.tagName) {
        return;
    }

    // Skip Moodle's own elements (basic heuristic).
    if (isMoodleElement(element)) {
        return;
    }

    const findings = [];

    // Check element attributes against suspicious patterns.
    for (const pattern of SUSPICIOUS_ELEMENT_PATTERNS) {
        const attrValue = element.getAttribute(pattern.attribute);
        if (attrValue && pattern.pattern.test(attrValue)) {
            findings.push({
                type: 'element_pattern',
                name: pattern.name,
                attribute: pattern.attribute,
                value: attrValue,
                weight: pattern.weight,
            });
        }
    }

    // Check text content for suspicious patterns.
    const textContent = element.textContent?.trim().substring(0, 200) || '';
    if (textContent && element.children.length === 0) { // Only leaf nodes
        for (const pattern of SUSPICIOUS_TEXT_PATTERNS) {
            if (pattern.pattern.test(textContent)) {
                findings.push({
                    type: 'text_pattern',
                    name: pattern.name,
                    text: textContent.substring(0, 50),
                    weight: pattern.weight,
                });
            }
        }
    }

    // Check for floating UI (fixed/absolute positioned overlays).
    const floatingInfo = checkFloatingUI(element);
    if (floatingInfo) {
        findings.push({
            type: 'floating_ui',
            name: 'injected_overlay',
            ...floatingInfo,
            weight: 6,
        });
    }

    // Check for shadow DOM (common in extension-injected UI).
    if (element.shadowRoot) {
        findings.push({
            type: 'shadow_dom',
            name: 'injected_shadow_root',
            weight: 7,
        });
    }

    // Store findings.
    if (findings.length > 0) {
        const detection = {
            timestamp: Date.now(),
            source: source,
            tagName: element.tagName,
            id: element.id || null,
            className: element.className || null,
            findings: findings,
        };

        detectionStore.injectedElements.push(detection);

        if (debugMode) {
            Log.debug('[AgentDetect/Injection] Suspicious element detected', detection);
        }
    }
};

/**
 * Check if an element is a floating UI overlay (likely injected).
 *
 * @param {Element} element Element to check.
 * @returns {Object|null} Floating UI info or null.
 */
const checkFloatingUI = (element) => {
    const style = window.getComputedStyle(element);
    const position = style.position;

    if (!FLOATING_UI_INDICATORS.positions.includes(position)) {
        return null;
    }

    const rect = element.getBoundingClientRect();
    const zIndex = parseInt(style.zIndex, 10) || 0;

    // Must be visible and reasonably sized.
    if (rect.width < FLOATING_UI_INDICATORS.minSize ||
        rect.height < FLOATING_UI_INDICATORS.minSize) {
        return null;
    }

    // High z-index suggests overlay.
    if (zIndex >= FLOATING_UI_INDICATORS.maxZIndex) {
        return {
            position: position,
            zIndex: zIndex,
            width: rect.width,
            height: rect.height,
        };
    }

    return null;
};

/**
 * Basic check if element appears to be part of Moodle.
 *
 * @param {Element} element Element to check.
 * @returns {boolean} True if likely a Moodle element.
 */
const isMoodleElement = (element) => {
    const className = element.className?.toString() || '';
    const id = element.id || '';

    // Common Moodle/Bootstrap/TinyMCE class prefixes - these are NOT injected.
    const moodlePatterns = [
        // Moodle core.
        /^(mod-|block-|course-|quiz-|assign-|forum-|page-)/i,
        /^(moodle|mdl-|m-)/i,
        /^(nav|navbar|footer|header|drawer)/i,
        /^(activity|section|content)/i,
        /^(user|profile|grade)/i,

        // Bootstrap (used by Moodle).
        /^(btn|alert|card|modal|dropdown|collapse|form-|input-|list-)/i,
        /^(container|row|col-|d-|p-|m-|text-|bg-|border-)/i,

        // Font Awesome icons.
        /^(fa-|fa |fas |far |fab |icon)/i,

        // TinyMCE editor (Moodle's text editor) - NOT an AI sidebar!
        /^(tox-|tox |mce-|tiny)/i,

        // Moodle question/quiz UI.
        /^(que|qn|answer|formulation|outcome|feedback|rightanswer)/i,
        /^(submitbtns|im-controls)/i,

        // Atto editor.
        /^(atto|editor_atto)/i,

        // YUI (legacy Moodle).
        /^(yui|yui3)/i,
    ];

    for (const pattern of moodlePatterns) {
        if (pattern.test(className) || pattern.test(id)) {
            return true;
        }
    }

    // Check for Moodle data attributes.
    if (element.hasAttribute('data-region') ||
        element.hasAttribute('data-action') ||
        element.hasAttribute('data-for') ||
        element.hasAttribute('data-contextid')) {
        return true;
    }

    return false;
};

/**
 * Scan for chrome-extension:// or moz-extension:// resources.
 *
 * @returns {void}
 */
const scanExtensionResources = () => {
    // Check images.
    const images = document.querySelectorAll('img[src^="chrome-extension://"], img[src^="moz-extension://"]');
    for (const img of images) {
        detectionStore.chromeExtensionResources.push({
            type: 'image',
            src: img.src,
            timestamp: Date.now(),
        });
    }

    // Check iframes.
    const iframes = document.querySelectorAll(
        'iframe[src^="chrome-extension://"], iframe[src^="moz-extension://"]'
    );
    for (const iframe of iframes) {
        detectionStore.chromeExtensionResources.push({
            type: 'iframe',
            src: iframe.src,
            timestamp: Date.now(),
        });
    }

    // Check stylesheets.
    for (const sheet of document.styleSheets) {
        try {
            if (sheet.href &&
                (sheet.href.startsWith('chrome-extension://') || sheet.href.startsWith('moz-extension://'))) {
                detectionStore.chromeExtensionResources.push({
                    type: 'stylesheet',
                    src: sheet.href,
                    timestamp: Date.now(),
                });
            }
        } catch (e) {
            // Cross-origin stylesheet, can't inspect.
        }
    }
};

/**
 * Analyze collected injection data and return results.
 *
 * @returns {Object} Analysis results with score and signals.
 */
export const analyze = () => {
    const results = {
        timestamp: Date.now(),
        duration: Date.now() - detectionStore.startTime,
        detectionCounts: {
            injectedElements: detectionStore.injectedElements.length,
            extensionResources: detectionStore.chromeExtensionResources.length,
        },
        signals: [],
        score: 0,
    };

    // Aggregate signals from detected elements.
    const signalMap = new Map();

    for (const detection of detectionStore.injectedElements) {
        for (const finding of detection.findings) {
            const key = `${finding.type}.${finding.name}`;
            if (!signalMap.has(key)) {
                signalMap.set(key, {
                    name: key,
                    count: 0,
                    maxWeight: finding.weight,
                    examples: [],
                });
            }
            const signal = signalMap.get(key);
            signal.count++;
            signal.maxWeight = Math.max(signal.maxWeight, finding.weight);
            if (signal.examples.length < 3) {
                signal.examples.push(finding.text || finding.value || detection.tagName);
            }
        }
    }

    // Add extension resource signals.
    if (detectionStore.chromeExtensionResources.length > 0) {
        signalMap.set('extension.resources', {
            name: 'extension.resources',
            count: detectionStore.chromeExtensionResources.length,
            maxWeight: 7,
            examples: detectionStore.chromeExtensionResources.slice(0, 3).map((r) => r.type),
        });
    }

    // Convert to array and calculate score.
    results.signals = Array.from(signalMap.values());
    results.score = calculateInjectionScore(results.signals);

    return results;
};

/**
 * Calculate injection detection score.
 *
 * @param {Array} signals Detected signals.
 * @returns {number} Score from 0-100.
 */
const calculateInjectionScore = (signals) => {
    if (signals.length === 0) {
        return 0;
    }

    let totalWeight = 0;

    for (const signal of signals) {
        // Weight increases with count but with diminishing returns.
        const countMultiplier = Math.min(signal.count, 5);
        totalWeight += signal.maxWeight * (1 + (countMultiplier - 1) * 0.2);
    }

    // Normalize to 0-100 scale.
    // Max reasonable score would be ~5 signals at weight 10 = 50.
    const normalized = (totalWeight / 50) * 100;
    return Math.min(100, Math.round(normalized));
};

/**
 * Get raw detection data for debugging.
 *
 * @returns {Object} Detection store data.
 */
export const getRawData = () => {
    return {
        ...detectionStore,
        isMonitoring,
    };
};

/**
 * Reset all collected data.
 *
 * @returns {void}
 */
export const reset = () => {
    detectionStore.injectedElements = [];
    detectionStore.suspiciousText = [];
    detectionStore.floatingUI = [];
    detectionStore.chromeExtensionResources = [];
    detectionStore.startTime = Date.now();
};

export default {
    startMonitoring,
    stopMonitoring,
    analyze,
    getRawData,
    reset,
};
