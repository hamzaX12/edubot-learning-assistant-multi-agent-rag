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
 * Emotion detector — keyword-based message emotion analysis.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

/**
 * Detects emotional state from student messages using keyword analysis.
 */
class emotion_detector {

    /** @var array Frustration keywords. */
    private const FRUSTRATION_KEYWORDS = [
        "i don't understand",
        "i dont understand",
        "this is impossible",
        "i give up",
        "makes no sense",
        "i've tried",
        "still wrong",
        "doesn't work",
        "i'm lost",
        "i can't",
        "so confused",
        "too hard",
    ];

    /** @var array Anxiety keywords. */
    private const ANXIETY_KEYWORDS = [
        "exam",
        "test tomorrow",
        "i'll fail",
        "running out of time",
        "deadline",
        "scared",
        "worried",
        "what if i fail",
    ];

    /** @var array Discouragement keywords. */
    private const DISCOURAGEMENT_KEYWORDS = [
        "i give up",
        "forget it",
        "never mind",
        "i'm done",
        "useless",
        "i'll never understand",
        "i'm stupid",
        "too hard for me",
        "i'm not good at this",
    ];

    /**
     * Analyze a message for emotional signals.
     *
     * @param string $message The student's message.
     * @param int $exchangecount Number of exchanges in conversation.
     * @return array Analysis result with emotional_state, is_wrong_answer, hint_requested.
     */
    public function analyze_message($message, $exchangecount = 0) {
        $lower = strtolower($message);
        $emotion = 'NEUTRAL';

        // Check discouragement first (overlaps with frustration).
        foreach (self::DISCOURAGEMENT_KEYWORDS as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                $emotion = 'DISCOURAGED';
                break;
            }
        }

        // Check frustration (may override if not already discouraged).
        if ($emotion === 'NEUTRAL') {
            foreach (self::FRUSTRATION_KEYWORDS as $keyword) {
                if (strpos($lower, $keyword) !== false) {
                    $emotion = 'FRUSTRATED';
                    break;
                }
            }
        }

        // Check anxiety.
        if ($emotion === 'NEUTRAL') {
            foreach (self::ANXIETY_KEYWORDS as $keyword) {
                if (strpos($lower, $keyword) !== false) {
                    $emotion = 'ANXIOUS';
                    break;
                }
            }
        }

        // Check boredom: short messages after several exchanges.
        if ($emotion === 'NEUTRAL' && strlen($message) < 10 && $exchangecount > 5) {
            $emotion = 'BORED';
        }

        // Detect wrong answer signals.
        $iswrong = false;
        $wrongsignals = ['wrong', 'incorrect', 'mistake', 'error', 'failed', 'not right'];
        foreach ($wrongsignals as $signal) {
            if (strpos($lower, $signal) !== false) {
                $iswrong = true;
                break;
            }
        }

        // Detect hint request.
        $hintrequested = false;
        $hintsignals = ['hint', 'help me', 'clue', 'tip', 'guide me', 'show me how'];
        foreach ($hintsignals as $signal) {
            if (strpos($lower, $signal) !== false) {
                $hintrequested = true;
                break;
            }
        }

        return [
            'emotional_state' => $emotion,
            'is_wrong_answer' => $iswrong,
            'hint_requested' => $hintrequested,
        ];
    }

    /**
     * Combine message analysis with behavioral signals for final state.
     *
     * @param array $messageanalysis From analyze_message().
     * @param array $behavioral From student_profiler.get_behavioral_signals().
     * @return string Final emotional state.
     */
    public function combine_signals($messageanalysis, $behavioral) {
        $emotion = $messageanalysis['emotional_state'];
        $msglen = 30; // Default, will be overridden if we have actual length.

        // Override: neutral but many failed attempts.
        if ($emotion === 'NEUTRAL'
            && isset($behavioral['failed_attempts_today'])
            && $behavioral['failed_attempts_today'] >= 3) {
            $emotion = 'FRUSTRATED';
        }

        // Override: neutral but consecutive wrong answers.
        if ($emotion === 'NEUTRAL'
            && isset($behavioral['consecutive_wrong_answers'])
            && $behavioral['consecutive_wrong_answers'] >= 3) {
            $emotion = 'FRUSTRATED';
        }

        // Override: boredom from very short messages over many exchanges.
        if (isset($behavioral['exchange_count'])
            && $behavioral['exchange_count'] >= 2) {
            // This would need message length tracking; handled at caller level.
        }

        return $emotion;
    }
}
