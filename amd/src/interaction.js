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
 * Interaction anomaly detection module.
 *
 * Monitors user interactions (mouse, keyboard, scroll) to detect
 * patterns typical of automated browsers versus human users.
 *
 * @module     local_agentdetect/interaction
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Configuration for interaction monitoring.
 *
 * @type {Object}
 */
const CONFIG = {
    // Minimum data points before analysis.
    minMouseMoves: 20,
    minClicks: 3,
    minKeystrokes: 10,

    // Thresholds for anomaly detection.
    perfectTimingVariance: 5, // ms - variance below this is suspicious.
    minHumanReactionTime: 50, // ms - clicks faster than this are suspicious.
    maxMouseSpeed: 10000, // px/ms - movements faster than this are suspicious.
    centerClickTolerance: 5, // px - clicks within this of element center are suspicious.

    // Sampling configuration.
    maxStoredEvents: 500,
    analysisInterval: 10000, // ms - how often to run analysis.
};

/**
 * Event storage for analysis.
 *
 * @type {Object}
 */
const eventStore = {
    mouseMoves: [],
    clicks: [],
    keystrokes: [],
    scrolls: [],
    hovers: [],
    focusChanges: [],
    pointerEvents: [],
    startTime: Date.now(),
    pageLoadCount: 1,
};

/**
 * Context ID for sessionStorage scoping (set in startMonitoring).
 *
 * @type {number|null}
 */
let contextId = null;

/**
 * Analysis results cache.
 *
 * @type {Object|null}
 */
let analysisCache = null;

/**
 * Whether monitoring is active.
 *
 * @type {boolean}
 */
let isMonitoring = false;

/**
 * Start monitoring user interactions.
 *
 * @param {Object} options Configuration options.
 * @param {number} options.contextId Context ID for sessionStorage scoping.
 * @returns {void}
 */
export const startMonitoring = (options = {}) => {
    if (isMonitoring) {
        return;
    }

    isMonitoring = true;
    contextId = options.contextId || null;
    eventStore.startTime = Date.now();

    // Restore accumulated events from prior pages in this session.
    loadFromSessionStorage();

    // Mouse movement tracking.
    document.addEventListener('mousemove', handleMouseMove, {passive: true});

    // Click tracking (capture phase to get all clicks).
    document.addEventListener('click', handleClick, {capture: true, passive: true});
    document.addEventListener('mousedown', handleMouseDown, {capture: true, passive: true});
    document.addEventListener('mouseup', handleMouseUp, {capture: true, passive: true});

    // Hover tracking.
    document.addEventListener('mouseover', handleMouseOver, {passive: true});
    document.addEventListener('mouseout', handleMouseOut, {passive: true});

    // Keyboard tracking.
    document.addEventListener('keydown', handleKeyDown, {capture: true, passive: true});
    document.addEventListener('keyup', handleKeyUp, {capture: true, passive: true});

    // Scroll tracking.
    document.addEventListener('scroll', handleScroll, {passive: true});
    window.addEventListener('scroll', handleScroll, {passive: true});

    // Focus tracking.
    document.addEventListener('focusin', handleFocusIn, {passive: true});
    document.addEventListener('focusout', handleFocusOut, {passive: true});

    // Pointer event tracking (for CDP dispatch detection).
    document.addEventListener('pointerdown', handlePointerDown, {capture: true, passive: true});
    document.addEventListener('pointermove', handlePointerMove, {passive: true});
};

/**
 * Stop monitoring user interactions.
 *
 * @returns {void}
 */
export const stopMonitoring = () => {
    if (!isMonitoring) {
        return;
    }

    isMonitoring = false;

    document.removeEventListener('mousemove', handleMouseMove);
    document.removeEventListener('click', handleClick, {capture: true});
    document.removeEventListener('mousedown', handleMouseDown, {capture: true});
    document.removeEventListener('mouseup', handleMouseUp, {capture: true});
    document.removeEventListener('mouseover', handleMouseOver);
    document.removeEventListener('mouseout', handleMouseOut);
    document.removeEventListener('keydown', handleKeyDown, {capture: true});
    document.removeEventListener('keyup', handleKeyUp, {capture: true});
    document.removeEventListener('scroll', handleScroll);
    window.removeEventListener('scroll', handleScroll);
    document.removeEventListener('focusin', handleFocusIn);
    document.removeEventListener('focusout', handleFocusOut);
    document.removeEventListener('pointerdown', handlePointerDown, {capture: true});
    document.removeEventListener('pointermove', handlePointerMove);
};

/**
 * Handle mouse move events.
 *
 * Uses Date.now() for timestamps so events can be compared across page loads.
 *
 * @param {MouseEvent} e Mouse event.
 */
