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
 * Student profiler — builds learner profiles from Moodle data.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads Moodle data to build comprehensive student profiles.
 */
class student_profiler {

    /**
     * Get knowledge state for a student in a course.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Moodle course ID.
     * @return array Knowledge state with concepts, mastery, weakest, strongest.
     */
    public function get_knowledge_state($userid, $courseid) {
        global $DB;

        $concepts = [];

        // Get quizzes in this course with student grades.
        $sql = "SELECT q.id, q.name,
                       COALESCE(qg.grade, 0) AS grade,
                       q.grade AS maxgrade
                  FROM {quiz} q
             LEFT JOIN {quiz_grades} qg ON qg.quiz = q.id AND qg.userid = :userid
                 WHERE q.course = :courseid
              ORDER BY q.id";

        $quizzes = $DB->get_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        foreach ($quizzes as $quiz) {
            $mastery = 0;
            if ($quiz->maxgrade > 0) {
                $mastery = round(($quiz->grade / $quiz->maxgrade) * 100);
            }

            $status = 'NOT_SEEN';
            if ($quiz->grade > 0) {
                if ($mastery > 75) {
                    $status = 'STRONG';
                } else if ($mastery > 50) {
                    $status = 'DEVELOPING';
                } else if ($mastery > 25) {
                    $status = 'WEAK';
                }
            }

            $concepts[] = [
                'name' => $quiz->name,
                'mastery' => $mastery,
                'status' => $status,
            ];
        }

        // Find weakest and strongest.
        $weakest = [];
        $strongest = '';
        $weakscores = [];
        $strongscore = -1;

        foreach ($concepts as $c) {
            if ($c['status'] !== 'NOT_SEEN') {
                if ($c['mastery'] < 50) {
                    $weakscores[$c['name']] = $c['mastery'];
                }
                if ($c['mastery'] > $strongscore) {
                    $strongscore = $c['mastery'];
                    $strongest = $c['name'];
                }
            }
        }

        asort($weakscores);
        $weakest = array_slice(array_keys($weakscores), 0, 2);

        return [
            'concepts' => $concepts,
            'weakest' => $weakest,
            'strongest' => $strongest,
        ];
    }

    /**
     * Get learning style using Felder-Silverman model.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Moodle course ID.
     * @return array Learning style profile.
     */
    public function get_learning_style($userid, $courseid) {
        global $DB;

        $now = time();
        $monthago = $now - (30 * 24 * 3600);

        // Count video vs text resource access (Visual vs Verbal).
        $videohits = $DB->count_records_select(
            'logstore_standard_log',
            "userid = :uid AND courseid = :cid AND timecreated > :since
             AND component = 'mod_resource' AND target = 'course_module'
             AND other LIKE '%video%'",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $monthago]
        );

        $texthits = $DB->count_records_select(
            'logstore_standard_log',
            "userid = :uid AND courseid = :cid AND timecreated > :since
             AND component IN ('mod_resource', 'mod_page') AND action = 'viewed'",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $monthago]
        );

        // Forum activity (Active vs Reflective).
        $forumposts = $DB->count_records_select(
            'logstore_standard_log',
            "userid = :uid AND courseid = :cid AND timecreated > :since
             AND component = 'mod_forum' AND action = 'created'",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $monthago]
        );

        $forumviews = $DB->count_records_select(
            'logstore_standard_log',
            "userid = :uid AND courseid = :cid AND timecreated > :since
             AND component = 'mod_forum' AND action = 'viewed'",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $monthago]
        );

        // Sequential vs Global — check if student follows module order.
        $totalviews = $DB->count_records_select(
            'logstore_standard_log',
            "userid = :uid AND courseid = :cid AND timecreated > :since
             AND action = 'viewed' AND target = 'course_module'",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $monthago]
        );

        // Calculate styles.
        $inputstyle = ($videohits > $texthits) ? 'Visual' : 'Verbal';
        $processingstyle = ($forumposts > 2) ? 'Active' : 'Reflective';
        $understandingstyle = 'Sequential'; // Default.
        $perceptionstyle = 'Sensing'; // Default.

        // Sensing vs Intuitive: quiz-heavy users tend to be Sensing.
        $quizattempts = $DB->count_records_select(
            'logstore_standard_log',
            "userid = :uid AND courseid = :cid AND timecreated > :since
             AND component = 'mod_quiz' AND action = 'attempted'",
            ['uid' => $userid, 'cid' => $courseid, 'since' => $monthago]
        );

        if ($quizattempts > 3) {
            $perceptionstyle = 'Sensing';
        } else if ($forumviews > 5) {
            $perceptionstyle = 'Intuitive';
        }

        // Confidence based on data volume.
        $totalactions = $videohits + $texthits + $forumposts + $forumviews + $quizattempts;
        $confidence = min(1.0, $totalactions / 50.0);

        $profile = [
            'processing_style' => $processingstyle,
            'perception_style' => $perceptionstyle,
            'input_style' => $inputstyle,
            'understanding_style' => $understandingstyle,
            'style_confidence' => round($confidence, 2),
        ];

        // Save to database.
        $existing = $DB->get_record('local_edubot_student_profiles', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]);

