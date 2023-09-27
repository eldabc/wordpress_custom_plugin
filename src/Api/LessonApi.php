<?php

namespace App\Api;

use Error;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Response;
use LearnDash_Settings_Section;
use WP_Query;

class Lesson extends WP_REST_Controller
{
    protected static $instance;

    protected $post_type = 'sfwd-lessons';


    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'course-api/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'lessons';


    /**
     * singleton instance.
     *
     * @since 0.1.0
     */
    public static function instance()
    {
        if (!isset(self::$instance)) {
            $class          = __CLASS__;
            self::$instance = new $class;
        }

        return self::$instance;
    }

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    public function __construct()
    {
        add_action('rest_api_init', array($this, 'register_routes'), 10);
    }

    /**
     * Register the component routes.
     *
     * @since 0.1.0
     */
    public function register_routes()
    {

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_items'),
                    'permission_callback' => array($this, 'get_items_permissions_check'),
                    'args'                => $this->get_collection_params(),
                )
            )
        );

        register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
    }


    /**
     * Retrieve Lessons.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response
     * @since          0.1.0
     *
     * @api            {GET} /wp-json/buddyboss-app/learndash/v1/lessons Get LearnDash Lessons
     * @apiName        GetLDLessons
     * @apiGroup       LD Lessons
     * @apiDescription Retrieve Lessons
     * @apiVersion     1.0.0
     * @apiPermission  LoggedInUser
     * @apiParam {Number} [page] Current page of the collection.
     * @apiParam {Number} [per_page=10] Maximum number of items to be returned in result set.
     * @apiParam {String} [search] Limit results to those matching a string.
     * @apiParam {Array} [exclude] Ensure result set excludes specific IDs.
     * @apiParam {Array} [include] Ensure result set includes specific IDs.
     * @apiParam {String} [after]  Limit results to those published after a given ISO8601 compliant date.
     * @apiParam {String} [before] Limit results to those published before a given ISO8601 compliant date.
     * @apiParam {Array} [author] Limit results to those assigned to specific authors.
     * @apiParam {Array} [author_exclude] Ensure results to excludes those assigned to specific authors.
     * @apiParam {String=asc,desc} [order=desc] Sort result set by given order.
     * @apiParam {String=date,id,title,menu_order} [orderby=date] Sort result set by given field.
     * @apiParam {Array} [parent] Limit results to those assigned to specific parent.
     */
    public function get_items($request)
    {
        $user_id = get_current_user_id();

        $registered = $this->get_collection_params();

        /**
         * Filter the the request.
         *
         * @param WP_REST_Request $request The request sent to the API.
         *
         * @since 0.1.0
         */
        $request = apply_filters('bbapp_ld_get_lessons_request', $request);

        /**
         * This array defines mappings between public API query parameters whose
         * values are accepted as-passed, and their internal WP_Query parameter
         * name equivalents (some are the same). Only values which are also
         * present in $registered will be set.
         */
        $parameter_mappings = array(
            'author'         => 'author__in',
            'author_exclude' => 'author__not_in',
            'exclude'        => 'post__not_in',
            'include'        => 'post__in',
            'offset'         => 'offset',
            'order'          => 'order',
            'orderby'        => 'orderby',
            'page'           => 'paged',
            'parent'         => 'post_parent__in',
            'parent_exclude' => 'post_parent__not_in',
            'search'         => 's',
            'slug'           => 'post_name__in',
            'status'         => 'post_status',
            'per_page'       => 'posts_per_page',
        );

        /**
         * For each known parameter which is both registered and present in the request,
         * set the parameter's value on the query $args.
         */
        foreach ($parameter_mappings as $api_param => $wp_param) {
            if (isset($registered[$api_param], $request[$api_param])) {
                $args[$wp_param] = $request[$api_param];
            } else if (isset($registered[$api_param]['default'])) {
                $args[$wp_param] = $registered[$api_param]['default'];
            }
        }

        // Check for & assign any parameters which require special handling or setting.
        $args['date_query'] = array();

        // Set before into date query. Date query must be specified as an array of an array.
        if (isset($registered['before'], $request['before'])) {
            $args['date_query'][0]['before'] = $request['before'];
        }

        // Set after into date query. Date query must be specified as an array of an array.
        if (isset($registered['after'], $request['after'])) {
            $args['date_query'][0]['after'] = $request['after'];
        }

        $args = $this->prepare_items_query($args, $request);

        
        if (isset($request['author'])) {
            $args['author'] =  $request['author'];
        }
        //wp_send_json($args);


        /**
         * Filter the query arguments for the request.
         *
         * @param array           $args    Key value array of query var to query value.
         * @param WP_REST_Request $request The request sent to the API.
         *
         * @since 0.1.0
         */
        $args = apply_filters('bbapp_ld_get_lessons_args', $args, $request);

        $args['post_type'] = $this->post_type;

        /**
         * Taxonomy Filter query
         */
        $taxonomies = wp_list_filter(get_object_taxonomies($this->post_type, 'objects'), array('show_in_rest' => true));
        foreach ($taxonomies as $taxonomy) {
            $base = !empty($taxonomy->rest_base) ? $taxonomy->rest_base : $taxonomy->name;

            if (!empty($request[$base])) {
                $args['tax_query'][] = array(
                    'taxonomy'         => $taxonomy->name,
                    'field'            => 'term_id',
                    'terms'            => $request[$base],
                    'include_children' => false,
                );
            }
        }


        $posts_query            = new WP_Query();
        $leesons['posts']       = $posts_query->query($args);
        $leesons['total_posts'] = $posts_query->found_posts;

        /**
         * Fires list of Lesson is fetched via Query.
         *
         * @param array            $leesons Fetched lessons.
         * @param WP_REST_Response $args    Query arguments.
         * @param WP_REST_Request  $request The request sent to the API.
         *
         * @since 0.1.0
         */
        $leesons = apply_filters('bbapp_ld_get_lessons', $leesons, $args, $request);

        $retval = array();
        foreach ($leesons['posts'] as $couese) {
            if (!$this->check_read_permission($couese)) {
                continue;
            }
            $retval[] = $this->prepare_response_for_collection(
                $this->prepare_item_for_response($couese, $request)
            );
        }

        $response = rest_ensure_response($retval);
        $response = bbapp_learners_response_add_total_headers($response, $leesons['total_posts'], $args['posts_per_page']);


        /**
         * Fires after a list of lessons response is prepared via the REST API.
         *
         * @param WP_REST_Response $response The response data.
         * @param WP_REST_Request  $request  The request sent to the API.
         *
         * @since 0.1.0
         */
        do_action('bbapp_ld_lessons_items_response', $response, $request);

        return $response;
    }

    /**
     * Prepare a single post output for response.
     *
     * @param WP_Post         $post    Post object.
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response $data
     */
    public function prepare_item_for_response($post, $request)
    {
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        $context = !empty($request['context']) ? $request['context'] : 'view';
        $schema  = $this->get_public_item_schema();

        $post->has_content_access = $this->get_has_content_access($post);


        // Base fields for every post.
        
        $data = array(
            'id'           => $post->ID,
            'title'        => array(
                'raw'      => $post->post_title,
                'rendered' => get_the_title($post->ID),
            ),
            'content'      => array(
                'raw'      => ($post->has_content_access) ? bbapp_learners_fix_relative_urls_protocol($post->post_content) : '',
                'rendered' => ($post->has_content_access) ? bbapp_learners_fix_relative_urls_protocol(apply_filters('the_content', $post->post_content)) : '',
            ),
            'date'         => mysql_to_rfc3339($post->post_date),
            'date_gmt'     => mysql_to_rfc3339($post->post_date_gmt),
            'modified'     => mysql_to_rfc3339($post->post_modified),
            'modified_gmt' => mysql_to_rfc3339($post->post_modified_gmt),
            'link'         => get_permalink($post->ID),
            'slug'         => $post->post_name,
            'author'       => (int) $post->post_author,
            'excerpt'      => array(
                'raw'      => bbapp_learners_fix_relative_urls_protocol($post->post_excerpt),
                'rendered' => bbapp_learners_fix_relative_urls_protocol(apply_filters('the_excerpt', $post->post_excerpt)),
            ),
            'menu_order'   => (int) $post->menu_order,
            'course' => get_post_meta($post->ID, 'course_id', true)
        );

        $data = $this->add_additional_fields_to_object($data, $request);
        $data = $this->filter_response_by_context($data, $context);

        // Wrap the data in a response object.
        $response = rest_ensure_response($data);


        return apply_filters('bbapp_ld_rest_prepare_lesson', $response, $post, $request);
    }


    /**
     * @param $post
     *
     * @return bool
     */
    public function get_has_content_access($post)
    {
        return bbapp_lms_lesson_access_from($post)
            && bbapp_lms_is_content_access($post, 'prerequities_completed')
            && bbapp_lms_is_content_access($post, 'points_access')
            && bbapp_lms_is_content_access($post, 'previous_lesson_completed')
            && sfwd_lms_has_access($post->ID);
    }

    /**
     * @param array $prepared_args
     * @param null  $request
     *
     * @return array
     */
    protected function prepare_items_query($prepared_args = array(), $request = null)
    {
        $query_args = array();

        foreach ($prepared_args as $key => $value) {
            /**
             * Filters the query_vars used in get_items() for the constructed query.
             *
             * The dynamic portion of the hook name, `$key`, refers to the query_var key.
             *
             * @param string $value The query_var value.
             *
             * @since 4.7.0
             *
             */
            $query_args[$key] = apply_filters("rest_query_var-{$key}", $value); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
        }

        $query_args['ignore_sticky_posts'] = true;

        // Map to proper WP_Query orderby param.
        if (isset($query_args['orderby']) && isset($request['orderby'])) {
            $orderby_mappings = array(
                'id'            => 'ID',
                'include'       => 'post__in',
                'slug'          => 'post_name',
                'include_slugs' => 'post_name__in',
            );

            if (isset($orderby_mappings[$request['orderby']])) {
                $query_args['orderby'] = $orderby_mappings[$request['orderby']];
            }
        }

        return $query_args;
    }

    /**
     * Check if a given request has access to lesson items.
     *
     * @param WP_REST_Request $request Full data about the request.
     *
     * @return bool|WP_Error
     * @since 0.1.0
     */
    public function get_items_permissions_check($request)
    {

        $retval = true;

        /**
         * Filter the lesson `get_items` permissions check.
         *
         * @param bool|WP_Error   $retval  Returned value.
         * @param WP_REST_Request $request The request sent to the API.
         *
         * @since 0.1.0
         */
        return apply_filters('bbapp_ld_lessons_permissions_check', $retval, $request);
    }

    /**
     * Check if we can read a post.
     *
     * Correctly handles posts with the inherit status.
     *
     * @param object $post Post object.
     *
     * @return boolean Can we read it?
     */
    public function check_read_permission($post)
    {
        return true;
    }

    /**
     * Get the query params for collections.
     *
     * @since 2.4-beta-1
     *
     *
     * @return array
     */
    public function get_collection_params()
    {
        return array(
            'page'                   => array(
                'description'        => __('Current page of the collection.'),
                'type'               => 'integer',
                'default'            => 1,
                'sanitize_callback'  => 'absint',
                'validate_callback'  => 'rest_validate_request_arg',
                'minimum'            => 1,
            ),
            'per_page'               => array(
                'description'        => __('Maximum number of items to be returned in result set.'),
                'type'               => 'integer',
                'default'            => 10,
                'minimum'            => 1,
                'maximum'            => 100,
                'sanitize_callback'  => 'absint',
                'validate_callback'  => 'rest_validate_request_arg',
            ),
            'search'                 => array(
                'description'        => __('Limit results to those matching a string.'),
                'type'               => 'string',
                'sanitize_callback'  => 'sanitize_text_field',
                'validate_callback'  => 'rest_validate_request_arg',
            ),
        );
    }

    /**
	 * Retrieve Lesson.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response
	 * @since          0.1.0
	 *
	 * @api            {GET} /wp-json/buddyboss-app/learndash/v1/lessons/:id Get LearnDash Lesson
	 * @apiName        GetLDLesson
	 * @apiGroup       LD Lessons
	 * @apiDescription Retrieve single Lesson
	 * @apiVersion     1.0.0
	 * @apiPermission  LoggedInUser
	 * @apiParam {Number} id A unique numeric ID for the Lesson.
	 */
	public function get_item( $request ) {
		$lesson_id = is_numeric( $request ) ? $request : (int) $request['id'];
		$lesson    = get_post( $lesson_id );

		if ( empty( $lesson ) || $this->post_type !== $lesson->post_type ) {
			return LessonsError::instance()->invalid_lesson_id();
		}

		/**
		 * Fire after Lesson is fetched via Query.
		 *
		 * @param array           $lesson    Fetched lesson.
		 * @param WP_REST_Request $lesson_id lesson id.
		 *
		 * @since 0.1.0
		 */
		$lesson = apply_filters( 'bbapp_ld_get_lesson', $lesson, $lesson_id );

		$retval = $this->prepare_response_for_collection(
			$this->prepare_item_for_response( $lesson, $request )
		);

		$response = rest_ensure_response( $retval );

		/**
		 * Fires after an lesson respose is prepared via the REST API.
		 *
		 * @param WP_REST_Response $response The response data.
		 * @param WP_REST_Request  $request  The request sent to the API.
		 *
		 * @since 0.1.0
		 */
		do_action( 'bbapp_ld_lesson_item_response', $response, $request );

		return $response;
	}
}