const handleMouseMove = (e) => {
    const now = Date.now();
    const lastMove = eventStore.mouseMoves[eventStore.mouseMoves.length - 1];

    const moveData = {
        x: e.clientX,
        y: e.clientY,
        timestamp: now,
        deltaTime: lastMove ? now - lastMove.timestamp : 0,
        deltaX: lastMove ? e.clientX - lastMove.x : 0,
        deltaY: lastMove ? e.clientY - lastMove.y : 0,
    };

    // Calculate velocity.
    if (moveData.deltaTime > 0) {
        const distance = Math.sqrt(moveData.deltaX ** 2 + moveData.deltaY ** 2);
        moveData.velocity = distance / moveData.deltaTime;
    }

    addToStore('mouseMoves', moveData);
};

/**
 * Handle click events.
 *
 * @param {MouseEvent} e Mouse event.
 */
const handleClick = (e) => {
    const now = Date.now();
    const target = e.target;
    const rect = target.getBoundingClientRect();

    // Calculate click position relative to element center.
    const elementCenterX = rect.left + rect.width / 2;
    const elementCenterY = rect.top + rect.height / 2;
    const offsetFromCenter = Math.sqrt(
        (e.clientX - elementCenterX) ** 2 +
        (e.clientY - elementCenterY) ** 2
    );

    const clickData = {
        x: e.clientX,
        y: e.clientY,
        timestamp: now,
        target: {
            tagName: target.tagName,
            id: target.id,
            className: target.className,
            width: rect.width,
            height: rect.height,
        },
        offsetFromCenter: offsetFromCenter,
        hadPrecedingHover: checkPrecedingHover(target),
        hadPrecedingMouseMove: checkPrecedingMouseMove(e.clientX, e.clientY),
    };

    addToStore('clicks', clickData);
};

/**
 * Handle mousedown events.
 */
const handleMouseDown = () => {
    // Store for click duration analysis.
    const lastClick = eventStore.clicks[eventStore.clicks.length - 1];
    if (lastClick && !lastClick.mousedownTime) {
        lastClick.mousedownTime = Date.now();
    }
};

/**
 * Handle mouseup events.
 */
const handleMouseUp = () => {
    // Calculate click duration.
    const lastClick = eventStore.clicks[eventStore.clicks.length - 1];
    if (lastClick && lastClick.mousedownTime) {
        lastClick.clickDuration = Date.now() - lastClick.mousedownTime;
    }
};

/**
 * Handle mouseover events.
 *
 * @param {MouseEvent} e Mouse event.
 */
const handleMouseOver = (e) => {
    addToStore('hovers', {
        target: e.target,
        timestamp: Date.now(),
        type: 'over',
    });
};

/**
 * Handle mouseout events.
 *
 * @param {MouseEvent} e Mouse event.
 */
const handleMouseOut = (e) => {
    addToStore('hovers', {
        target: e.target,
        timestamp: Date.now(),
        type: 'out',
    });
};

/**
 * Handle keydown events.
 *
 * @param {KeyboardEvent} e Keyboard event.
 */
const handleKeyDown = (e) => {
    const now = Date.now();
    const lastKeystroke = eventStore.keystrokes[eventStore.keystrokes.length - 1];

    addToStore('keystrokes', {
        key: e.key.length === 1 ? 'char' : e.key, // Don't store actual characters for privacy.
        timestamp: now,
        deltaTime: lastKeystroke ? now - lastKeystroke.timestamp : 0,
        type: 'down',
    });
};

/**
 * Handle keyup events.
 */
const handleKeyUp = () => {
    // Find matching keydown to calculate hold duration.
    const keydowns = eventStore.keystrokes.filter(
        (k) => k.type === 'down' && !k.holdDuration
    );
    const matchingKeydown = keydowns[keydowns.length - 1];
    if (matchingKeydown) {
        matchingKeydown.holdDuration = Date.now() - matchingKeydown.timestamp;
    }
};

/**
 * Handle scroll events.
 */
const handleScroll = () => {
    const now = Date.now();
    const lastScroll = eventStore.scrolls[eventStore.scrolls.length - 1];

    addToStore('scrolls', {
        scrollY: window.scrollY,
        scrollX: window.scrollX,
        timestamp: now,
        deltaTime: lastScroll ? now - lastScroll.timestamp : 0,
        deltaY: lastScroll ? window.scrollY - lastScroll.scrollY : 0,
        deltaX: lastScroll ? window.scrollX - lastScroll.scrollX : 0,
    });
};

/**
 * Handle focus in events.
 *
 * @param {FocusEvent} e Focus event.
 */
const handleFocusIn = (e) => {
    addToStore('focusChanges', {
        target: {
            tagName: e.target.tagName,
            id: e.target.id,
            type: e.target.type,
        },
        timestamp: Date.now(),
        type: 'in',
    });
};

/**
 * Handle focus out events.
 *
 * @param {FocusEvent} e Focus event.
 */
