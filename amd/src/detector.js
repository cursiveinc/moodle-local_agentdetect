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
 * Main agent detection module.
 *
 * Orchestrates fingerprint and interaction detection, combines results,
 * and reports to the Moodle backend.
 *
 * @module     local_agentdetect/detector
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Fingerprint from 'local_agentdetect/fingerprint';
import * as Interaction from 'local_agentdetect/interaction';
import * as Injection from 'local_agentdetect/injection';
import Ajax from 'core/ajax';
import Log from 'core/log';

/**
 * Configuration passed from PHP.
 *
 * @type {Object}
 */
let config = {
    enabled: true,
    reportInterval: 30000, // Ms - how often to report to server.
    minReportScore: 10, // Minimum combined score to report.
    contextId: null,
    sessionKey: null,
    debug: false,
};

/**
 * Report timer reference.
 *
 * @type {number|null}
 */
let reportTimer = null;

/**
 * Session ID for this detection session.
 * Persisted in sessionStorage to survive page navigations within a quiz.
 *
 * @type {string}
 */
let sessionId = null;

/**
 * Maximum age (ms) for a reusable session ID from sessionStorage.
 *
 * @type {number}
 */
const SESSION_MAX_AGE = 30 * 60 * 1000; // 30 minutes.

/**
 * Whether detection has been initialized.
 *
 * @type {boolean}
 */
let initialized = false;

/**
 * Initialize the agent detection system.
 *
 * @param {Object} options Configuration options from PHP.
 * @returns {Promise<void>}
 */
export const init = async(options = {}) => {
    if (initialized) {
        Log.debug('[AgentDetect] Already initialized');
        return;
    }

    // Merge config.
    config = {...config, ...options};

    if (!config.enabled) {
        Log.debug('[AgentDetect] Detection disabled');
        return;
    }

    // Restore or generate session ID (persists across page loads within same tab).
    sessionId = restoreOrCreateSessionId();

    Log.debug('[AgentDetect] Initializing detection', {sessionId, config});

    // Start interaction monitoring with context for cross-page accumulation.
    Interaction.startMonitoring({contextId: config.contextId});

    // Start injection detection (watches for AI helper UI injected by extensions).
    Injection.startMonitoring({debug: config.debug});

    // Collect initial fingerprint.
    const initialFingerprint = await Fingerprint.collect();

    if (config.debug) {
        Log.debug('[AgentDetect] Initial fingerprint:', initialFingerprint);
    }

    // If fingerprint score is high, report immediately.
    if (initialFingerprint.score >= config.minReportScore) {
        await reportSignals({
            type: 'fingerprint',
            data: initialFingerprint,
        });
    }

    // Start periodic reporting.
    startPeriodicReporting();

    // Report on page unload.
    window.addEventListener('beforeunload', handlePageUnload);

    // Report on visibility change (tab switching).
    document.addEventListener('visibilitychange', handleVisibilityChange);

    initialized = true;
    Log.debug('[AgentDetect] Initialization complete');
};

/**
 * Generate a unique session ID.
 *
 * @returns {string} Session ID.
 */
const generateSessionId = () => {
    const timestamp = Date.now().toString(36);
    const random = Math.random().toString(36).substring(2, 10);
    return `${timestamp}-${random}`;
};

/**
 * Restore session ID from sessionStorage or create a new one.
 * This allows the same session to span multiple page loads within a quiz.
 *
 * @returns {string} Session ID.
 */
const restoreOrCreateSessionId = () => {
    const storageKey = 'agentdetect_session';
    try {
        const stored = sessionStorage.getItem(storageKey);
        if (stored) {
            const parsed = JSON.parse(stored);
            const age = Date.now() - (parsed.timestamp || 0);
            if (age < SESSION_MAX_AGE && parsed.id) {
                Log.debug('[AgentDetect] Restored session ID from prior page', parsed.id);
                return parsed.id;
            }
        }
    } catch (e) {
        // SessionStorage unavailable or corrupt — generate fresh.
    }

    const newId = generateSessionId();
    try {
        sessionStorage.setItem(storageKey, JSON.stringify({id: newId, timestamp: Date.now()}));
    } catch (e) {
        // Ignore storage errors.
    }
    return newId;
};

