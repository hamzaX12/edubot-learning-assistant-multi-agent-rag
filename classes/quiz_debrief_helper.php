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
 * Quiz debrief helper for extracting quiz attempt data.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

class quiz_debrief_helper {

    /**
     * Get extracted data for a given attempt.
     */
    public static function extract_attempt_data(int $attemptid): ?array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
        if (!$attemptobj) {
            return null;
        }

        $quiz = $attemptobj->get_quiz();
        $course = $attemptobj->get_course();

        $data = [
            'quiz_name' => $quiz->name,
            'course_name' => $course->fullname,
            'score' => $attemptobj->get_sum_marks(),
            'max_score' => $quiz->sumgrades,
            'percentage' => $quiz->sumgrades > 0 ? ($attemptobj->get_sum_marks() / $quiz->sumgrades) * 100 : 0,
            'questions' => [],
        ];

        // Iterate through all slots (questions).
        $slots = $attemptobj->get_slots();
        foreach ($slots as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            if (!$qa) {
                continue;
            }

            $q = $qa->get_question();
            if (!$q) {
                continue;
            }

            // Only support certain types.
            $type = $q->qtype->name();
            if (!in_array($type, ['multichoice', 'truefalse', 'shortanswer', 'match'])) {
                continue;
            }

            // Extract question text.
            $qtext = strip_tags($qa->get_question(false)->questiontext);
            $qtext = preg_replace('/\s+/', ' ', $qtext);
            $qtext = trim($qtext);

            // Extract correct answer.
            $correctanswer = self::get_correct_answer($q, $qa);

            // Extract student answer.
            $studentanswer = self::get_student_answer($q, $qa);

            $fraction = $qa->get_fraction(); // 1.0 is full marks.
            $is_correct = ($fraction > 0.99);

            // Determine concept (use category name).
            $cat = $DB->get_record('question_categories', ['id' => $q->category]);
            $concept = $cat ? $cat->name : 'General Concepts';

            $data['questions'][] = [
                'slot' => $slot,
                'question_text' => $qtext,
                'student_answer' => $studentanswer,
                'correct_answer' => $correctanswer,
                'is_correct' => $is_correct,
                'marks' => $qa->get_mark(),
                'max_marks' => $qa->get_max_mark(),
                'concept' => $concept,
                'type' => $type
            ];
        }

        return $data;
    }

    private static function get_correct_answer($q, $qa) {
        return $qa->get_right_answer_summary();
    }

    private static function get_student_answer($q, $qa) {
        $summary = $qa->get_response_summary();
        if (empty($summary)) {
            return 'No answer';
        }
        return $summary;
    }
}