const handleFocusOut = (e) => {
    addToStore('focusChanges', {
        target: {
            tagName: e.target.tagName,
            id: e.target.id,
            type: e.target.type,
        },
        timestamp: Date.now(),
        type: 'out',
    });
};

/**
 * Handle pointerdown events for CDP dispatch detection.
 *
 * @param {PointerEvent} e Pointer event.
 */
const handlePointerDown = (e) => {
    addToStore('pointerEvents', {
        type: 'down',
        x: e.clientX,
        y: e.clientY,
        timestamp: Date.now(),
        pointerType: e.pointerType,
    });
};

/**
 * Handle pointermove events (throttled).
 *
 * @param {PointerEvent} e Pointer event.
 */
const handlePointerMove = (e) => {
    const now = Date.now();
    const last = eventStore.pointerEvents[eventStore.pointerEvents.length - 1];
    if (last && now - last.timestamp < 50) {
        return; // Throttle to 20Hz.
    }
    addToStore('pointerEvents', {
        type: 'move',
        x: e.clientX,
        y: e.clientY,
        timestamp: now,
        pointerType: e.pointerType,
    });
};

/**
 * Timestamp of last periodic save to sessionStorage.
 *
 * @type {number}
 */
let lastPeriodicSave = 0;

/**
 * Add event to storage with size limiting.
 *
 * Also periodically saves to sessionStorage so that cross-page
 * accumulation works even when beforeunload does not fire
 * (e.g. CDP-driven page navigations by agents).
 *
 * @param {string} storeName Name of the store.
 * @param {Object} data Event data.
 */
const addToStore = (storeName, data) => {
    eventStore[storeName].push(data);

    // Limit store size.
    if (eventStore[storeName].length > CONFIG.maxStoredEvents) {
        eventStore[storeName].shift();
    }

    // Invalidate cache.
    analysisCache = null;

    // Periodic save: write to sessionStorage every 2 seconds at most.
    // This ensures cross-page accumulation even if beforeunload doesn't fire.
    const now = Date.now();
    if (now - lastPeriodicSave > 2000) {
        lastPeriodicSave = now;
        try {
            saveToSessionStorage();
        } catch (e) {
            // Ignore save errors.
        }
    }
};

/**
 * Check if there was a hover event before this click.
 *
 * @param {Element} target Click target.
 * @returns {boolean} True if hover preceded click.
 */
const checkPrecedingHover = (target) => {
    const recentHovers = eventStore.hovers.slice(-20);
    return recentHovers.some((h) => h.target === target && h.type === 'over');
};

/**
 * Check if there was mouse movement leading to click position.
 *
 * @param {number} x Click X coordinate.
 * @param {number} y Click Y coordinate.
 * @returns {boolean} True if mouse movement preceded click.
 */
const checkPrecedingMouseMove = (x, y) => {
    const recentMoves = eventStore.mouseMoves.slice(-10);
    if (recentMoves.length === 0) {
        return false;
    }

    // Check if any recent movement was near the click position.
    return recentMoves.some((m) => {
        const distance = Math.sqrt((m.x - x) ** 2 + (m.y - y) ** 2);
        return distance < 50;
    });
};

/**
 * Analyze collected interaction data for anomalies.
 *
 * @returns {Object} Analysis results with anomaly signals.
 */
export const analyze = () => {
    if (analysisCache) {
        return analysisCache;
    }

    const results = {
        timestamp: Date.now(),
        duration: Date.now() - eventStore.startTime,
        pageLoadCount: eventStore.pageLoadCount,
        eventCounts: {
            mouseMoves: eventStore.mouseMoves.length,
            clicks: eventStore.clicks.length,
            keystrokes: eventStore.keystrokes.length,
            scrolls: eventStore.scrolls.length,
            hovers: eventStore.hovers.length,
            focusChanges: eventStore.focusChanges.length,
            pointerEvents: eventStore.pointerEvents.length,
        },
        anomalies: [],
        score: 0,
    };

    // Run individual analyses.
    results.anomalies.push(...analyzeMouseMovement());
    results.anomalies.push(...analyzeClicks());
    results.anomalies.push(...analyzeKeystrokes());
    results.anomalies.push(...analyzeScrolling());
    results.anomalies.push(...analyzeEventSequence());

    // Comet agentic mode analyses.
    results.anomalies.push(...analyzeActionBursts());
    results.anomalies.push(...analyzeCDPClickPatterns());
    results.anomalies.push(...analyzePointerEvents());

    // Calculate overall score.
    results.score = calculateInteractionScore(results.anomalies);

    analysisCache = results;
    return results;
};