/**
 * Start periodic reporting to the server.
 *
 * @returns {void}
 */
const startPeriodicReporting = () => {
    if (reportTimer) {
        clearInterval(reportTimer);
    }

    reportTimer = setInterval(async() => {
        await collectAndReport();
    }, config.reportInterval);
};

/**
 * Stop periodic reporting.
 *
 * @returns {void}
 */
const stopPeriodicReporting = () => {
    if (reportTimer) {
        clearInterval(reportTimer);
        reportTimer = null;
    }
};

/**
 * Collect all signals and report to server.
 *
 * @returns {Promise<Object>} Combined analysis results.
 */
export const collectAndReport = async() => {
    // Collect fingerprint.
    const fingerprint = await Fingerprint.collect();

    // Analyze interactions.
    const interaction = Interaction.analyze();

    // Analyze injected content (AI helper extensions).
    const injection = Injection.analyze();

    // Extract Comet agentic mode signals from all sub-modules.
    const comet = extractCometSignals(fingerprint, interaction, injection);

    // Combine scores.
    const combinedScore = calculateCombinedScore(fingerprint.score, interaction.score, injection.score, comet.score);

    const result = {
        sessionId,
        timestamp: Date.now(),
        pageUrl: window.location.href,
        pageTitle: document.title,
        fingerprint,
        interaction,
        injection,
        comet,
        combinedScore,
        verdict: getVerdict(combinedScore),
        detectedAgent: comet.detected ? 'comet_agentic' : null,
    };

    if (config.debug) {
        Log.debug('[AgentDetect] Analysis result:', result);
    }

    // Only report if score meets threshold.
    if (combinedScore >= config.minReportScore) {
        await reportSignals({
            type: 'combined',
            data: result,
        });
    }

    return result;
};

/**
 * Calculate combined score from fingerprint, interaction, injection, and Comet signals.
 *
 * Interaction score is the primary signal (catches human-driven AI tool usage).
 * Injection score adds evidence of AI helper extensions being present.
 * Fingerprint score is a bonus when detected (catches automated headless browsers).
 * Comet score is a definitive category for Perplexity Comet agentic mode.
 *
 * @param {number} fingerprintScore Fingerprint score (0-100).
 * @param {number} interactionScore Interaction score (0-100).
 * @param {number} injectionScore Injection detection score (0-100).
 * @param {number} cometScore Comet agentic mode score (0-100).
 * @returns {number} Combined score (0-100).
 */
const calculateCombinedScore = (fingerprintScore, interactionScore, injectionScore = 0, cometScore = 0) => {
    // Interaction is the base score - it catches both:
    // 1. Automated agents (teleport clicks, superhuman speed)
    // 2. Human-driven AI usage (tab switches, copy-paste patterns, pauses)
    let score = interactionScore;

    // Injection detection adds to the score - catches AI helper extensions.
    // This is direct evidence of tools being present on the page.
    if (injectionScore >= 50) {
        // Strong injection signals - significant boost.
        score = Math.min(100, score + 25);
    } else if (injectionScore >= 25) {
        // Moderate injection signals - modest boost.
        score = Math.min(100, score + 15);
    } else if (injectionScore >= 10) {
        // Weak injection signals - small boost.
        score = Math.min(100, score + 5);
    }

    // Fingerprint is a bonus - only helps when it detects something.
    // This catches headless browsers, automation tools with webdriver flag, etc.
    // Modern extension-based AI tools won't trigger fingerprint, and that's OK.
    if (fingerprintScore >= 70) {
        // Strong automation fingerprint - significant boost.
        score = Math.min(100, score + 30);
    } else if (fingerprintScore >= 40) {
        // Moderate fingerprint signals - modest boost.
        score = Math.min(100, score + 15);
    } else if (fingerprintScore >= 20) {
        // Weak fingerprint signals - small boost.
        score = Math.min(100, score + 5);
    }
    // If fingerprint is 0-19, no adjustment - interaction score stands alone.

    // Comet agentic mode signals — definitive category.
    // With tier-aware scoring, only real agents reach cometScore >= 70.
    if (cometScore >= 70) {
        // Strong Comet agentic evidence — ensure HIGH_CONFIDENCE.
        score = Math.max(score, 80);
        score = Math.min(100, score + 10);
    } else if (cometScore >= 40) {
        // Moderate Comet signals — modest boost.
        score = Math.min(100, score + 15);
    } else if (cometScore >= 20) {
        // Weak Comet signals — small boost.
        score = Math.min(100, score + 5);
    }

    return Math.round(score);
};

