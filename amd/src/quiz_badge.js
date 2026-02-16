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
 * Quiz badge injection module.
 *
 * Adds visual agent detection indicators next to student names on
 * quiz report overview and quiz review pages.
 *
 * @module     local_agentdetect/quiz_badge
 * @copyright  2026 Cursive Technology <joe@cursivetechnology.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Log from 'core/log';

/**
 * Initialise badge injection.
 *
 * @param {Object} config Configuration from PHP.
 * @param {string} config.mode Page mode: 'overview' or 'review'.
 * @param {number} config.courseid Course ID.
 * @param {number} config.contextid Context ID for AJAX calls.
 * @param {string} config.reportUrl Base URL for the course report page.
 */
export const init = (config) => {
    Log.debug('[AgentDetect Badge] Initialising with config:', config);

    if (config.mode === 'overview') {
        injectOverviewBadges(config);
    } else if (config.mode === 'review') {
        injectReviewBadge(config);
    }
};

/**
 * Inject badges on the quiz grades overview page.
 *
 * Finds all user profile links in the attempts table, extracts user IDs,
 * makes a single batch AJAX call, and appends icons next to flagged users.
 *
 * @param {Object} config Configuration object.
 */
const injectOverviewBadges = async(config) => {
    // Find user profile links in the attempts table.
    const userLinks = document.querySelectorAll(
        'table.quizattemptsreport a[href*="user/view.php"], ' +
        'table.generaltable a[href*="user/view.php"]'
    );

    if (!userLinks.length) {
        Log.debug('[AgentDetect Badge] No user links found on overview page.');
        return;
    }

    // Extract unique user IDs from href params.
    const userMap = new Map();
    userLinks.forEach((link) => {
        const url = new URL(link.href, window.location.origin);
        const uid = parseInt(url.searchParams.get('id'), 10);
        if (uid && !isNaN(uid)) {
            if (!userMap.has(uid)) {
                userMap.set(uid, []);
            }
            userMap.get(uid).push(link);
        }
    });

    if (!userMap.size) {
        return;
    }

    const userIds = Array.from(userMap.keys());
    Log.debug('[AgentDetect Badge] Fetching flags for', userIds.length, 'users');

    try {
        const flags = await fetchFlags(userIds, config.contextid);
        flags.forEach((flag) => {
            const links = userMap.get(flag.userid);
            if (links && links.length > 0) {
                // Only badge the first link per user to avoid duplicates across attempt rows.
                appendBadge(links[0], flag, config);
            }
        });
    } catch (err) {
        Log.error('[AgentDetect Badge] Failed to fetch flags:', err);
    }
};

/**
 * Inject a badge on the single attempt review page.
 *
 * Finds the user link in the quiz review summary table and checks
 * their flag status.
 *
 * @param {Object} config Configuration object.
 */
const injectReviewBadge = async(config) => {
    // Find user link in the review summary table.
    const summaryTable = document.querySelector('table.quizreviewsummary');
    if (!summaryTable) {
        Log.debug('[AgentDetect Badge] No quiz review summary table found.');
        return;
    }

    const userLink = summaryTable.querySelector('a[href*="user/view.php"]');
    if (!userLink) {
        return;
    }

    const url = new URL(userLink.href, window.location.origin);
    const uid = parseInt(url.searchParams.get('id'), 10);
    if (!uid || isNaN(uid)) {
        return;
    }

    try {
        const flags = await fetchFlags([uid], config.contextid);
        if (flags.length > 0) {
            appendBadge(userLink, flags[0], config);
        }
    } catch (err) {
        Log.error('[AgentDetect Badge] Failed to fetch flag:', err);
    }
};

/**
 * Fetch user flags via AJAX.
 *
 * @param {number[]} userIds Array of user IDs.
 * @param {number} contextId Context ID.
 * @returns {Promise<Array>} Array of flag objects.
 */
const fetchFlags = (userIds, contextId) => {
    const request = Ajax.call([{
        methodname: 'local_agentdetect_get_user_flags',
        args: {
            userids: userIds,
            contextid: contextId,
        },
    }]);
    return request[0];
};

/**
 * Append a detection badge icon next to a user link.
 *
 * @param {HTMLElement} link The user profile link element.
 * @param {Object} flag The flag data.
 * @param {Object} config Configuration with reportUrl and courseid.
 */
const appendBadge = (link, flag, config) => {
    // Don't double-inject.
    if (link.parentElement.querySelector('.agentdetect-badge')) {
        return;
    }

    let iconName;
    let cssClass;
    let tooltip;

    if (flag.flagtype === 'agent_suspected' || flag.flagtype === 'agent_confirmed') {
        iconName = 'i/warning';
        cssClass = 'agentdetect-badge text-danger';
        tooltip = flag.flagtype.replace('_', ' ') + ' (score: ' + flag.maxscore + ')';
    } else if (flag.flagtype === 'low_suspicion') {
        iconName = 'i/flagged';
        cssClass = 'agentdetect-badge text-warning';
        tooltip = 'Low suspicion (score: ' + flag.maxscore + ')';
    } else if (flag.flagtype === 'likely_human') {
        iconName = 'i/checkedcircle';
        cssClass = 'agentdetect-badge text-success';
        tooltip = 'Likely human (score: ' + flag.maxscore + ')';
    } else {
        // Cleared or unknown — don't show badge.
        return;
    }

    // Build the badge link to the course report.
    const reportUrl = config.reportUrl + '&userid=' + flag.userid;

    const badgeLink = document.createElement('a');
    badgeLink.href = reportUrl;
    badgeLink.className = cssClass;
    badgeLink.title = tooltip;
    badgeLink.style.marginLeft = '4px';
    badgeLink.style.textDecoration = 'none';

    // Use Moodle pix icon.
    const img = document.createElement('img');
    img.src = M.util.image_url(iconName, 'core');
    img.alt = tooltip;
    img.className = 'icon';
    img.style.width = '16px';
    img.style.height = '16px';

    badgeLink.appendChild(img);
    link.parentElement.insertBefore(badgeLink, link.nextSibling);
};
