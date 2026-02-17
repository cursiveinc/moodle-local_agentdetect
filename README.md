[![Moodle Plugin CI](https://github.com/joethibault/moodle-local_agentdetect/actions/workflows/ci.yml/badge.svg)](https://github.com/joethibault/moodle-local_agentdetect/actions/workflows/ci.yml)
# Agent Detection (local_agentdetect)

A Moodle local plugin that detects automated browser agents (e.g., Perplexity Comet) during quizzes and assessments.

## Description

Agent Detection monitors browser fingerprints and user interaction patterns to identify sessions driven by AI-powered browser agents rather than human users. It uses a multi-layered detection approach:

- **Browser fingerprinting** - Detects WebDriver flags, headless browser indicators, and known automation extensions.
- **Interaction analysis** - Monitors mouse movement, click patterns, keystroke timing, and scroll behaviour to identify non-human interaction patterns.
- **DOM injection detection** - (Coming soon )Uses MutationObserver to detect elements injected by AI helper browser extensions.
- **Comet-specific signals** - Targeted detection for Perplexity's Comet agentic browser including centre-precision clicks, teleport patterns, zero keystrokes, and per-page mouse ratio analysis.

## Requirements

- Moodle 4.3 or later
- PHP 8.0 or later

## Installation

1. Copy the `agentdetect` directory into `local/` in your Moodle installation.
2. Visit **Site Administration > Notifications** to trigger the database installation.
3. Configure the plugin at **Site Administration > Plugins > Local plugins > Agent Detection**.

## Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| Enable agent detection | Master on/off switch | Off |
| Detection threshold | Score (0-100) above which sessions are flagged | 70 |
| Minimum report score | Only report signals at or above this score | 10 |
| Report interval | How often to send detection reports (ms) | 30000 |
| Page types to monitor | Comma-separated page types with wildcard support | `mod-assign-*,mod-quiz-*` |
| Collect interaction data | Enable behavioural analysis | On |
| Debug mode | Enable browser console logging | Off |

## Capabilities

| Capability | Context | Default roles | Description |
|------------|---------|---------------|-------------|
| `local/agentdetect:viewreports` | Course | Teacher, Editing teacher, Manager | View agent detection reports for a course |
| `local/agentdetect:manageflags` | Course | Editing teacher, Manager | Manage user detection flags |
| `local/agentdetect:viewsignals` | System | Manager | View detailed signal data (admin report) |
| `local/agentdetect:configure` | System | Manager | Configure plugin settings |

## Reports

- **Admin report**: Available at **Site Administration > Reports > Agent Detection**. Shows all signals and flags across the site. Requires `viewsignals` capability.
- **Course report**: Available in the course navigation Reports section. Shows flagged students enrolled in the course. Requires `viewreports` capability.
- **Quiz badges**: Visual indicators appear next to student names on quiz review pages when flags exist.
<img width="1878" height="1082" alt="Screenshot 2026-02-16 182335" src="https://github.com/user-attachments/assets/fba48227-a0fa-4232-bd19-5fc9950b09e2" />



## Privacy

This plugin stores personal data including user IDs, IP addresses, user agent strings, and behavioural interaction signals. It implements the Moodle privacy API (GDPR) with full support for data export and deletion.

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

See [COPYING](https://www.gnu.org/licenses/gpl-3.0.html) for details.