/**
 * Analyze mouse movement patterns.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzeMouseMovement = () => {
    const anomalies = [];
    const moves = eventStore.mouseMoves;

    if (moves.length < CONFIG.minMouseMoves) {
        anomalies.push({
            name: 'mouse.insufficient_data',
            value: moves.length,
            weight: 2,
        });
        return anomalies;
    }

    // Check for perfectly linear movements.
    // Note: humans trigger this at 0.44-0.57 so it's a weak signal.
    const linearSegments = findLinearSegments(moves);
    if (linearSegments > moves.length * 0.3) {
        anomalies.push({
            name: 'mouse.linear_movement',
            value: linearSegments / moves.length,
            weight: 3, // Reduced from 7 — humans trigger this often.
        });
    }

    // Check for teleporting (instant position changes).
    const teleports = moves.filter((m) => m.velocity > CONFIG.maxMouseSpeed);
    if (teleports.length > 0) {
        anomalies.push({
            name: 'mouse.teleport',
            value: teleports.length,
            weight: 8,
        });
    }

    // Check for no mouse movement at all (common in automated tests).
    const duration = Date.now() - eventStore.startTime;
    if (moves.length < duration / 5000) { // Less than 1 move per 5 seconds.
        anomalies.push({
            name: 'mouse.sparse_movement',
            value: moves.length,
            weight: 5,
        });
    }

    // Check velocity variance (humans have high variance).
    const velocities = moves.filter((m) => m.velocity).map((m) => m.velocity);
    if (velocities.length > 5) {
        const variance = calculateVariance(velocities);
        if (variance < 0.1) {
            anomalies.push({
                name: 'mouse.constant_velocity',
                value: variance,
                weight: 6,
            });
        }
    }

    // KEY SIGNAL: Mouse-to-action ratio.
    // Humans generate many mouse moves per click (typically 20-100+).
    // CDP-driven agents generate almost no mouse moves (0-3 per click).
    // This is the strongest real-world differentiator from test data.
    const totalActions = eventStore.clicks.length + eventStore.keystrokes.filter((k) => k.type === 'down').length;
    if (totalActions >= 3 && eventStore.pageLoadCount >= 2) {
        const movePerAction = moves.length / totalActions;
        if (movePerAction < 2) {
            // Almost no mouse movement relative to actions — very strong agent signal.
            anomalies.push({
                name: 'comet.low_mouse_to_action_ratio',
                value: movePerAction,
                weight: 10,
            });
        } else if (movePerAction < 5) {
            // Very low mouse movement — suspicious.
            anomalies.push({
                name: 'comet.low_mouse_to_action_ratio',
                value: movePerAction,
                weight: 7,
            });
        }
    }

    return anomalies;
};

/**
 * Find linear segments in mouse movement.
 *
 * @param {Array} moves Mouse move events.
 * @returns {number} Count of linear segments.
 */
const findLinearSegments = (moves) => {
    let linearCount = 0;
    const threshold = 0.99; // Angle consistency threshold.

    for (let i = 2; i < moves.length; i++) {
        const angle1 = Math.atan2(
            moves[i - 1].y - moves[i - 2].y,
            moves[i - 1].x - moves[i - 2].x
        );
        const angle2 = Math.atan2(
            moves[i].y - moves[i - 1].y,
            moves[i].x - moves[i - 1].x
        );

        if (Math.abs(Math.cos(angle1 - angle2)) > threshold) {
            linearCount++;
        }
    }

    return linearCount;
};