/**
 * Extract Comet-specific signals from all detection sub-modules.
 *
 * @param {Object} fingerprint Fingerprint results.
 * @param {Object} interaction Interaction analysis results.
 * @param {Object} injection Injection analysis results.
 * @returns {Object} Comet detection summary with signals and score.
 */
const extractCometSignals = (fingerprint, interaction, injection) => {
    const signals = [];

    // From fingerprint: Comet extension probing, runtime artifacts, and webdriver change.
    if (fingerprint.cometExtension) {
        signals.push(...(fingerprint.cometExtension.signals || []));
    }
    if (fingerprint.cometRuntime) {
        signals.push(...(fingerprint.cometRuntime.signals || []));
    }
    if (fingerprint.perplexityNetwork) {
        signals.push(...(fingerprint.perplexityNetwork.signals || []));
    }
    // Webdriver mid-session change (debugger attachment).
    const webdriverChange = (fingerprint.webdriver?.signals || []).find(
        (s) => s.name === 'webdriver.changed_mid_session'
    );
    if (webdriverChange) {
        signals.push(webdriverChange);
    }

    // From interaction: comet-prefixed anomalies.
    const cometAnomalies = (interaction.anomalies || []).filter(
        (a) => a.name.startsWith('comet.')
    );
    signals.push(...cometAnomalies);

    // From injection: Comet-specific findings.
    const cometInjections = (injection.signals || []).filter(
        (s) => s.name.includes('comet') || s.name.includes('perplexity') ||
               s.name.includes('npclhjbddhklpbnacpjloidibaggcgon')
    );
    signals.push(...cometInjections);

    return {
        detected: signals.length > 0,
        signalCount: signals.length,
        signals,
        score: calculateCometScore(signals),
    };
};

/**
 * Calculate Comet agentic mode score from extracted signals.
 *
 * Uses a tiered approach: Tier 1 (physically-impossible) signals compound
 * with Tier 2 (temporal/behavioral) signals. Temporal-only signals are
 * capped to prevent false positives from normal quiz-taking behavior.
 *
 * @param {Array} signals Comet-specific signals.
 * @returns {number} Score from 0-100.
 */
const calculateCometScore = (signals) => {
    if (signals.length === 0) {
        return 0;
    }

    const totalWeight = signals.reduce((sum, s) => sum + (s.weight || s.maxWeight || 0), 0);

    // Definitive signals = immediate high score (extension/runtime detection).
    const hasDefinitiveSignal = signals.some((s) =>
        s.name === 'comet_overlay_js' ||
        s.name === 'comet.extension.script_injected' ||
        s.name === 'comet.extension.resource_probe' ||
        s.name === 'comet_agent_src' ||
        s.name === 'network.perplexity_agent' ||
        s.name.startsWith('comet.runtime.')
    );

    if (hasDefinitiveSignal) {
        return Math.min(100, 70 + totalWeight);
    }

    // Tier 1: physically-impossible signals (low false-positive risk).
    // These indicate behaviour a human physically cannot produce.
    const TIER1_WEIGHTED = [
        'comet.ultra_precise_center', // Clicks land within 2px of center.
        'comet.low_mouse_to_action_ratio', // Only the extreme variant (weight 10, movePerClick < 2).
        'comet.low_per_page_mouse_ratio', // Per-page ratio consistently agent-like.
    ];
    const TIER1_ANY = [
        'comet.zero_keystrokes', // Zero keystrokes across entire quiz.
    ];
    const tier1 = signals.filter((s) =>
        (TIER1_WEIGHTED.includes(s.name) && s.weight >= 10) ||
        TIER1_ANY.includes(s.name)
    );

    // Tier 2: temporal/behavioral signals (higher false-positive risk).
    // Action bursts, read-then-act, focus sequences — humans trigger these during quizzes.
    const tier2 = signals.filter((s) =>
        s.name.startsWith('comet.') && !tier1.includes(s)
    );

    // Compounding requires at least 1 Tier 1 signal.
    if (tier1.length >= 1 && tier2.length >= 2) {
        // Strong fingerprint + behavioral confirmation.
        return Math.min(100, totalWeight * 2);
    }

    if (tier1.length >= 1) {
        // Tier 1 alone — moderate boost.
        return Math.min(100, Math.round(totalWeight * 1.5));
    }

    // Temporal-only signals — cap the score to prevent false positives.
    return Math.min(40, totalWeight);
};