        $record = new \stdClass();
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->processing_style = $profile['processing_style'];
        $record->perception_style = $profile['perception_style'];
        $record->input_style = $profile['input_style'];
        $record->understanding_style = $profile['understanding_style'];
        $record->style_confidence = $profile['style_confidence'];
        $record->updated_at = time();

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_edubot_student_profiles', $record);
        } else {
            $DB->insert_record('local_edubot_student_profiles', $record);
        }

        return $profile;
    }

    /**
     * Get behavioral signals for a student.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Moodle course ID.
     * @param int $conversationid Current conversation ID.
     * @return array Behavioral signals.
     */
    public function get_behavioral_signals($userid, $courseid, $conversationid = 0) {
        global $DB;

        $today = strtotime('today midnight');
        $now = time();

        // Failed attempts today.
        $failedtoday = $DB->count_records_select(
            'quiz_attempts',
            "userid = :uid AND state = 'finished' AND sumgrades = 0
             AND timefinish > :today",
            ['uid' => $userid, 'today' => $today]
        );

        // Time on current topic (minutes on course activities today).
        $firstaction = $DB->get_field_sql(
            "SELECT MIN(timecreated)
               FROM {logstore_standard_log}
              WHERE userid = :uid AND courseid = :cid AND timecreated > :today",
            ['uid' => $userid, 'cid' => $courseid, 'today' => $today]
        );
        $timeontopic = $firstaction ? round(($now - $firstaction) / 60) : 0;

        // Help requests this session (messages sent today in EduBot).
        $helprequests = 0;
        if ($conversationid > 0) {
            $helprequests = $DB->count_records_select(
                'local_edubot_messages',
                "conversation_id = :convid AND role = 'user' AND created_at > :today",
                ['convid' => $conversationid, 'today' => $today]
            );
        }

        // Consecutive wrong quiz answers.
        $recentattempts = $DB->get_records_sql(
            "SELECT id, sumgrades FROM {quiz_attempts}
              WHERE userid = :uid AND state = 'finished'
           ORDER BY timefinish DESC LIMIT 10",
            ['uid' => $userid]
        );
        $consecutivewrong = 0;
        foreach ($recentattempts as $attempt) {
            if ($attempt->sumgrades == 0) {
                $consecutivewrong++;
            } else {
                break;
            }
        }

        // Topic completion percentage.
        $completion = $this->get_topic_completion($userid, $courseid);

        // Recent correct answers (last 5).
        $last5 = $DB->get_records_sql(
            "SELECT id, sumgrades FROM {quiz_attempts}
              WHERE userid = :uid AND state = 'finished'
           ORDER BY timefinish DESC LIMIT 5",
            ['uid' => $userid]
        );
        $recentcorrect = 0;
        foreach ($last5 as $attempt) {
            if ($attempt->sumgrades > 0) {
                $recentcorrect++;
            }
        }

        // Exchange count in current conversation.
        $exchangecount = 0;
        if ($conversationid > 0) {
            $exchangecount = $DB->count_records(
                'local_edubot_messages',
                ['conversation_id' => $conversationid]
            );
        }

        return [
            'failed_attempts_today' => (int) $failedtoday,
            'time_on_current_topic' => (int) $timeontopic,
            'help_requests_session' => (int) $helprequests,
            'consecutive_wrong_answers' => (int) $consecutivewrong,
            'topic_completion' => (int) $completion,
            'recent_correct_answers' => (int) $recentcorrect,
            'exchange_count' => (int) $exchangecount,
        ];
    }

    /**
     * Get course completion percentage.
     */
    private function get_topic_completion($userid, $courseid) {
        global $DB;

        $totalactivities = $DB->count_records_select(
            'course_modules',
            "course = :cid AND visible = 1 AND completion > 0",
            ['cid' => $courseid]
        );

        if ($totalactivities == 0) {
            return 0;
        }

        $completed = $DB->count_records_select(
            'course_modules_completion',
            "userid = :uid AND coursemoduleid IN (
                SELECT id FROM {course_modules} WHERE course = :cid AND visible = 1
            ) AND completionstate > 0",
            ['uid' => $userid, 'cid' => $courseid]
        );

        return round(($completed / $totalactivities) * 100);
    }

    /**
     * Get full context for a student — combines all profile data.
     *
     * @param int $userid Moodle user ID.
     * @param int $courseid Moodle course ID.
     * @param int $conversationid Current conversation ID.
     * @return array Complete student profile.
     */
    public function get_full_context($userid, $courseid, $conversationid = 0) {
        global $DB, $USER;

        // Get user and course info.
        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');

        // Get conversation topic.
        $topic = '';
        if ($conversationid > 0) {
            $conv = $DB->get_record('local_edubot_conversations', ['id' => $conversationid]);
            if ($conv) {
                $topic = $conv->topic ?: '';
            }
        }

        $context = [
            'user_name' => $user ? fullname($user) : '',
            'course_name' => $course ? $course->fullname : '',
            'current_topic' => $topic,
            'next_topic' => '',
            'knowledge_state' => $this->get_knowledge_state($userid, $courseid),
            'learning_style' => $this->get_learning_style($userid, $courseid),
            'behavioral_signals' => $this->get_behavioral_signals($userid, $courseid, $conversationid),
        ];

        return $context;
    }
}