/**
 * Analyze click patterns.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzeClicks = () => {
    const anomalies = [];
    const clicks = eventStore.clicks;

    if (clicks.length < CONFIG.minClicks) {
        return anomalies;
    }

    // Check for clicks at exact element centers (agents click perfectly).
    const centerClicks = clicks.filter(
        (c) => c.offsetFromCenter < CONFIG.centerClickTolerance
    );
    if (centerClicks.length > clicks.length * 0.5) {
        anomalies.push({
            name: 'click.center_precision',
            value: centerClicks.length / clicks.length,
            weight: 10, // Increased - strong agent indicator.
        });
    }

    // Ultra-precise center clicks (< 2px offset) -- strong agentic indicator.
    // Agents target elements by reference, landing at exact computed center.
    const ultraPreciseClicks = clicks.filter(
        (c) => c.offsetFromCenter < 2
    );
    if (ultraPreciseClicks.length > clicks.length * 0.6 && clicks.length >= 3) {
        anomalies.push({
            name: 'comet.ultra_precise_center',
            value: ultraPreciseClicks.length / clicks.length,
            weight: 10,
        });
    }

    // Check for clicks without preceding hover.
    const noHoverClicks = clicks.filter((c) => !c.hadPrecedingHover);
    if (noHoverClicks.length > clicks.length * 0.7) {
        anomalies.push({
            name: 'click.no_hover',
            value: noHoverClicks.length / clicks.length,
            weight: 6,
        });
    }

    // Check for clicks without preceding mouse movement (teleport clicks).
    const noMoveClicks = clicks.filter((c) => !c.hadPrecedingMouseMove);
    if (noMoveClicks.length > clicks.length * 0.5) {
        anomalies.push({
            name: 'click.no_movement',
            value: noMoveClicks.length / clicks.length,
            weight: 9, // Increased - agents teleport to click targets.
        });
    }

    // STRONG INDICATOR: Clicks with NO mouse data at all (pure teleport).
    const totalMouseMoves = eventStore.mouseMoves.length;
    if (clicks.length >= 3 && totalMouseMoves < clicks.length * 2) {
        anomalies.push({
            name: 'click.teleport_pattern',
            value: totalMouseMoves / clicks.length,
            weight: 10, // Very strong - humans move mouse much more than they click.
        });
    }

    // Check for impossibly fast clicks (< 50ms reaction time).
    // Note: humans trigger this with rapid double-clicks, so not definitive alone.
    const interClickTimes = [];
    for (let i = 1; i < clicks.length; i++) {
        interClickTimes.push(clicks[i].timestamp - clicks[i - 1].timestamp);
    }
    const fastClicks = interClickTimes.filter((t) => t < CONFIG.minHumanReactionTime);
    if (fastClicks.length > 0) {
        anomalies.push({
            name: 'click.superhuman_speed',
            value: fastClicks.length,
            weight: 6, // Reduced from 10 — humans can rapid-click too.
        });
    }

    // Check for perfectly regular click timing.
    if (interClickTimes.length >= 3) {
        const variance = calculateVariance(interClickTimes);
        if (variance < CONFIG.perfectTimingVariance) {
            anomalies.push({
                name: 'click.perfect_timing',
                value: variance,
                weight: 8,
            });
        }
    }

    return anomalies;
};

/**
 * Analyze keystroke patterns.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzeKeystrokes = () => {
    const anomalies = [];
    const keystrokes = eventStore.keystrokes.filter((k) => k.type === 'down');

    if (keystrokes.length < CONFIG.minKeystrokes) {
        return anomalies;
    }

    // Check inter-key timing variance.
    const interKeyTimes = keystrokes.slice(1).map((k) => k.deltaTime);
    if (interKeyTimes.length >= 5) {
        const variance = calculateVariance(interKeyTimes);
        if (variance < CONFIG.perfectTimingVariance) {
            anomalies.push({
                name: 'keystroke.perfect_timing',
                value: variance,
                weight: 9,
            });
        }
    }

    // Comet-specific: check coefficient of variation for inter-key timing.
    // Human typing has CV > 0.3; agent-dispatched keystrokes have CV < 0.1.
    const keyMean = interKeyTimes.reduce((a, b) => a + b, 0) / interKeyTimes.length;
    const keyStdDev = Math.sqrt(calculateVariance(interKeyTimes));
    const keyCV = keyMean > 0 ? keyStdDev / keyMean : 0;

    if (keyCV < 0.1 && interKeyTimes.length >= 10) {
        anomalies.push({
            name: 'comet.uniform_keystroke_cadence',
            value: keyCV,
            weight: 9,
        });
    }

    // Check for impossibly fast typing (< 30ms between keys is ~2000 WPM).
    const fastKeys = interKeyTimes.filter((t) => t > 0 && t < 30);
    if (fastKeys.length > interKeyTimes.length * 0.3) {
        anomalies.push({
            name: 'keystroke.superhuman_speed',
            value: fastKeys.length / interKeyTimes.length,
            weight: 9,
        });
    }

    // Check key hold duration variance.
    const holdDurations = keystrokes.filter((k) => k.holdDuration).map((k) => k.holdDuration);
    if (holdDurations.length >= 5) {
        const variance = calculateVariance(holdDurations);
        if (variance < 1) {
            anomalies.push({
                name: 'keystroke.constant_hold',
                value: variance,
                weight: 7,
            });
        }

        // Comet-specific: check hold duration coefficient of variation.
        if (holdDurations.length >= 10) {
            const holdMean = holdDurations.reduce((a, b) => a + b, 0) / holdDurations.length;
            const holdStdDev = Math.sqrt(calculateVariance(holdDurations));
            const holdCV = holdMean > 0 ? holdStdDev / holdMean : 0;

            if (holdCV < 0.1) {
                anomalies.push({
                    name: 'comet.uniform_hold_duration',
                    value: holdCV,
                    weight: 8,
                });
            }
        }
    }

    return anomalies;
};

/**
 * Analyze scrolling patterns.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzeScrolling = () => {
    const anomalies = [];
    const scrolls = eventStore.scrolls;

    if (scrolls.length < 3) {
        return anomalies;
    }

    // Check for instant scroll jumps (no smooth scrolling).
    const instantScrolls = scrolls.filter((s) => s.deltaTime < 10 && Math.abs(s.deltaY) > 100);
    if (instantScrolls.length > scrolls.length * 0.5) {
        anomalies.push({
            name: 'scroll.instant_jump',
            value: instantScrolls.length / scrolls.length,
            weight: 6,
        });
    }

    // Check for perfectly regular scroll amounts.
    const scrollAmounts = scrolls.map((s) => Math.abs(s.deltaY)).filter((v) => v > 0);
    if (scrollAmounts.length >= 3) {
        const variance = calculateVariance(scrollAmounts);
        if (variance < 1) {
            anomalies.push({
                name: 'scroll.constant_amount',
                value: variance,
                weight: 5,
            });
        }
    }

    return anomalies;
};

/**
 * Analyze event sequence patterns.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzeEventSequence = () => {
    const anomalies = [];

    // Check for missing event sequences (e.g., click without mousedown).
    // Automated tools sometimes skip intermediate events.

    // Check ratio of hovers to clicks (humans hover a lot before clicking).
    const hoverRatio = eventStore.hovers.length / Math.max(eventStore.clicks.length, 1);
    if (hoverRatio < 2 && eventStore.clicks.length >= CONFIG.minClicks) {
        anomalies.push({
            name: 'sequence.low_hover_ratio',
            value: hoverRatio,
            weight: 5,
        });
    }

    // Check for focus changes without preceding clicks or tabs.
    // Direct focus (via JS) is common in automation.
    const directFocus = eventStore.focusChanges.filter((f) => {
        // Check if there was a recent click or keystroke.
        const recentEvents = [
            ...eventStore.clicks.slice(-5),
            ...eventStore.keystrokes.slice(-5),
        ];
        const hasRecentInteraction = recentEvents.some(
            (e) => Math.abs(e.timestamp - f.timestamp) < 100
        );
        return !hasRecentInteraction;
    });

    if (directFocus.length > eventStore.focusChanges.length * 0.5 &&
        eventStore.focusChanges.length >= 3) {
        anomalies.push({
            name: 'sequence.direct_focus',
            value: directFocus.length / eventStore.focusChanges.length,
            weight: 6,
        });
    }

    // Rapid sequential focus changes across different form fields.
    // Agents navigate fields programmatically, producing near-instant focus changes.
    const focusIns = eventStore.focusChanges.filter((f) => f.type === 'in');
    if (focusIns.length >= 3) {
        let rapidSequentialFocus = 0;
        for (let j = 1; j < focusIns.length; j++) {
            const gap = focusIns[j].timestamp - focusIns[j - 1].timestamp;
            const differentTarget = focusIns[j].target.id !== focusIns[j - 1].target.id;
            if (gap < 200 && differentTarget) {
                rapidSequentialFocus++;
            }
        }
        if (rapidSequentialFocus >= 2) {
            anomalies.push({
                name: 'comet.rapid_focus_sequence',
                value: rapidSequentialFocus,
                weight: 7,
            });
        }
    }

    return anomalies;
};

/**
 * Calculate variance of an array of numbers.
 *
 * @param {Array<number>} arr Array of numbers.
 * @returns {number} Variance.
 */
