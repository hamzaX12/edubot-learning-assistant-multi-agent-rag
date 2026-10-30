<?php
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
 * Strategy selector — pedagogical decision engine for EduBot.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

/**
 * Selects the appropriate pedagogical strategy based on student state.
 */
class strategy_selector {

    /** @var array Hint levels per conversation. */
    private static $hintlevels = [];

    /**
     * Select the optimal pedagogical strategy.
     *
     * @param array $state Student state array with keys:
     *   emotional_state, exchange_count, topic_is_new, is_wrong_answer,
     *   hint_requested, topic_completion, recent_correct_answers,
     *   conversation_id.
     * @return string Strategy name.
     */
    public function select_strategy($state) {
        $emotion = $state['emotional_state'] ?? 'NEUTRAL';
        $exchanges = $state['exchange_count'] ?? 0;
        $topicnew = $state['topic_is_new'] ?? false;
        $wronganswer = $state['is_wrong_answer'] ?? false;
        $hintrequest = $state['hint_requested'] ?? false;
        $completion = $state['topic_completion'] ?? 0;
        $recentcorrect = $state['recent_correct_answers'] ?? 0;
        $convid = $state['conversation_id'] ?? 0;

        $hintlevel = $this->get_hint_level($convid);

        // 1. Emotional states take priority.
        if (in_array($emotion, ['FRUSTRATED', 'ANXIOUS', 'DISCOURAGED'])) {
            return 'EMOTIONAL_SUPPORT';
        }

        // 2. New topic or first exchange.
        if ($exchanges == 1 || $topicnew) {
            return 'ACTIVATION';
        }

        // 3. Wrong answer, first hint.
        if ($wronganswer && $hintlevel == 0) {
            $this->increment_hint_level($convid);
            return 'ERROR_CORRECTION';
        }

        // 4. Wrong answer, subsequent hints.
        if ($wronganswer && $hintlevel >= 1) {
            $this->increment_hint_level($convid);
            return 'SCAFFOLDED_HINTS';
        }

        // 5. Hint requested.
        if ($hintrequest) {
            $this->increment_hint_level($convid);
            return 'SCAFFOLDED_HINTS';
        }

        // 6. Metacognitive check every 5 exchanges.
        if ($exchanges > 0 && $exchanges % 5 == 0) {
            return 'METACOGNITIVE_MONITORING';
        }

        // 7. Consolidation when doing well.
        if ($completion >= 80 && $recentcorrect >= 3) {
            return 'CONSOLIDATION';
        }

        // 8. Default.
        return 'SOCRATIC_QUESTIONING';
    }

    /**
     * Get hint level for a conversation.
     *
     * @param int $convid Conversation ID.
     * @return int Hint level.
     */
    public function get_hint_level($convid) {
        return self::$hintlevels[$convid] ?? 0;
    }

    /**
     * Increment hint level for a conversation.
     *
     * @param int $convid Conversation ID.
     */
    public function increment_hint_level($convid) {
        if (!isset(self::$hintlevels[$convid])) {
            self::$hintlevels[$convid] = 0;
        }
        self::$hintlevels[$convid]++;
    }

    /**
     * Reset hint level (new topic or correct answer).
     *
     * @param int $convid Conversation ID.
     */
    public function reset_hint_level($convid) {
        self::$hintlevels[$convid] = 0;
    }

    /**
     * Load hint level from database (message count with SCAFFOLDED_HINTS).
     *
     * @param int $convid Conversation ID.
     */
    public function load_hint_level($convid) {
        global $DB;
        $count = $DB->count_records_select(
            'local_edubot_messages',
            "conversation_id = :convid AND strategy_used = :strategy",
            ['convid' => $convid, 'strategy' => 'SCAFFOLDED_HINTS']
        );
        self::$hintlevels[$convid] = (int) $count;
    }
}
