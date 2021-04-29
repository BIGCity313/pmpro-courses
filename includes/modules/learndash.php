<?php
class PMPro_Courses_LearnDash extends PMPro_Courses_Module {
	public $slug = 'learndash';
	
	/**
	 * Initial setup for the LearnDash module.
	 * @since 1.0
	 */
	public function init() {		
		add_filter( 'pmpro_courses_modules', array( 'PMPro_Courses_LearnDash', 'add_module' ), 10, 1 );
	}
	
	/**
     * Initial setup for the LearnDash module when active.
     * @since 1.0
     */
    public function init_active() {
        add_action('admin_menu', array( 'PMPro_Courses_LearnDash', 'admin_menu' ), 20);
		add_filter( 'pmpro_has_membership_access_filter', array( 'PMPro_Courses_LearnDash', 'pmpro_has_membership_access_filter' ), 10, 4 );
		add_action( 'template_redirect', array( 'PMPro_Courses_LearnDash', 'template_redirect' ) );
        add_filter( 'pmpro_membership_content_filter', array( 'PMPro_Courses_LearnDash', 'pmpro_membership_content_filter' ), 10, 2 );		
    }
	
	/**
	 * Add LearnDash to the modules list.
	 */
	public static function add_module( $modules ){

		$modules[] = array(
			'name' => __('LearnDash', 'pmpro-courses'),
			'slug' => 'learndash',
			'description' => __( 'LearnDash LMS', 'pmpro-courses' ),
		);
		
		return $modules;
	}
	
	/**
	 * Add Require Membership box to LearnDash courses.
	 */
	public static function admin_menu() {
		add_meta_box( 'pmpro_page_meta', __( 'Require Membership', 'pmpro-courses' ), 'pmpro_page_meta', 'sfwd-courses', 'side');
	}

	/**
	 * Check if a user has access to a LD course, lesson, etc.
	 * For courses, the default PMPro check works.
	 * For other LD CPTs, we first find the course_id.
	 * For public courses, access to lessons/etc is
	 * the same as access for the associated course.
	 * For private courses (with assignedments),
	 * access is true to let LD handle it.
	 */
	public static function has_access_to_post( $post_id = null, $user_id = null ) {
		global $post;
		
		// Use post global or queried object if no $post_id was passed in.
		// Copied from PMPro includes/content.php.
		if( ! $post_id && ! empty( $post ) && ! empty( $post->ID ) ) {
			$post_id = $post->ID;
		} elseif( ! $post_id && ! empty( $queried_object ) && ! empty( $queried_object->ID ) ) {
			$post_id = $queried_object->ID;
		}
		
		// No post, return true.
		if( ! $post_id ) {
			return true;
		}
		
		$ld_non_course_cpts = array( 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question', 'sfwd-certificates', 'groups', 'sfwd-assignment' );				
		
		// Check if this is a course or other non-LD CPT.
		$mypost = get_post( $post_id );		
		if ( ! in_array( $mypost->post_type, $ld_non_course_cpts ) ) {
			// Let PMPro handle these CPTs.
			return pmpro_has_membership_access( $post_id, $user_id );
		} else {
			// Let admins in.
			if ( current_user_can( 'manage_options' ) ) {
				return true;
			}
			
			// Check course.
			$course_id = get_post_meta( $post_id, 'course_id', true );
			$price_type = learndash_get_setting( $course_id, 'course_price_type' );
						
			if ( ! empty( $course_id ) && $price_type == 'open' ) {				
				// Access same as course.
				return pmpro_has_membership_access( $course_id, $user_id );
			} elseif ( ! empty( $course_id ) ) {
				// Let LD handle it through enrollment.
				return true;
			} else {
				// A LearnDash CPT with no course. Let PMPro handle it.
				return pmpro_has_membership_access( $post_id, $user_id ); 
			}
		}
	}
	
	/**
	 * Filter PMPro access so check on lessons and
	 * other LD post types checks the associated course.
	 */
	public static function pmpro_has_membership_access_filter( $hasaccess, $mypost, $myuser, $post_membership_levels ) {
		// Don't need to check if already restricted.
		if ( !  $hasaccess ) {
			return $hasaccess;
		}
				
		return PMPro_Courses_LearnDash::has_access_to_post( $mypost->ID, $myuser->ID );
	}
	
	/**
	 * If a course requires membership, redirect its lessons
	 * to the main course page.
	 */
	public static function template_redirect() {		
		global $post, $pmpro_pages;

		if( ! empty( $post ) && is_singular() ) {		
			// Only check if a LearnDash CPT.
			$ld_cpts = array( 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question', 'sfwd-certificates', 'groups', 'sfwd-assignment' );
			if ( ! in_array( $post->post_type, $ld_cpts ) ) {
				return;
			}
			
			// Check access for this course or lesson.
			$access = PMPro_Courses_LearnDash::has_access_to_post( $post->ID );

			// They have access. Let em in.
			if ( $access ) {
				return;
			}
			
			// Make sure we don't redirect away from the levels page if they have odd settings.
			if ( intval( $pmpro_pages['levels'] ) == $post->ID ) {
				return;
			}
			
			// No access.
			if ( $post->post_type == 'sfwd-courses' ) {
				// Don't redirect courses unless a url is passed in filter.
				$redirect_to = apply_filters( 'pmpro_courses_course_redirect_to', null );				
			} else {
				// Send lessons and other content to the parent course.
				$course_id = get_post_meta( $post->ID, 'course_id', true );
				if ( ! empty( $course_id ) ) {
					$redirect_to = get_permalink( $course_id );
				} else {
					$redirect_to = null;
				}
				$redirect_to = apply_filters( 'pmpro_courses_lesson_redirect_to', $redirect_to );
			}
			
			if ( $redirect_to ) {
				wp_redirect( $redirect_to );
				exit;
			}	
		}
	}
	
	/**
	 * Override PMPro's the_content filter.
	 * We want to show course content even if it requires membership.
	 * Still showing the non-member text at the bottom.
	 */
	public static function pmpro_membership_content_filter( $filtered_content, $original_content ) {		
		if ( is_singular( 'sfwd-courses' ) ) {
			// Show non-member text if needed.
			ob_start();
			// Get hasaccess ourselves so we get level ids and names.
			$hasaccess = pmpro_has_membership_access(NULL, NULL, true);
			if( is_array( $hasaccess ) ) {
				//returned an array to give us the membership level values
				$post_membership_levels_ids = $hasaccess[1];
				$post_membership_levels_names = $hasaccess[2];
				$hasaccess = $hasaccess[0];
				if ( ! $hasaccess ) {
					echo pmpro_get_no_access_message( '', $post_membership_levels_ids, $post_membership_levels_names );
				}
			}
			$after_the_content = ob_get_contents();
			ob_end_clean();			
			return $original_content . $after_the_content;		
		} else {
			return $filtered_content;	// Probably false.
		}
	}
}