const calculateVariance = (arr) => {
    if (arr.length < 2) {
        return 0;
    }
    const mean = arr.reduce((a, b) => a + b, 0) / arr.length;
    const squareDiffs = arr.map((value) => Math.pow(value - mean, 2));
    return squareDiffs.reduce((a, b) => a + b, 0) / arr.length;
};

/**
 * Calculate overall interaction score.
 *
 * Applies a confidence discount when total events are low, to avoid
 * false positives from sparse data (e.g. a single quiz page with 2 clicks).
 *
 * @param {Array} anomalies Detected anomalies.
 * @returns {number} Score from 0-100.
 */
const calculateInteractionScore = (anomalies) => {
    if (anomalies.length === 0) {
        return 0;
    }

    const totalWeight = anomalies.reduce((sum, a) => sum + (a.weight || 0), 0);
    const maxPossibleWeight = anomalies.length * 10;

    // Check for "smoking gun" combinations that indicate definite agent.
    const hasSuperhuman = anomalies.some((a) => a.name === 'click.superhuman_speed');
    const hasCenterPrecision = anomalies.some((a) => a.name === 'click.center_precision');
    const hasTeleport = anomalies.some((a) => a.name === 'click.teleport_pattern');
    const hasNoMovement = anomalies.some((a) => a.name === 'click.no_movement');
    const hasUltraPrecise = anomalies.some((a) => a.name === 'comet.ultra_precise_center');
    const hasNoTrail = anomalies.some((a) => a.name === 'comet.no_mousemove_trail');
    const hasReadThenAct = anomalies.some((a) => a.name === 'comet.read_then_act');
    const hasLowMouseRatio = anomalies.some((a) => a.name === 'comet.low_mouse_to_action_ratio');

    // Multiple strong signals = high confidence agent.
    let multiplier = 1.0;
    const strongSignals = [
        hasSuperhuman, hasCenterPrecision, hasTeleport, hasNoMovement,
        hasUltraPrecise, hasNoTrail, hasReadThenAct, hasLowMouseRatio,
    ].filter(Boolean).length;
    if (strongSignals >= 3) {
        multiplier = 1.5; // 3+ strong signals = very likely agent.
    } else if (strongSignals >= 2) {
        multiplier = 1.25; // 2 strong signals = boost score.
    }

    // Normalize and apply scaling.
    let rawScore = (totalWeight / Math.max(maxPossibleWeight, 30)) * 100 * multiplier;

    // Confidence discount: with very few events, ratios are unreliable.
    // A human clicking 3 times with 2 mouse moves looks identical to an agent.
    // Require more data before giving high scores.
    const totalActions = eventStore.clicks.length + eventStore.keystrokes.length;
    const totalMoves = eventStore.mouseMoves.length;
    const totalEvents = totalActions + totalMoves;

    if (totalEvents < 10) {
        // Very sparse — heavily discount unless smoking-gun signals present.
        // center_precision is reliable even with few events; teleport_pattern is not.
        const hasReliableSignal = hasCenterPrecision || hasUltraPrecise || hasNoTrail || hasLowMouseRatio;
        if (!hasReliableSignal) {
            rawScore *= 0.3; // 70% discount for ratio-only signals with sparse data.
        } else {
            rawScore *= 0.7; // 30% discount even with reliable signals if data is sparse.
        }
    } else if (totalEvents < 25) {
        // Moderate data — small discount.
        rawScore *= 0.85;
    }
    // 25+ events = full confidence, no discount.

    return Math.min(100, Math.round(rawScore));
};