/**
 * Get human-readable verdict from score.
 *
 * @param {number} score Combined score.
 * @returns {string} Verdict string.
 */
const getVerdict = (score) => {
    if (score >= 80) {
        return 'HIGH_CONFIDENCE_AGENT';
    } else if (score >= 60) {
        return 'PROBABLE_AGENT';
    } else if (score >= 40) {
        return 'SUSPICIOUS';
    } else if (score >= 20) {
        return 'LOW_SUSPICION';
    }
    return 'LIKELY_HUMAN';
};

/**
 * Report signals to the Moodle backend.
 *
 * @param {Object} payload Signal data to report.
 * @returns {Promise<void>}
 */
const reportSignals = async(payload) => {
    if (!config.sessionKey) {
        Log.warn('[AgentDetect] No session key configured, skipping report');
        return;
    }

    try {
        const response = await Ajax.call([{
            methodname: 'local_agentdetect_report_signals',
            args: {
                sesskey: config.sessionKey,
                contextid: config.contextId,
                sessionid: sessionId,
                signaltype: payload.type,
                signaldata: JSON.stringify(payload.data),
            },
        }])[0];

        if (config.debug) {
            Log.debug('[AgentDetect] Report response:', response);
        }
    } catch (error) {
        Log.error('[AgentDetect] Failed to report signals:', error);
    }
};

/**
 * Handle page unload - save state for cross-page continuity and send final report.
 *
 * @returns {void}
 */
const handlePageUnload = () => {
    // Save interaction events to sessionStorage for cross-page accumulation.
    Interaction.saveToSessionStorage();

    // Use sendBeacon for reliable delivery during unload.
    if (navigator.sendBeacon && config.sessionKey) {
        const interaction = Interaction.analyze();
        const payload = {
            sesskey: config.sessionKey,
            contextid: config.contextId,
            sessionid: sessionId,
            signaltype: 'unload',
            signaldata: JSON.stringify({
                pageUrl: window.location.href,
                pageTitle: document.title,
                interaction,
                duration: Date.now() - (Interaction.getRawData().startTime || Date.now()),
            }),
        };

        const url = M.cfg.wwwroot + '/local/agentdetect/beacon.php';
        navigator.sendBeacon(url, JSON.stringify(payload));
    }
};

/**
 * Handle visibility change - report when tab loses focus.
 *
 * @returns {void}
 */
const handleVisibilityChange = async() => {
    if (document.visibilityState === 'hidden') {
        // Tab lost focus - good time to report.
        await collectAndReport();
    }
};

/**
 * Manually trigger detection analysis.
 *
 * Useful for testing or on-demand checks.
 *
 * @returns {Promise<Object>} Analysis results.
 */
export const runAnalysis = async() => {
    return await collectAndReport();
};

/**
 * Get current detection status.
 *
 * @returns {Object} Status information.
 */
export const getStatus = () => {
    return {
        initialized,
        sessionId,
        isMonitoring: Interaction.getRawData().isMonitoring,
        config: {
            enabled: config.enabled,
            reportInterval: config.reportInterval,
            minReportScore: config.minReportScore,
        },
    };
};

/**
 * Shutdown detection and cleanup.
 *
 * @returns {void}
 */
export const shutdown = () => {
    stopPeriodicReporting();
    Interaction.stopMonitoring();
    Injection.stopMonitoring();
    window.removeEventListener('beforeunload', handlePageUnload);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    initialized = false;
    Log.debug('[AgentDetect] Shutdown complete');
};

export default {
    init,
    runAnalysis,
    getStatus,
    shutdown,
    collectAndReport,
};
