<?php
/**
 * Plugin Name: Team Course Progress Tracker
 * Description: Track and display course progress data for teams using Sensei LMS, WooCommerce, and Teams for WooCommerce
 * Version: 1.0.0
 * Author: Your Name
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * WC requires at least: 5.0
 * WC tested up to: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Team_Course_Progress_Tracker {

    /**
     * Constructor
     */
    public function __construct() {
        // Check if required plugins are active
        add_action('admin_init', array($this, 'check_requirements'));
        
        // Hook into WordPress
        add_action('init', array($this, 'init'));
        
        // Register shortcode for team progress display
        add_shortcode('team_progress', array($this, 'team_progress_shortcode'));
        
        // Register team manager page
        add_action('woocommerce_account_team-progress_endpoint', array($this, 'team_progress_endpoint_content'));
        
        // Add endpoint to WooCommerce My Account
        add_action('init', array($this, 'add_endpoints'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_items'));
        add_filter('query_vars', array($this, 'add_query_vars'), 0);
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handler for fetching team progress data
        add_action('wp_ajax_get_team_progress_data', array($this, 'get_team_progress_data_ajax'));
        add_action('wp_ajax_nopriv_get_team_progress_data', array($this, 'get_team_progress_data_ajax'));
    }

    /**
     * Check if required plugins are active and show warning if not
     */
    public function check_requirements() {
        if (!is_admin()) return;

        $missing_plugins = array();

        if (!class_exists('WooCommerce')) {
            $missing_plugins[] = 'WooCommerce';
        }

        if (!class_exists('WC_Memberships')) {
            $missing_plugins[] = 'WooCommerce Memberships';
        }

        if (!class_exists('WC_Memberships_For_Teams')) {
            $missing_plugins[] = 'Teams for WooCommerce Memberships';
        }

        if (!class_exists('Sensei_Main')) {
            $missing_plugins[] = 'Sensei LMS';
        }

        if (!empty($missing_plugins)) {
            // Add admin notice instead of deactivating
            add_action('admin_notices', function() use ($missing_plugins) {
                $message = sprintf(
                    __('Warning: Team Course Progress Tracker works best with the following plugins: %s. Some features may not work correctly without them.', 'team-course-progress-tracker'),
                    implode(', ', $missing_plugins)
                );
                echo '<div class="notice notice-warning is-dismissible"><p>' . $message . '</p></div>';
            });
        }
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('team-course-progress-tracker', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Register endpoints for My Account page
     */
    public function add_endpoints() {
        add_rewrite_endpoint('team-progress', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }

    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'team-progress';
        return $vars;
    }

    /**
     * Add menu item to WooCommerce My Account menu
     */
    public function add_menu_items($items) {
        // Only show to team managers and owners
        if ($this->is_team_manager_or_owner()) {
            $new_items = array();
            
            // Add item after the "Teams" menu item if it exists
            foreach ($items as $key => $value) {
                $new_items[$key] = $value;
                
                if ($key === 'teams') {
                    $new_items['team-progress'] = __('Team Progress', 'team-course-progress-tracker');
                }
            }
            
            // If "Teams" menu item doesn't exist, just add to the end
            if (!isset($new_items['team-progress'])) {
                $new_items['team-progress'] = __('Team Progress', 'team-course-progress-tracker');
            }
            
            return $new_items;
        }
        
        return $items;
    }

    /**
     * Check if current user is a team manager or owner
     */
    public function is_team_manager_or_owner() {
        // If Teams for WooCommerce Memberships is not active, return false
        if (!function_exists('wc_memberships_for_teams_get_teams')) {
            return false;
        }

        $current_user_id = get_current_user_id();
        if (!$current_user_id) {
            return false;
        }

        // Get teams where user is an owner or manager
        $teams = wc_memberships_for_teams_get_teams(
            $current_user_id,
            array(
                'role' => array('owner', 'manager'),
                'status' => 'active',
            )
        );
        
        // If user has any teams as owner or manager, return true
        return !empty($teams);
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        // Only enqueue on the team progress page
        if (is_account_page() && is_wc_endpoint_url('team-progress')) {
            wp_enqueue_style('team-progress-styles', plugin_dir_url(__FILE__) . 'assets/css/team-progress.css', array(), '1.0.0');
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.7.0', true);
            wp_enqueue_script('team-progress-script', plugin_dir_url(__FILE__) . 'assets/js/team-progress.js', array('jquery', 'chart-js'), '1.0.0', true);
            
            wp_localize_script('team-progress-script', 'team_progress_data', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('team_progress_nonce'),
            ));
        }
    }

    /**
     * Team progress endpoint content
     */
    public function team_progress_endpoint_content() {
        // Check if user is a team manager or owner
        if (!$this->is_team_manager_or_owner()) {
            echo '<p>' . __('You must be a team owner or manager to view team progress.', 'team-course-progress-tracker') . '</p>';
            return;
        }
        
        // Get all teams the user manages
        $current_user_id = get_current_user_id();
        $teams = $this->get_managed_teams($current_user_id);
        
        if (empty($teams)) {
            echo '<p>' . __('You are not managing any teams.', 'team-course-progress-tracker') . '</p>';
            return;
        }

        // Display the team progress dashboard
        $this->render_team_progress_dashboard($teams);
    }

    /**
     * Get all teams managed by a user
     */
    public function get_managed_teams($user_id) {
        // Get teams where user is an owner or manager
        if (function_exists('wc_memberships_for_teams_get_teams')) {
            return wc_memberships_for_teams_get_teams(
                $user_id,
                array(
                    'role' => array('owner', 'manager'),
                    'status' => 'active',
                )
            );
        }
        
        return array();
    }

    /**
     * Render team progress dashboard
     */
    public function render_team_progress_dashboard($teams) {
        ?>
        <div class="team-progress-dashboard">
            <h2><?php _e('Team Course Progress', 'team-course-progress-tracker'); ?></h2>
            
            <?php if (count($teams) > 1): ?>
                <div class="team-selector">
                    <label for="team-select"><?php _e('Select Team:', 'team-course-progress-tracker'); ?></label>
                    <select id="team-select">
                        <?php foreach ($teams as $team): ?>
                            <option value="<?php echo esc_attr($team->get_id()); ?>"><?php echo esc_html($team->get_name()); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <div class="team-progress-tabs">
                <ul class="tab-navigation">
                    <li class="active" data-tab="overview"><?php _e('Overview', 'team-course-progress-tracker'); ?></li>
                    <li data-tab="members"><?php _e('Member Progress', 'team-course-progress-tracker'); ?></li>
                    <li data-tab="courses"><?php _e('Course Details', 'team-course-progress-tracker'); ?></li>
                </ul>
                
                <div class="tab-content">
                    <div class="tab-pane active" id="overview">
                        <div class="overview-stats">
                            <div class="stat-card total-courses">
                                <h3><?php _e('Total Courses', 'team-course-progress-tracker'); ?></h3>
                                <div class="stat-value" id="total-courses-value">-</div>
                            </div>
                            <div class="stat-card avg-completion">
                                <h3><?php _e('Average Completion', 'team-course-progress-tracker'); ?></h3>
                                <div class="stat-value" id="avg-completion-value">-</div>
                            </div>
                            <div class="stat-card team-members">
                                <h3><?php _e('Team Members', 'team-course-progress-tracker'); ?></h3>
                                <div class="stat-value" id="team-members-value">-</div>
                            </div>
                        </div>
                        
                        <div class="overview-charts">
                            <div class="chart-container">
                                <h3><?php _e('Overall Team Progress', 'team-course-progress-tracker'); ?></h3>
                                <canvas id="team-progress-chart"></canvas>
                            </div>
                            <div class="chart-container">
                                <h3><?php _e('Course Completion Rates', 'team-course-progress-tracker'); ?></h3>
                                <canvas id="course-completion-chart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="tab-pane" id="members">
                        <div class="member-search">
                            <input type="text" id="member-search" placeholder="<?php esc_attr_e('Search members...', 'team-course-progress-tracker'); ?>">
                        </div>
                        <div class="member-list-container">
                            <table class="member-progress-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Member', 'team-course-progress-tracker'); ?></th>
                                        <th><?php _e('Courses Started', 'team-course-progress-tracker'); ?></th>
                                        <th><?php _e('Courses Completed', 'team-course-progress-tracker'); ?></th>
                                        <th><?php _e('Overall Progress', 'team-course-progress-tracker'); ?></th>
                                        <th><?php _e('Last Activity', 'team-course-progress-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="member-progress-tbody">
                                    <tr>
                                        <td colspan="5"><?php _e('Loading member data...', 'team-course-progress-tracker'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="tab-pane" id="courses">
                        <div class="course-search">
                            <input type="text" id="course-search" placeholder="<?php esc_attr_e('Search courses...', 'team-course-progress-tracker'); ?>">
                        </div>
                        <div class="course-list-container">
                            <table class="course-progress-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Course', 'team-course-progress-tracker'); ?></th>
                                        <th><?php _e('Enrolled Members', 'team-course-progress-tracker'); ?></th>
                                        <th><?php _e('Completion Rate', 'team-course-progress-tracker'); ?></th>
                                        <th><?php _e('Actions', 'team-course-progress-tracker'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="course-progress-tbody">
                                    <tr>
                                        <td colspan="4"><?php _e('Loading course data...', 'team-course-progress-tracker'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="course-details-modal" class="modal">
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <h2 id="modal-course-title"></h2>
                    <div class="course-member-progress">
                        <table class="course-member-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Member', 'team-course-progress-tracker'); ?></th>
                                    <th><?php _e('Progress', 'team-course-progress-tracker'); ?></th>
                                    <th><?php _e('Lessons Completed', 'team-course-progress-tracker'); ?></th>
                                    <th><?php _e('Quizzes Passed', 'team-course-progress-tracker'); ?></th>
                                    <th><?php _e('Start Date', 'team-course-progress-tracker'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="modal-member-progress">
                                <!-- Filled via JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Team progress shortcode
     */
    public function team_progress_shortcode($atts) {
        $atts = shortcode_atts(array(
            'team_id' => 0, // Optional team ID to show specific team progress
        ), $atts, 'team_progress');
        
        // Buffer output
        ob_start();
        
        $current_user_id = get_current_user_id();
        
        // If team_id is specified, get that specific team
        if (!empty($atts['team_id'])) {
            $team_id = (int) $atts['team_id'];
            
            // Get the specific team
            $team = wc_memberships_for_teams_get_team($team_id);
            
            if (!$team) {
                echo '<p>' . __('Team not found.', 'team-course-progress-tracker') . '</p>';
                return ob_get_clean();
            }
            
            // Check if user is a manager/owner of this specific team
            $user_teams = wc_memberships_for_teams_get_teams(
                $current_user_id,
                array(
                    'role' => array('owner', 'manager'),
                    'status' => 'active',
                    'include' => array($team_id),
                )
            );
            
            if (empty($user_teams)) {
                echo '<p>' . __('You do not have permission to view this team\'s progress.', 'team-course-progress-tracker') . '</p>';
                return ob_get_clean();
            }
            
            $teams = array($team);
        } else {
            // Get all teams the user manages
            $teams = $this->get_managed_teams($current_user_id);
            
            if (empty($teams)) {
                echo '<p>' . __('You are not managing any teams.', 'team-course-progress-tracker') . '</p>';
                return ob_get_clean();
            }
        }
        
        // Render the team progress dashboard
        $this->render_team_progress_dashboard($teams);
        
        return ob_get_clean();
    }

    /**
     * AJAX handler for fetching team progress data
     */
    public function get_team_progress_data_ajax() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'team_progress_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce.'));
            exit;
        }
        
        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'You must be logged in.'));
            exit;
        }
        
        // Get team ID from request
        $team_id = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
        
        if (!$team_id) {
            wp_send_json_error(array('message' => 'No team ID provided.'));
            exit;
        }
        
        // Check if user is a manager or owner of this specific team
        $current_user_id = get_current_user_id();
        $user_teams = wc_memberships_for_teams_get_teams(
            $current_user_id,
            array(
                'role' => array('owner', 'manager'),
                'status' => 'active',
                'include' => array($team_id),
            )
        );
        
        if (empty($user_teams)) {
            wp_send_json_error(array('message' => 'You do not have permission to view this team\'s progress.'));
            exit;
        }
        
        // Get team data
        $team = wc_memberships_for_teams_get_team($team_id);
        
        if (!$team) {
            wp_send_json_error(array('message' => 'Team not found.'));
            exit;
        }
        
        // Collect team progress data
        $progress_data = $this->get_team_progress_data($team);
        
        wp_send_json_success($progress_data);
        exit;
    }

    /**
     * Get team progress data
     */
    public function get_team_progress_data($team) {
        $team_id = $team->get_id();
        $team_members = $team->get_members();
        $member_count = count($team_members);
        
        // Get all courses
        $courses = array();
        $course_args = array(
            'post_type' => 'course',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        );
        $course_query = new WP_Query($course_args);
        
        if ($course_query->have_posts()) {
            while ($course_query->have_posts()) {
                $course_query->the_post();
                $course_id = get_the_ID();
                
                $courses[$course_id] = array(
                    'id' => $course_id,
                    'title' => get_the_title(),
                    'permalink' => get_permalink(),
                    'enrolled_members' => 0,
                    'completed_members' => 0,
                    'total_progress' => 0,
                    'members_progress' => array(),
                );
            }
        }
        wp_reset_postdata();
        
        // Initialize member data
        $member_data = array();
        foreach ($team_members as $member) {
            $user_id = $member->ID;
            $user_data = get_userdata($user_id);
            
            $member_data[$user_id] = array(
                'id' => $user_id,
                'name' => $user_data->display_name,
                'email' => $user_data->user_email,
                'avatar' => get_avatar_url($user_id),
                'courses_started' => 0,
                'courses_completed' => 0,
                'overall_progress' => 0,
                'last_activity' => $this->get_last_activity_date($user_id),
                'courses' => array(),
            );
        }
        
        // Calculate progress for each member and course
        foreach ($team_members as $member) {
            $user_id = $member->ID;
            $total_progress = 0;
            $courses_count = 0;
            
            foreach ($courses as $course_id => $course_data) {
                // Check if user is taking the course
                if (Sensei_Utils::user_started_course($course_id, $user_id)) {
                    $courses[$course_id]['enrolled_members']++;
                    $member_data[$user_id]['courses_started']++;
                    
                    // Get course progress percentage
                    $progress_percentage = $this->get_user_course_progress($user_id, $course_id);
                    $total_progress += $progress_percentage;
                    $courses_count++;
                    
                    // Check if user completed the course
                    $course_completed = Sensei_Utils::user_completed_course($course_id, $user_id);
                    if ($course_completed) {
                        $courses[$course_id]['completed_members']++;
                        $member_data[$user_id]['courses_completed']++;
                    }
                    
                    // Add member progress data to course
                    $courses[$course_id]['total_progress'] += $progress_percentage;
                    $courses[$course_id]['members_progress'][$user_id] = array(
                        'progress' => $progress_percentage,
                        'completed' => $course_completed,
                        'lessons_completed' => $this->get_completed_lessons_count($user_id, $course_id),
                        'quizzes_passed' => $this->get_passed_quizzes_count($user_id, $course_id),
                        'start_date' => $this->get_course_start_date($user_id, $course_id),
                    );
                    
                    // Add course progress data to member
                    $member_data[$user_id]['courses'][$course_id] = array(
                        'progress' => $progress_percentage,
                        'completed' => $course_completed,
                    );
                }
            }
            
            // Calculate overall progress for the member
            if ($courses_count > 0) {
                $member_data[$user_id]['overall_progress'] = round($total_progress / $courses_count, 2);
            }
        }
        
        // Calculate average completion percentage for each course
        foreach ($courses as $course_id => &$course_data) {
            if ($course_data['enrolled_members'] > 0) {
                $course_data['completion_rate'] = round(($course_data['completed_members'] / $course_data['enrolled_members']) * 100, 2);
                $course_data['avg_progress'] = round($course_data['total_progress'] / $course_data['enrolled_members'], 2);
            } else {
                $course_data['completion_rate'] = 0;
                $course_data['avg_progress'] = 0;
            }
        }
        
        // Calculate team-wide statistics
        $courses_with_enrollments = array_filter($courses, function($course) {
            return $course['enrolled_members'] > 0;
        });
        
        $total_courses = count($courses_with_enrollments);
        $avg_completion = 0;
        
        if ($total_courses > 0) {
            $total_completion = array_sum(array_map(function($course) {
                return $course['avg_progress'];
            }, $courses_with_enrollments));
            
            $avg_completion = round($total_completion / $total_courses, 2);
        }
        
        return array(
            'team' => array(
                'id' => $team_id,
                'name' => $team->get_name(),
                'member_count' => $member_count,
                'total_courses' => $total_courses,
                'avg_completion' => $avg_completion,
            ),
            'courses' => array_values($courses),
            'members' => array_values($member_data),
        );
    }

    /**
     * Get user course progress percentage
     */
    private function get_user_course_progress($user_id, $course_id) {
        $lesson_ids = Sensei()->course->course_lessons($course_id, 'publish', 'ids');
        $total_lessons = count($lesson_ids);
        
        if ($total_lessons === 0) {
            return 0;
        }
        
        $completed_lessons = 0;
        
        foreach ($lesson_ids as $lesson_id) {
            if (Sensei_Utils::user_completed_lesson($lesson_id, $user_id)) {
                $completed_lessons++;
            }
        }
        
        return round(($completed_lessons / $total_lessons) * 100, 2);
    }

    /**
     * Get completed lessons count
     */
    private function get_completed_lessons_count($user_id, $course_id) {
        $lesson_ids = Sensei()->course->course_lessons($course_id, 'publish', 'ids');
        $completed_lessons = 0;
        
        foreach ($lesson_ids as $lesson_id) {
            if (Sensei_Utils::user_completed_lesson($lesson_id, $user_id)) {
                $completed_lessons++;
            }
        }
        
        return $completed_lessons;
    }

    /**
     * Get passed quizzes count
     */
    private function get_passed_quizzes_count($user_id, $course_id) {
        $lesson_ids = Sensei()->course->course_lessons($course_id, 'publish', 'ids');
        $passed_quizzes = 0;
        
        foreach ($lesson_ids as $lesson_id) {
            $quiz_id = Sensei()->lesson->lesson_quizzes($lesson_id);
            
            if ($quiz_id && Sensei_Utils::user_passed_quiz($quiz_id, $user_id)) {
                $passed_quizzes++;
            }
        }
        
        return $passed_quizzes;
    }

    /**
     * Get course start date
     */
    private function get_course_start_date($user_id, $course_id) {
        $start_date = get_user_meta($user_id, '_sensei_course_start_' . $course_id, true);
        
        if (!$start_date) {
            return '';
        }
        
        return date_i18n(get_option('date_format'), $start_date);
    }

    /**
     * Get user's last activity date
     */
    private function get_last_activity_date($user_id) {
        $last_activity_date = get_user_meta($user_id, '_sensei_last_activity', true);
        
        if (!$last_activity_date) {
            return __('No activity', 'team-course-progress-tracker');
        }
        
        return date_i18n(get_option('date_format'), $last_activity_date);
    }
}

// Initialize the plugin
new Team_Course_Progress_Tracker();