/**
 * Analyze action bursts — rapid sequences of heterogeneous events
 * preceded by a quiescent period. Characteristic of agentic AI
 * that reads the DOM, pauses to "think", then executes rapidly.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzeActionBursts = () => {
    const anomalies = [];

    // Merge all action events into a sorted timeline.
    const allActions = [
        ...eventStore.clicks.map((e) => ({timestamp: e.timestamp, actionType: 'click'})),
        ...eventStore.keystrokes.filter((k) => k.type === 'down').map((e) => ({timestamp: e.timestamp, actionType: 'keystroke'})),
        ...eventStore.focusChanges.map((e) => ({timestamp: e.timestamp, actionType: 'focus'})),
    ].sort((a, b) => a.timestamp - b.timestamp);

    if (allActions.length < 5) {
        return anomalies;
    }

    let burstCount = 0;
    let readThenActCount = 0;
    let i = 0;

    while (i < allActions.length) {
        // Find all actions within 2000ms of this one.
        let windowEnd = i;
        while (windowEnd < allActions.length &&
               allActions[windowEnd].timestamp - allActions[i].timestamp < 2000) {
            windowEnd++;
        }
        const burstSize = windowEnd - i;
        const actionTypes = new Set(
            allActions.slice(i, windowEnd).map((a) => a.actionType)
        );

        if (burstSize >= 5 && actionTypes.size >= 2) {
            burstCount++;

            // Check for preceding quiescent period (3+ seconds gap).
            if (i > 0) {
                const gap = allActions[i].timestamp - allActions[i - 1].timestamp;
                if (gap >= 3000) {
                    readThenActCount++;
                }
            }
            // Skip past this burst to avoid double-counting.
            i = windowEnd;
        } else {
            i++;
        }
    }

    if (burstCount >= 2) {
        anomalies.push({
            name: 'comet.action_burst',
            value: burstCount,
            weight: 8,
        });
    }

    if (readThenActCount >= 1) {
        anomalies.push({
            name: 'comet.read_then_act',
            value: readThenActCount,
            weight: 9,
        });
    }

    return anomalies;
};

/**
 * Analyze CDP-dispatched click patterns.
 * CDP-dispatched clicks via Input.dispatchMouseEvent lack the natural
 * mousemove trail that precedes a human click.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzeCDPClickPatterns = () => {
    const anomalies = [];
    const clicks = eventStore.clicks;
    const moves = eventStore.mouseMoves;

    if (clicks.length < 3) {
        return anomalies;
    }

    // For each click, count mousemoves in the 500ms window before it.
    let zeroTrailClicks = 0;

    for (const click of clicks) {
        const precedingMoves = moves.filter((m) =>
            m.timestamp > click.timestamp - 500 &&
            m.timestamp < click.timestamp
        );
        if (precedingMoves.length === 0) {
            zeroTrailClicks++;
        }
    }

    const ratio = zeroTrailClicks / clicks.length;
    if (ratio > 0.7) {
        anomalies.push({
            name: 'comet.no_mousemove_trail',
            value: ratio,
            weight: 9,
        });
    }

    return anomalies;
};

/**
 * Analyze pointer events relative to mouse clicks.
 * Human interactions generate both pointer and mouse events.
 * CDP-dispatched mouse events may lack corresponding pointer events.
 *
 * @returns {Array} Anomaly signals.
 */
const analyzePointerEvents = () => {
    const anomalies = [];
    const clicks = eventStore.clicks;
    const pointerDowns = eventStore.pointerEvents.filter((p) => p.type === 'down');

    if (clicks.length < 3) {
        return anomalies;
    }

    const ratio = pointerDowns.length / clicks.length;
    if (ratio < 0.3) {
        anomalies.push({
            name: 'comet.missing_pointer_events',
            value: ratio,
            weight: 7,
        });
    }

    return anomalies;
};

/**
 * Get the sessionStorage key for cross-page event accumulation.
 *
 * @returns {string} Storage key scoped by context.
 */
const getStorageKey = () => {
    return contextId ? `agentdetect_events_${contextId}` : 'agentdetect_events';
};

/**
 * Load accumulated events from sessionStorage (prior pages in same session).
 *
 * @returns {void}
 */
const loadFromSessionStorage = () => {
    try {
        const stored = sessionStorage.getItem(getStorageKey());
        if (!stored) {
            return;
        }
        const data = JSON.parse(stored);

        // Restore startTime from the original first page.
        if (data.startTime) {
            eventStore.startTime = data.startTime;
        }

        // Restore page load count and increment.
        eventStore.pageLoadCount = (data.pageLoadCount || 1) + 1;

        // Merge stored events — keep the most recent ones within limits.
        const storeNames = ['mouseMoves', 'clicks', 'keystrokes', 'scrolls', 'focusChanges', 'pointerEvents'];
        for (const name of storeNames) {
            if (data[name] && Array.isArray(data[name])) {
                // Prepend prior events, then trim to max.
                eventStore[name] = [...data[name], ...eventStore[name]];
                if (eventStore[name].length > CONFIG.maxStoredEvents) {
                    eventStore[name] = eventStore[name].slice(-CONFIG.maxStoredEvents);
                }
            }
        }

        // Don't restore hovers — they hold element references which can't be serialised.

        // Invalidate analysis cache since we loaded new data.
        analysisCache = null;
    } catch (e) {
        // sessionStorage unavailable or data corrupt — start fresh.
    }
};

/**
 * Save current events to sessionStorage for the next page load.
 * Called on beforeunload to persist cross-page.
 *
 * @returns {void}
 */
export const saveToSessionStorage = () => {
    try {
        // Save a compressed version — most recent 200 per type, no DOM references.
        const data = {
            startTime: eventStore.startTime,
            pageLoadCount: eventStore.pageLoadCount,
            mouseMoves: eventStore.mouseMoves.slice(-200),
            clicks: eventStore.clicks.slice(-200).map((c) => ({
                x: c.x,
                y: c.y,
                timestamp: c.timestamp,
                offsetFromCenter: c.offsetFromCenter,
                hadPrecedingHover: c.hadPrecedingHover,
                hadPrecedingMouseMove: c.hadPrecedingMouseMove,
                clickDuration: c.clickDuration,
            })),
            keystrokes: eventStore.keystrokes.slice(-200).map((k) => ({
                key: k.key,
                timestamp: k.timestamp,
                deltaTime: k.deltaTime,
                type: k.type,
                holdDuration: k.holdDuration,
            })),
            scrolls: eventStore.scrolls.slice(-200),
            focusChanges: eventStore.focusChanges.slice(-200).map((f) => ({
                target: f.target,
                timestamp: f.timestamp,
                type: f.type,
            })),
            pointerEvents: eventStore.pointerEvents.slice(-200),
        };
        sessionStorage.setItem(getStorageKey(), JSON.stringify(data));
    } catch (e) {
        // Ignore storage errors (quota exceeded, etc.).
    }
};

/**
 * Get raw event data for debugging/inspection.
 *
 * @returns {Object} Event store data.
 */
export const getRawData = () => {
    return {
        ...eventStore,
        isMonitoring,
    };
};

/**
 * Reset all collected data.
 *
 * @returns {void}
 */
export const reset = () => {
    eventStore.mouseMoves = [];
    eventStore.clicks = [];
    eventStore.keystrokes = [];
    eventStore.scrolls = [];
    eventStore.hovers = [];
    eventStore.focusChanges = [];
    eventStore.pointerEvents = [];
    eventStore.startTime = Date.now();
    eventStore.pageLoadCount = 1;
    analysisCache = null;
};

export default {
    startMonitoring,
    stopMonitoring,
    analyze,
    getRawData,
    reset,
    saveToSessionStorage,
    CONFIG,
};
